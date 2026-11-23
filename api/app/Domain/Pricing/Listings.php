<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Models\PriceLatest;
use Illuminate\Support\Facades\DB;

/**
 * The lowest listing beside a variant's price, read from price_points (price_latest holds no listing).
 *
 * - A variant with a close: the low of the observation the close came from, the price_points row at the close's as_of.
 *   The market is thin when that low is above zero and the close is under PricingConfig::THIN_MARKET_RATIO times it;
 *   exactly that multiple is not thin.
 * - A variant without a close: the low of its newest observation, with that observation's day and marker, when it is
 *   above zero; the variant still has no price.
 *
 * A low that is null or 0.00 is no listing: lowest is null and the market is not thin. All variants are read with one
 * statement, one primary-key probe per variant and monthly partition, so a card costs one query whatever its size.
 */
final class Listings
{
    /**
     * The listing of each variant by variant_key. A variant with no observation, or whose close's observation is
     * missing, is left out.
     *
     * @param  array<string, PriceLatest|null>  $latest  each variant's price_latest row by variant_key, null without a close
     * @return array<string, array{lowest: string|null, thin: bool, day: string, as_of: string}>
     */
    public static function forVariants(array $latest): array
    {
        if ($latest === []) {
            return [];
        }
        $rows = [];
        foreach ($latest as $key => $price) {
            $rows[] = [
                'variant_key' => (string) $key,
                'close' => $price?->close,
                'as_of' => $price?->as_of->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ];
        }

        $found = DB::select(<<<'SQL'
            SELECT v.variant_key,
                   CASE WHEN o.low > 0 THEN o.low::text END AS lowest,
                   coalesce(o.low > 0 AND v.close < o.low * ?::numeric, false) AS thin,
                   to_char(o.day, 'YYYY-MM-DD') AS day,
                   to_char(o.observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM jsonb_to_recordset(?::jsonb) AS v(variant_key text, close numeric, as_of timestamptz)
            JOIN LATERAL (
                SELECT p.low, p.day, p.observed_at
                FROM price_points p
                WHERE p.variant_key = v.variant_key AND p.source = ?
                  AND p.observed_at <= coalesce(v.as_of, 'infinity'::timestamptz)
                ORDER BY p.observed_at DESC
                LIMIT 1
            ) o ON v.as_of IS NULL OR o.observed_at = v.as_of
            SQL, [PricingConfig::THIN_MARKET_RATIO, json_encode($rows, JSON_THROW_ON_ERROR), PricingConfig::source()]);

        $listings = [];
        foreach ($found as $row) {
            $listings[(string) $row->variant_key] = [
                'lowest' => is_string($row->lowest) ? $row->lowest : null,
                'thin' => $row->thin === true,
                'day' => (string) $row->day,
                'as_of' => (string) $row->as_of,
            ];
        }

        return $listings;
    }
}
