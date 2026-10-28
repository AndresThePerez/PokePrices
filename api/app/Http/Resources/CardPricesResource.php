<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\PricingConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The daily candles of a card's variants as GET /v1/cards/{id}/prices answers them: one series per
 * variant (by variant_key) with its points ascending by day, and the provenance and window in meta. Every point
 * carries its own as_of, the snapshot marker of the close it shows; meta.as_of is the newest ingested day's marker.
 * history_begins is the day of the variant's first real close in any epoch, or null; not range-bound.
 */
final class CardPricesResource extends JsonResource
{
    /**
     * @param  list<array{variant_key: string, finish: string, edition: string, treatment: string, history_begins: string|null, points: list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}>}>  $series
     */
    public function __construct(
        array $series,
        private readonly string $range,
        private readonly ?string $from,
        private readonly ?string $to,
        private readonly ?string $asOf,
    ) {
        parent::__construct($series);
    }

    /**
     * @return list<array{variant_key: string, finish: string, edition: string, treatment: string, history_begins: string|null, points: list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}>}>
     */
    public function toArray(Request $request): array
    {
        /** @var list<array{variant_key: string, finish: string, edition: string, treatment: string, history_begins: string|null, points: list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}>}> $series */
        $series = $this->resource;

        // Each series is rebuilt as a literal so the exported OpenAPI reads the item docblocks (history_begins is a
        // nullable format: date string); the values pass through unchanged.
        $out = [];
        foreach ($series as $one) {
            $out[] = [
                'variant_key' => $one['variant_key'],
                'finish' => $one['finish'],
                'edition' => $one['edition'],
                'treatment' => $one['treatment'],
                /**
                 * @var string|null
                 *
                 * @format date
                 */
                'history_begins' => $one['history_begins'],
                /** @var list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}> */
                'points' => $one['points'],
            ];
        }

        return $out;
    }

    /**
     * @return array{meta: array{source: string, feed: string, basis: string, currency: string, cadence: string, range: string, from: string|null, to: string|null, as_of: string|null}}
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                ...PricingConfig::provenance(),
                'cadence' => PricingConfig::CADENCE,
                'range' => $this->range,
                'from' => $this->from,
                'to' => $this->to,
                'as_of' => $this->asOf,
            ],
        ];
    }
}
