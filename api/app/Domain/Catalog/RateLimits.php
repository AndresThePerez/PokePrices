<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\NamedRateLimiters;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The PokéPrices rate tiers (plan T44, S8): one named limiter per tier in config/rate_limits.php, which ships
 * inside api/ with the app (R160).
 *
 * Each tier is ['per_minute' => n, 'by' => 'ip']; routes opt in with throttle:<tier>. A request over the limit
 * gets ThrottleRequestsException, which ProblemDetails answers as 429 too-many-requests with Retry-After.
 */
final class RateLimits
{
    public const string CONFIG = 'rate_limits';

    /**
     * Checks the tiers now (a malformed tier fails the boot), then names one limiter per tier on the
     * RateLimiter when the container builds it. Building it here would open the limiter's Redis store, and
     * with it the Redis manager, before anything needs them.
     */
    public static function register(): void
    {
        NamedRateLimiters::register(
            self::tiers(config(self::CONFIG)),
            static fn (string $tier, int $perMinute, Request $request): Limit => Limit::perMinute($perMinute)->by((string) $request->ip()),
        );
    }

    /** The file the tiers come from: a Laravel config file under api/, so the runtime image carries it. */
    public static function path(): string
    {
        return config_path(self::CONFIG.'.php');
    }

    /**
     * @return array<string, int<1, max>> requests per minute, by tier name
     *
     * @throws RuntimeException when the tiers are missing or a tier is not ['per_minute' => n >= 1, 'by' => 'ip']
     */
    public static function tiers(mixed $config): array
    {
        if (! is_array($config) || $config === [] || array_is_list($config)) {
            throw new RuntimeException('Config '.self::CONFIG.' must map tier names to tiers.');
        }

        $tiers = [];
        foreach ($config as $name => $tier) {
            $perMinute = is_array($tier) ? ($tier['per_minute'] ?? null) : null;
            if (! is_int($perMinute) || $perMinute < 1 || ! is_array($tier) || ($tier['by'] ?? null) !== 'ip' || count($tier) !== 2) {
                throw new RuntimeException('Rate tier '.$name.' in config '.self::CONFIG." must be ['per_minute' => n >= 1, 'by' => 'ip'].");
            }
            $tiers[(string) $name] = $perMinute;
        }

        return $tiers;
    }
}
