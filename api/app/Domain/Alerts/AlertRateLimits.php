<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The alert rate tiers (plan P5-i1 D9, D14), per minute from config/alerts.php rate_limits, one named limiter each,
 * keyed user:<id>: webhook-test (POST /v1/webhooks/{id}/test). A 429 is the existing problem too-many-requests with
 * Retry-After. Registered like App\Domain\Accounts\AccountRateLimits, on every RateLimiter the container builds.
 */
final class AlertRateLimits
{
    public const string CONFIG = 'alerts.rate_limits';

    public const array TIERS = ['webhook-test'];

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
        if (! is_array($config) || array_map('strval', array_keys($config)) !== self::TIERS) {
            throw new RuntimeException('Config '.self::CONFIG.' must name exactly the tiers '.implode(', ', self::TIERS).'.');
        }
        $tiers = [];
        foreach ($config as $name => $perMinute) {
            if (! is_int($perMinute) || $perMinute < 1) {
                throw new RuntimeException('Alert tier '.$name.' in config '.self::CONFIG.' must be an integer >= 1.');
            }
            $tiers[(string) $name] = $perMinute;
        }

        return $tiers;
    }

    /** @param array<string, int<1, max>> $tiers */
    private static function apply(RateLimiter $limiter, array $tiers): void
    {
        foreach ($tiers as $name => $perMinute) {
            $limiter->for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)->by(self::user($request)));
        }
    }

    private static function user(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }
}
