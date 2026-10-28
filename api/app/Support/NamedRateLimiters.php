<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * The one registrar of the PokéPrices named rate limiters (Q1-i5 T03): the catalog, account and alert tiers each check
 * their own config and pass their tiers here with the Limit each tier answers.
 */
final class NamedRateLimiters
{
    /**
     * Names one limiter per tier on every RateLimiter the container builds, and on the one it already holds. Building
     * it here would open the limiter's Redis store, and with it the Redis manager, before anything needs them.
     *
     * @param  array<string, int<1, max>>  $tiers  requests per minute, by tier name
     * @param  Closure(string, int, Request): Limit  $limit  the Limit a tier answers for a request
     */
    public static function register(array $tiers, Closure $limit): void
    {
        $apply = static function (RateLimiter $limiter) use ($tiers, $limit): void {
            foreach ($tiers as $name => $perMinute) {
                $limiter->for($name, static fn (Request $request): Limit => $limit($name, $perMinute, $request));
            }
        };
        app()->afterResolving(RateLimiter::class, $apply);
        if (app()->resolved(RateLimiter::class)) {
            $apply(app(RateLimiter::class));
        }
    }

    /** A signed-in request keys as user:<id>, any other as ip:<ip>. */
    public static function userKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }
}
