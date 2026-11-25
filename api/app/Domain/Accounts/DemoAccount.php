<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Alerts\DemoSamples;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The read-only demo account. config/accounts.php demo says whether it may be entered, its email
 * and its optional password (DEMO_PASSWORD, never committed); the watchlist and portfolio it is seeded with are
 * WATCHLIST and PORTFOLIO here (the config holds no list of cards). seed() is idempotent:
 * one demo user (is_demo, verified, no two-factor) holding exactly those rows whose variants exist in card_variants,
 * plus the samples of App\Domain\Alerts\DemoSamples (one alert, a sample signed webhook and two delivered sample
 * messages). Nothing is sent, queued or read from the catalogue. Without DEMO_PASSWORD the demo gets a random
 * 40-character password that nothing prints, so it is entered only through POST /v1/demo/session.
 */
final class DemoAccount
{
    public const int PASSWORD_LENGTH = 40;

    /**
     * The demo watchlist: one variant each of 14 well-known cards, no card twice. Every card is in the catalogue and
     * has daily closes, except the 1st Edition holo Charizard, kept as the example of a variant with no close.
     */
    public const array WATCHLIST = [
        'base1-4:holo:1st:-',
        'base1-2:holo:unlimited:-',
        'base1-15:holo:unlimited:-',
        'base1-10:holo:unlimited:-',
        'base1-16:holo:unlimited:-',
        'base1-58:normal:unlimited:-',
        'base2-11:holo:unlimited:-',
        'base3-4:holo:unlimited:-',
        'neo1-9:holo:unlimited:-',
        'ex5-17:reverse:-:-',
        'swsh7-95:holo:-:-',
        'sv2-203:holo:-:-',
        'sv4pt5-232:holo:-:-',
        'sv8pt5-161:holo:-:-',
    ];

    /** The demo portfolio, variant key => quantity: variants of the watchlist only. */
    public const array PORTFOLIO = [
        'base1-2:holo:unlimited:-' => 1,
        'base1-58:normal:unlimited:-' => 2,
        'neo1-9:holo:unlimited:-' => 1,
        'ex5-17:reverse:-:-' => 3,
        'sv2-203:holo:-:-' => 1,
    ];

    /** @var array<string, array{name: string, set_name: string, number: string}> */
    private const array CARDS = [
        'base1-4' => ['name' => 'Charizard', 'set_name' => 'Base', 'number' => '4'],
        'base1-2' => ['name' => 'Blastoise', 'set_name' => 'Base', 'number' => '2'],
        'base1-15' => ['name' => 'Venusaur', 'set_name' => 'Base', 'number' => '15'],
        'base1-10' => ['name' => 'Mewtwo', 'set_name' => 'Base', 'number' => '10'],
        'base1-16' => ['name' => 'Zapdos', 'set_name' => 'Base', 'number' => '16'],
        'base1-58' => ['name' => 'Pikachu', 'set_name' => 'Base', 'number' => '58'],
        'base2-11' => ['name' => 'Snorlax', 'set_name' => 'Jungle', 'number' => '11'],
        'base3-4' => ['name' => 'Dragonite', 'set_name' => 'Fossil', 'number' => '4'],
        'neo1-9' => ['name' => 'Lugia', 'set_name' => 'Neo Genesis', 'number' => '9'],
        'ex5-17' => ['name' => 'Chimecho', 'set_name' => 'Hidden Legends', 'number' => '17'],
        'swsh7-95' => ['name' => 'Umbreon VMAX', 'set_name' => 'Evolving Skies', 'number' => '95'],
        'sv2-203' => ['name' => 'Magikarp', 'set_name' => 'Paldea Evolved', 'number' => '203'],
        'sv4pt5-232' => ['name' => 'Mew ex', 'set_name' => 'Paldean Fates', 'number' => '232'],
        'sv8pt5-161' => ['name' => 'Umbreon ex', 'set_name' => 'Prismatic Evolutions', 'number' => '161'],
    ];

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

    /** @return list<string> the demo's watchlist: WATCHLIST */
    public static function watchlist(): array
    {
        return self::WATCHLIST;
    }

    /** @return array<string, int> the demo's portfolio, variant key => quantity: PORTFOLIO */
    public static function portfolio(): array
    {
        return self::PORTFOLIO;
    }

    /**
     * The catalogue name, set name and number of a card on the demo watchlist, as PokéSearch gives them; null for
     * any other card. The samples are written with these, so seeding never reads the catalogue.
     *
     * @return array{name: string, set_name: string, number: string}|null
     */
    public static function card(string $cardId): ?array
    {
        return self::CARDS[$cardId] ?? null;
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

            DemoSamples::seed(DB::connection(), $demo->id, $watchlist, $line);

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
            $demo->forceFill(['email' => $email, 'password' => Hash::make(self::password()), 'is_demo' => true, 'email_verified_at' => now()]);
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
                $demo->forceFill(['password' => Hash::make($configured)]);
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
