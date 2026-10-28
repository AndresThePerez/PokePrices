<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CardId;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\Problems\CardNotFound;
use App\Domain\Mapping\Models\CardVariant;
use App\Http\CachePolicy;
use App\Http\Resources\CardResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

#[Group('Cards', 'Pokémon cards from the PokéSearch catalog.')]
final class CardController
{
    /** The card goes through the catalog cache: cached answers, and the degraded last-good copy. */
    public function __construct(private readonly CatalogRepository $catalog) {}

    /**
     * Read one card, hydrated with its PokéPrices variants.
     *
     * The card comes from the PokéSearch catalog; variants lists the PokéPrices variants of the card with their current TCGplayer mappings, and catalog names the PokéSearch dataset that answered. Each variant carries price: its newest daily close from TCGplayer market prices via TCGCSV, one close per day, with source, feed and as_of on each variant (as_of is the snapshot marker of the close's day), the previous close of the same series epoch within 14 days and the change since it; price is null when the collected snapshots hold no close for the variant. An id outside the PokéSearch card id pattern, or one PokéSearch does not have, answers 404 card-not-found; catalog_missing is true when PokéPrices holds variant rows for an id PokéSearch no longer has (the rows are flagged, never deleted). While PokéSearch is unavailable the last good copy answers with meta.degraded true and meta.stale_since, or 503 when there is none.
     *
     * @operationId getCard
     */
    public function __invoke(Request $request, string $id): CardResource
    {
        if (! CardId::isValid($id)) {
            throw new CardNotFound;
        }

        $result = $this->catalog->card($id);
        if ($result === null) {
            throw new CardNotFound($this->flagMissing($id));
        }

        // conditional-get reads this, never the body, to answer a degraded copy no-store.
        $request->attributes->set(CachePolicy::DEGRADED, $result->degraded);

        // Four queries whatever the number of rows: the variants, their sources, the sources' builds and the
        // variants' latest prices.
        $variants = CardVariant::query()
            ->where('card_id', $id)
            ->orderBy('variant_key')
            ->with([
                'sources' => fn (HasMany $sources): HasMany => $sources->orderBy('source'),
                'sources.mapBuild',
                'latestPrice',
            ])
            ->get();

        return CardResource::fromResult($result, $id, $variants);
    }

    /**
     * Flags every variant row of a card PokéSearch does not have as catalog_missing: the rows
     * stay, with their mappings, until the catalog check sees the card again. One aggregate SELECT counts the
     * card's rows and the ones whose flag or seed ref would change; only those are written, so a repeated miss under
     * the same seed sends that one SELECT and no UPDATE, and a new seed restamps once. catalog_checked_at is
     * therefore the last time a row's flag or seed ref changed (or VerifyCatalog wrote it), not the last miss.
     * Returns whether any row of the card exists.
     */
    private function flagMissing(string $id): bool
    {
        $seedRef = $this->catalog->seedRef()->ref;

        $counts = CardVariant::query()
            ->where('card_id', $id)
            ->selectRaw(
                "count(*) AS total, count(*) FILTER (WHERE catalog_status <> 'catalog_missing' OR catalog_seed_ref IS DISTINCT FROM ?) AS changing",
                [$seedRef],
            )
            ->toBase()
            ->first();
        $total = (int) ($counts->total ?? 0);
        $changing = (int) ($counts->changing ?? 0);

        if ($changing > 0) {
            CardVariant::query()
                ->where('card_id', $id)
                ->where(fn (Builder $rows): Builder => $rows
                    ->where('catalog_status', '<>', 'catalog_missing')
                    ->orWhereRaw('catalog_seed_ref IS DISTINCT FROM ?', [$seedRef]))
                ->update([
                    'catalog_status' => 'catalog_missing',
                    'catalog_seed_ref' => $seedRef,
                    'catalog_checked_at' => Carbon::now(),
                ]);
        }

        return $total > 0;
    }
}
