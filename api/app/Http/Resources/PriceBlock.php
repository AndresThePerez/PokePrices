<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\PriceLatest;
use App\Domain\Pricing\PriceColumns;
use App\Domain\Pricing\PricingConfig;

/**
 * The price block of a variant, shared by the card page (CardResource) and the watchlist and
 * portfolio: its latest TCGplayer close via TCGCSV with the previous close and change, or null
 * when the collected snapshots hold no close for it. Money and change_pct are decimal strings with two decimals as
 * Postgres numeric(12,2) gives them; day and previous_day are YYYY-MM-DD and as_of (the snapshot marker of the close)
 * is RFC 3339 UTC. The card page's block also carries the lowest listing and the thin-market flag (withListing());
 * the watchlist and portfolio blocks do not.
 */
final class PriceBlock
{
    /**
     * @return array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null
     */
    public static function from(?PriceLatest $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        return [
            ...PricingConfig::provenance($latest->source),
            'cadence' => PricingConfig::CADENCE,
            'close' => $latest->close,
            'day' => $latest->day->format('Y-m-d'),
            'as_of' => $latest->as_of->utc()->format('Y-m-d\TH:i:s\Z'),
            'previous_close' => $latest->previous_close,
            'previous_day' => $latest->previous_day?->format('Y-m-d'),
            'change' => $latest->change,
            'change_pct' => $latest->change_pct,
            'series_epoch' => $latest->series_epoch,
        ];
    }

    /**
     * The card page's block: from()'s keys, then lowest_listing (the low of the observation the close came from, a
     * decimal string with two decimals, null when that observation lists none or 0.00) and thin_market (the close is
     * under PricingConfig::THIN_MARKET_RATIO times that listing). Null without a close.
     *
     * @return array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int, lowest_listing: string|null, thin_market: bool}|null
     */
    public static function withListing(?PriceLatest $latest, ?string $lowest, bool $thin): ?array
    {
        $block = self::from($latest);
        if ($block === null) {
            return null;
        }

        return [...$block, 'lowest_listing' => $lowest, 'thin_market' => $lowest !== null && $thin];
    }

    /**
     * The block of a row that LEFT JOINed price_latest with every PriceColumns::LATEST entry selected as
     * price_<column>: the row's price_latest values become a PriceLatest exactly as an eager load would hydrate it, so
     * the block is from()'s, key for key. Null when the join found no close (price_variant_key is null).
     *
     * @param  array<string, mixed>  $row
     * @return array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null
     */
    public static function fromRow(array $row): ?array
    {
        if (($row['price_variant_key'] ?? null) === null) {
            return null;
        }
        $attributes = [];
        foreach (PriceColumns::LATEST as $column) {
            $attributes[$column] = $row['price_'.$column] ?? null;
        }

        return self::from((new PriceLatest)->newFromBuilder($attributes));
    }
}
