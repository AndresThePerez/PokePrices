<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The account rate tiers (plan P4-i1 D7), one named limiter each, per minute from config/accounts.php rate_limits.
 * A 429 is the existing problem too-many-requests with Retry-After.
 *
 * Keys: login by the normalised email and the IP (a non-string email keys as ''); two-factor by the pending sign-in
 * in the session (Fortify's login.id); register, account-guest and demo-session by IP; password-confirm and account
 * by user. The shared demo user is not one person, so on the account tier it gets the account-demo value keyed
 * demo:<ip> instead (plan P4-i1 T06): one visitor cannot spend the demo's budget for every other visitor.
 */
final class AccountRateLimits
{
    public const string CONFIG = 'accounts.rate_limits';

    public const array TIERS = ['login', 'two-factor', 'register', 'account-guest', 'password-confirm', 'account', 'account-demo', 'demo-session'];

    /**
     * Checks the tiers now (a malformed tier fails the boot), then names the limiters on every RateLimiter the
     * container builds, as App\Domain\Catalog\RateLimits does for the anonymous tiers.
     */
    public static function register(): void
    {
        $tiers = self::tiers(config(self::CONFIG));
        app()->afterResolving(RateLimiter::class, static function (RateLimiter $limiter) use ($tiers): void {
            self::apply($limiter, $tiers);
        });
        if (app()->resolved(RateLimiter::class)) {
            self::apply(app(RateLimiter::class), $tiers);
        }
    }

    /**
     * @return array<string, int<1, max>> requests per minute, by tier name
     *
     * @throws RuntimeException unless the config maps exactly the known tiers to integers >= 1
     */
    public static function tiers(mixed $config): array
    {
        if (! is_array($config)) {
            throw new RuntimeException('Config '.self::CONFIG.' must map the account tiers to requests per minute.');
        }
        $names = array_map('strval', array_keys($config));
        sort($names);
        $expected = self::TIERS;
        sort($expected);
        if ($names !== $expected) {
            throw new RuntimeException('Config '.self::CONFIG.' must name exactly the tiers '.implode(', ', self::TIERS).'.');
        }

        $tiers = [];
        foreach ($config as $name => $perMinute) {
            if (! is_int($perMinute) || $perMinute < 1) {
                throw new RuntimeException('Account tier '.$name.' in config '.self::CONFIG.' must be an integer >= 1.');
            }
            $tiers[(string) $name] = $perMinute;
        }

        return $tiers;
    }

    /** The login limiter key (D7): the normalised email, then the IP. */
    public static function loginKey(Request $request): string
    {
        return Str::transliterate(AccountEmail::key($request->input(AccountEmail::FIELD))).'|'.$request->ip();
    }

    /** @param array<string, int<1, max>> $tiers */
    private static function apply(RateLimiter $limiter, array $tiers): void
    {
        foreach ($tiers as $name => $perMinute) {
            $limiter->for($name, static fn (Request $request): Limit => $name === 'account' && self::isDemo($request)
                ? Limit::perMinute($tiers['account-demo'])->by(self::key('account-demo', $request))
                : Limit::perMinute($perMinute)->by(self::key($name, $request)));
        }
    }

    private static function isDemo(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_demo;
    }

    private static function key(string $tier, Request $request): string
    {
        return match ($tier) {
            'login' => self::loginKey($request),
            'two-factor' => self::pendingSignIn($request),
            'password-confirm', 'account' => self::user($request),
            'account-demo' => 'demo:'.$request->ip(),
            default => (string) $request->ip(),
        };
    }

    private static function pendingSignIn(Request $request): string
    {
        $id = $request->hasSession() ? $request->session()->get('login.id') : null;

        return 'login:'.(is_int($id) || is_string($id) ? (string) $id : '');
    }

    private static function user(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }
}
