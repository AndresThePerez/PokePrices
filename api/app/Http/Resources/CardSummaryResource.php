<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit as GET /v1/cards lists it: nine catalog fields, then the card's headline variant and its price.
 *
 * PokéSearch's highlights, matched branches, facets, query DSL and timing never reach a client, and neither
 * does any hit field beyond these nine. rarity is null when PokéSearch omits it. variant names the card's first
 * variant in card order that has a close, and price is that close as the watchlist and portfolio carry it (the
 * card page's price block without the listing); both are null when no variant of the card has a close.
 */
final class CardSummaryResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $hit  a hit from PokeSearchClient::search(), its fields already checked
     * @param  array<string, mixed>|null  $headline  a LatestPrices::headlines() row, null without a priced variant
     */
    public function __construct(array $hit, private readonly ?array $headline = null)
    {
        parent::__construct($hit);
    }

    /**
     * @return array{id: string, name: string, number: string, rarity: string|null, supertype: string, set_id: string, set_name: string, release_date: string, image_small: string, variant: array{variant_key: string, finish: string, edition: string, treatment: string}|null, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: string, name: string, number: string, rarity?: string, supertype: string, set_id: string, set_name: string, release_date: string, image_small: string} $hit */
        $hit = $this->resource;
        $headline = $this->headline;

        return [
            'id' => $hit['id'],
            'name' => $hit['name'],
            'number' => $hit['number'],
            'rarity' => $hit['rarity'] ?? null,
            'supertype' => $hit['supertype'],
            'set_id' => $hit['set_id'],
            'set_name' => $hit['set_name'],
            'release_date' => $hit['release_date'],
            'image_small' => $hit['image_small'],
            /** @var array{variant_key: string, finish: string, edition: string, treatment: string}|null */
            'variant' => $headline === null ? null : [
                'variant_key' => self::text($headline['variant_key']),
                'finish' => self::text($headline['finish']),
                'edition' => self::text($headline['edition']),
                'treatment' => self::text($headline['treatment']),
            ],
            /** @var array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null */
            'price' => $headline === null ? null : PriceBlock::fromRow($headline),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
