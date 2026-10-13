<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\PriceMover;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ranked mover as GET /v1/movers answers it (plan P2-i1 T12, D7): the variant with its card id and finish, edition
 * and treatment (no card name: hydration is P3's), its close, the previous close it is compared with, and the change.
 * Money and change_pct are decimal strings with two decimals as Postgres numeric(12,2) gives them.
 *
 * @property PriceMover $resource
 */
final class MoverResource extends JsonResource
{
    /**
     * @return array{rank: int, card_id: string, variant_key: string, finish: string, edition: string, treatment: string, close: string, previous_close: string, previous_day: string, change: string, change_pct: string, series_epoch: int}
     */
    public function toArray(Request $request): array
    {
        $mover = $this->resource;

        return [
            'rank' => (int) $mover->rank,
            'card_id' => (string) $mover->card_id,
            'variant_key' => (string) $mover->variant_key,
            'finish' => (string) $mover->getAttribute('finish'),
            'edition' => (string) $mover->getAttribute('edition'),
            'treatment' => (string) $mover->getAttribute('treatment'),
            'close' => (string) $mover->close,
            'previous_close' => (string) $mover->previous_close,
            'previous_day' => $mover->previous_day->format('Y-m-d'),
            'change' => (string) $mover->change,
            'change_pct' => (string) $mover->change_pct,
            'series_epoch' => (int) $mover->series_epoch,
        ];
    }
}
