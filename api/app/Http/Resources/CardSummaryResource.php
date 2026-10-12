<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit as GET /v1/cards lists it (plan T44, review-1 rec 12): nine catalog fields, nothing else.
 *
 * PokéSearch's highlights, matched branches, facets, query DSL and timing never reach a client, and neither
 * does any hit field beyond these nine. rarity is null when PokéSearch omits it.
 */
final class CardSummaryResource extends JsonResource
{
    /** @param array<string, mixed> $hit a hit from PokeSearchClient::search(), its fields already checked */
    public function __construct(array $hit)
    {
        parent::__construct($hit);
    }

    /**
     * @return array{id: string, name: string, number: string, rarity: string|null, supertype: string, set_id: string, set_name: string, release_date: string, image_small: string}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: string, name: string, number: string, rarity?: string, supertype: string, set_id: string, set_name: string, release_date: string, image_small: string} $hit */
        $hit = $this->resource;

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
        ];
    }
}
