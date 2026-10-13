<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;

/**
 * Shared setup for the account tests (plan P4-i1 T02; Stage C conventions): a runtime invite code, the first-party
 * stateful domain, a fresh in-memory rate limiter and users with a known (fake) password.
 */
final class Accounts
{
    /** A fake invite code set at run time; never a real value. */
    public const string INVITE = 'test-invite-0000-0000-0000';

    /** A fake password of 21 characters. */
    public const string PASSWORD = 'fake-password-0000-ok';

    public const string ORIGIN = 'http://pokeprices.localhost:8095';

    /** Headers a first-party browser request carries (D1): Referer and Origin from the one hostname, JSON. */
    public const array FIRST_PARTY = [
        'Referer' => self::ORIGIN.'/',
        'Origin' => self::ORIGIN,
        'Accept' => 'application/json',
    ];

    public static function setUp(): void
    {
        config([
            'accounts.invite_code' => self::INVITE,
            'sanctum.stateful' => ['pokeprices.localhost:8095'],
            'cache.limiter' => 'array',
        ]);
        app()->forgetInstance(RateLimiter::class);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    }

    /** @param array<string, mixed> $attributes */
    public static function user(array $attributes = []): User
    {
        $user = new User;
        $user->forceFill($attributes + [
            'email' => 'person@example.com',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ])->save();

        return $user->refresh();
    }

    /**
     * Drops the resolved guards, so the next request authenticates from its session, not from a user an earlier
     * request left on a guard instance (Sanctum's RequestGuard caches its user for the life of the instance).
     */
    public static function forgetGuards(): void
    {
        Auth::forgetGuards();
    }

    /** The decrypted session id the response's session cookie carries, or null when it set none. */
    public static function sessionId(TestResponse $response): ?string
    {
        $name = config('session.cookie');
        $cookie = is_string($name) ? $response->getCookie($name) : null;

        return $cookie?->getValue();
    }
}
