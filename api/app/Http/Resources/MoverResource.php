<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\PriceMover;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ranked mover as GET /v1/movers answers it: the variant with its card id, the card's name, set
 * name and number from the PokéSearch catalog or null (with image_small, the catalog's small image URL, when the
 * catalog gives one), finish, edition and treatment, its close, the
 * previous close it is compared with, and the change.
 * Money and change_pct are decimal strings with two decimals as Postgres numeric(12,2) gives them.
 *
 * @property PriceMover $resource
 */
final class MoverResource extends JsonResource
{
    /**
     * @return array{rank: int, card_id: string, card: array{name: string, set_name: string, number: string, image_small?: string}|null, variant_key: string, finish: string, edition: string, treatment: string, close: string, previous_close: string, previous_day: string, change: string, change_pct: string, series_epoch: int}
     */
    public function toArray(Request $request): array
    {
        $mover = $this->resource;

        return [
            'rank' => (int) $mover->rank,
            'card_id' => (string) $mover->card_id,
            'card' => self::card($mover->getAttribute('card')),
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

    /**
     * The name the controller attached from the catalog, re-checked so the closed shape always holds: image_small
     * only when it is a string that is not blank.
     *
     * @return array{name: string, set_name: string, number: string, image_small?: string}|null
     */
    private static function card(mixed $card): ?array
    {
        if (! is_array($card) || ! is_string($card['name'] ?? null) || ! is_string($card['set_name'] ?? null) || ! is_string($card['number'] ?? null)) {
            return null;
        }

        $named = ['name' => $card['name'], 'set_name' => $card['set_name'], 'number' => $card['number']];
        $image = $card['image_small'] ?? null;

        return is_string($image) && trim($image) !== '' ? $named + ['image_small' => $image] : $named;
    }
}
