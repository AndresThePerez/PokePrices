<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The one place PokéPrices reads config/pricing.php's source, feed, queue and movers limit (Q1-i4 T03), and the
 * provenance every price-shaped answer carries: source and feed from config, market basis, USD. CADENCE is the
 * cadence the card prices, the price block and the price.moved payload state; the movers and portfolio meta do not.
 */
final class PricingConfig
{
    public const string CADENCE = 'daily';

    /** The movers a request gets when it names no limit. */
    public const int MOVERS_DEFAULT_LIMIT = 20;

    /** The most movers price_movers can keep per day and direction, and the export's maximum for GET /v1/movers limit. */
    public const int MOVERS_CEILING = 50;

    public static function source(): string
    {
        return (string) config('pricing.source');
    }

    public static function feed(): string
    {
        return (string) config('pricing.feed');
    }

    /** The queue every PokéPrices job runs on. */
    public static function queue(): string
    {
        return (string) config('pricing.queue');
    }

    /**
     * The movers price_movers keeps per day and direction, and so the most a request may ask for: pricing.movers.limit,
     * read as AccountLimits reads its knobs (Q1-i5 T03). A knob that is not an integer, or is below 1, gives 50; a knob
     * above 50 is clamped to 50, so the value is always in [1, 50].
     *
     * @return int<1, 50>
     */
    public static function moversLimit(): int
    {
        $value = config('pricing.movers.limit', self::MOVERS_CEILING);

        return is_int($value) && $value >= 1 ? min($value, self::MOVERS_CEILING) : self::MOVERS_CEILING;
    }

    /** The default page size of GET /v1/movers: 20, or fewer when the rebuild keeps fewer rows per day and direction. */
    public static function defaultMoversLimit(): int
    {
        return min(self::MOVERS_DEFAULT_LIMIT, self::moversLimit());
    }

    /**
     * The provenance of a price: $source names the row's own source where the row carries one (the price block and the
     * price.moved payload), else the configured source.
     *
     * @return array{source: string, feed: string, basis: 'market', currency: 'USD'}
     */
    public static function provenance(?string $source = null): array
    {
        return [
            'source' => $source ?? self::source(),
            'feed' => self::feed(),
            'basis' => 'market',
            'currency' => 'USD',
        ];
    }
}
