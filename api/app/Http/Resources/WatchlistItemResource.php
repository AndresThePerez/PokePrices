<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One variant on the watchlist as GET /v1/watchlist lists it: the variant, its card's name, set name
 * and number from the PokéSearch catalog (null when PokéSearch does not have the id or is unavailable without a last
 * good copy), the card page's price block from price_latest (null without a close in the collected snapshots) and,
 * last, its alert threshold in whole percent (null when no alert is set). Holdings share fields(),
 * which has no alert_pct.
 */
final class WatchlistItemResource extends JsonResource
{
    /** @param array<string, mixed> $row a Watchlist::items() row with card attached by the controller */
    public function __construct(array $row)
    {
        parent::__construct($row);
    }

    /**
     * @return array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null, alert_pct: int|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;
        $pct = $row['alert_pct'] ?? null;

        return self::fields($row) + [
            /** @var int|null */
            'alert_pct' => is_numeric($pct) ? (int) $pct : null,
        ];
    }

    /**
     * The item fields, shared with HoldingResource.
     *
     * @param  array<string, mixed>  $row
     * @return array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null}
     */
    public static function fields(array $row): array
    {
        return [
            'variant_key' => self::text($row['variant_key'] ?? null),
            'card_id' => self::text($row['card_id'] ?? null),
            'finish' => self::text($row['finish'] ?? null),
            'edition' => self::text($row['edition'] ?? null),
            'treatment' => self::text($row['treatment'] ?? null),
            /** @var array{name: string, set_name: string, number: string}|null */
            'card' => self::card($row['card'] ?? null),
            /** @var array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null */
            'price' => PriceBlock::fromRow($row),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The name the controller attached from the catalog, re-checked so the closed shape always holds.
     *
     * @return array{name: string, set_name: string, number: string}|null
     */
    private static function card(mixed $card): ?array
    {
        if (! is_array($card) || ! is_string($card['name'] ?? null) || ! is_string($card['set_name'] ?? null) || ! is_string($card['number'] ?? null)) {
            return null;
        }

        return ['name' => $card['name'], 'set_name' => $card['set_name'], 'number' => $card['number']];
    }
}
