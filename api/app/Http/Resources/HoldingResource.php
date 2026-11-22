<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One holding as GET /v1/portfolio lists it: the watchlist item fields, the quantity held and
 * value, quantity × close computed by Postgres as numeric(20,2) text (null when the variant has no close: a missing
 * price is never valued at zero).
 */
final class HoldingResource extends JsonResource
{
    /** @param array<string, mixed> $row a Portfolio::holdings() row with card attached by the controller */
    public function __construct(array $row)
    {
        parent::__construct($row);
    }

    /**
     * @return array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string, image_small?: string}|null, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null, quantity: int, value: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;
        $fields = WatchlistItemResource::fields($row);
        $price = $fields['price'];
        $value = $row['value'] ?? null;

        return $fields + [
            'quantity' => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0,
            /** @var string|null */
            'value' => $price !== null && is_string($value) ? $value : null,
        ];
    }
}
