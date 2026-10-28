<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The one parse of a collected day the PokéPrices commands take (--day, --days, --reset-watermark; Q1-i5 T03): exactly
 * YYYY-MM-DD, nothing around it, and a real calendar day (checkdate, so year 0000, 2026-02-30 and an unpadded
 * 2026-2-3 are refused). Each command keeps its own error text.
 */
final class IsoDay
{
    /** The day at UTC midnight, or null when $day is not a real day in the form YYYY-MM-DD. */
    public static function parse(string $day): ?CarbonImmutable
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $day, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');
    }

    public static function isDay(string $day): bool
    {
        return self::parse($day) !== null;
    }
}
