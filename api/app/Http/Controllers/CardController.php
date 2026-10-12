<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CardId;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Mapping\Models\CardVariant;
use App\Http\Problems\CardNotFound;
use App\Http\Resources\CardResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Group('Cards', 'Pokémon cards from the PokéSearch catalog.')]
final class CardController
{
    /** The card goes through the catalog cache (T42): cached answers, and the degraded last-good copy. */
    public function __construct(private readonly CatalogRepository $catalog) {}

    /**
     * Read one card, hydrated with its PokéPrices variants.
     *
     * The card comes from the PokéSearch catalog; variants lists the PokéPrices variants of the card with their current TCGplayer mappings, and catalog names the PokéSearch dataset that answered. There is no price yet. An id outside the PokéSearch card id pattern, or one PokéSearch does not have, answers 404 card-not-found; catalog_missing is true when PokéPrices holds variant rows for an id PokéSearch no longer has (the rows are flagged, never deleted). While PokéSearch is unavailable the last good copy answers with meta.degraded true and meta.stale_since, or 503 when there is none.
     *
     * @operationId getCard
     */
    public function __invoke(string $id): CardResource
    {
        if (! CardId::isValid($id)) {
            throw new CardNotFound;
        }

        $result = $this->catalog->card($id);
        if ($result === null) {
            throw new CardNotFound($this->flagMissing($id));
        }

        // Three queries whatever the number of rows (P8): the variants, their sources, the sources' builds.
        $variants = CardVariant::query()
            ->where('card_id', $id)
            ->orderBy('variant_key')
            ->with([
                'sources' => fn (HasMany $sources): HasMany => $sources->orderBy('source'),
                'sources.mapBuild',
            ])
            ->get();

        return CardResource::fromResult($result, $id, $variants);
    }

    /**
     * Flags every variant row of a card PokéSearch does not have as catalog_missing (review-1 rec 11): the rows
     * stay, with their mappings, until the catalog check (T45) sees the card again. Returns whether any row exists.
     */
    private function flagMissing(string $id): bool
    {
        /** @var Builder<CardVariant> $rows */
        $rows = CardVariant::query()->where('card_id', $id);

        return $rows->update([
            'catalog_status' => 'catalog_missing',
            'catalog_seed_ref' => $this->catalog->seedRef()->ref,
            'catalog_checked_at' => Carbon::now(),
        ]) > 0;
    }
}
