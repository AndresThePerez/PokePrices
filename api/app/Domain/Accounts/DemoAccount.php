<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The read-only demo account, configured in config/accounts.php demo: whether it may be
 * entered, its email, its optional password (DEMO_PASSWORD, never committed) and the watchlist and portfolio it is
 * seeded with. seed() is idempotent: one demo user (is_demo, verified, no two-factor) holding exactly the configured
 * rows whose variants exist in card_variants. Without DEMO_PASSWORD the demo gets a random 40-character password that
 * nothing prints, so it is entered only through POST /v1/demo/session.
 */
final class DemoAccount
{
    public const int PASSWORD_LENGTH = 40;

    public static function enabled(): bool
    {
        return config('accounts.demo.enabled') === true;
    }

    /** The demo user, or null before the seeder ran. */
    public static function user(): ?User
    {
        return User::query()->where('is_demo', true)->first();
    }

    public static function email(): string
    {
        $email = config('accounts.demo.email');

        return Str::lower(trim(is_string($email) && trim($email) !== '' ? $email : 'demo@example.com'));
    }

    /** The configured DEMO_PASSWORD, or null when it is unset or empty. */
    public static function configuredPassword(): ?string
    {
        $password = config('accounts.demo.password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    /** The configured password, else a fresh random one of PASSWORD_LENGTH characters. */
    public static function password(): string
    {
        return self::configuredPassword() ?? Str::random(self::PASSWORD_LENGTH);
    }

    /** @return list<string> */
    public static function watchlist(): array
    {
        $keys = config('accounts.demo.watchlist');

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** @return array<string, int> variant key => quantity */
    public static function portfolio(): array
    {
        $holdings = config('accounts.demo.portfolio');
        $portfolio = [];
        foreach (is_array($holdings) ? $holdings : [] as $key => $quantity) {
            if (is_string($key) && is_int($quantity)) {
                $portfolio[$key] = $quantity;
            }
        }

        return $portfolio;
    }

    /**
     * Creates or repairs the demo user and its rows in one transaction. $line receives one line per configured key
     * missing from card_variants and a closing summary; never a password.
     *
     * @param  Closure(string): void  $line
     *
     * @throws RuntimeException when the demo email belongs to an account that is not the demo
     */
    public static function seed(Closure $line): User
    {
        return DB::transaction(function () use ($line): User {
            $demo = self::upsertUser();
            $existing = self::existingVariants([...self::watchlist(), ...array_keys(self::portfolio())]);

            $watchlist = [];
            foreach (self::watchlist() as $key) {
                if (in_array($key, $existing, true)) {
                    $watchlist[] = $key;
                } else {
                    $line("Skipped {$key} on the demo watchlist: card_variants has no such variant.");
                }
            }
            $portfolio = [];
            foreach (self::portfolio() as $key => $quantity) {
                if (in_array($key, $existing, true)) {
                    $portfolio[$key] = $quantity;
                } else {
                    $line("Skipped {$key} on the demo portfolio: card_variants has no such variant.");
                }
            }

            WatchlistItem::query()->where('user_id', $demo->id)->whereNotIn('variant_key', $watchlist)->delete();
            foreach ($watchlist as $key) {
                DB::insert('INSERT INTO watchlist_items (user_id, variant_key) VALUES (?, ?) ON CONFLICT (user_id, variant_key) DO NOTHING', [$demo->id, $key]);
            }
            PortfolioHolding::query()->where('user_id', $demo->id)->whereNotIn('variant_key', array_keys($portfolio))->delete();
            foreach ($portfolio as $key => $quantity) {
                DB::insert(<<<'SQL'
                    INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, ?, ?)
                    ON CONFLICT (user_id, variant_key) DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = now()
                    WHERE portfolio_holdings.quantity <> EXCLUDED.quantity
                    SQL, [$demo->id, $key, $quantity]);
            }

            $line('Demo account '.$demo->email.' is ready: '.count($watchlist).' watchlist and '.count($portfolio).' portfolio rows.');

            return $demo;
        });
    }

    private static function upsertUser(): User
    {
        $email = self::email();
        $demo = self::user();
        if ($demo === null) {
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw new RuntimeException("The demo email {$email} belongs to an account that is not the demo; set DEMO_EMAIL to another address.");
            }
            $demo = new User;
            $demo->forceFill(['email' => $email, 'password' => self::password(), 'is_demo' => true, 'email_verified_at' => now()]);
        } else {
            $demo->forceFill([
                'email' => $email,
                'email_verified_at' => $demo->email_verified_at ?? now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $configured = self::configuredPassword();
            if ($configured !== null && ! Hash::check($configured, $demo->password)) {
                $demo->forceFill(['password' => $configured]);
            }
        }
        $demo->save();

        return $demo;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> the keys card_variants holds
     */
    private static function existingVariants(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        /** @var list<string> */
        return DB::table('card_variants')->whereIn('variant_key', array_values(array_unique($keys)))->pluck('variant_key')->all();
    }
}
