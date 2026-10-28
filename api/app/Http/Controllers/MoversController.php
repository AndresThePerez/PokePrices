<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PricingConfig;
use App\Http\Resources\MoverResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

#[Group('Prices', 'Daily TCGplayer market prices via TCGCSV and their freshness.')]
final class MoversController
{
    /**
     * Read the biggest movers of the newest collected day.
     *
     * The variants whose TCGplayer market close (via TCGCSV, one close per day) moved most against their previous close, from the ranked snapshot PokéPrices builds when it ingests a day: meta.day is the newest day with movers and meta.as_of its snapshot marker. A variant counts when it has a real close on that day and a previous close of at least meta.min_previous_close (2.00 USD) in the same series epoch within 14 days; direction up lists rises and down lists falls (default up), ranked by the absolute change in percent, then the absolute change, then variant_key; limit is 1 to 50 (default 20). The window is the previous close, which may be more than one day back when a day had no close. Each row names its card from the PokéSearch catalog (card is null when PokéSearch does not have the id); while PokéSearch is unavailable, names come from the last good copy or are null, and meta.degraded is true. With no movers yet, data is [] and meta.day and meta.as_of are null.
     *
     * @operationId getMovers
     */
    public function __invoke(Request $request, CatalogRepository $catalog): AnonymousResourceCollection
    {
        // limit stops at the rows price_movers keeps per day and direction (PricingConfig::moversLimit(), D9).
        $validated = $request->validate([
            'direction' => ['sometimes', 'required', 'string', Rule::in(['up', 'down'])],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PricingConfig::moversLimit()],
        ]);
        $direction = isset($validated['direction']) ? (string) $validated['direction'] : 'up';
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : PricingConfig::defaultMoversLimit();
        $source = PricingConfig::source();

        // Query 1 of 2: the newest movers day and the snapshot marker stored with its rows (review F9, R181(5), Q1-i3 T04).
        ['day' => $day, 'as_of' => $asOf] = Movers::newest(DB::connection(), $source);

        // Query 2 of 2: that day's ranked rows with each variant's finish, edition and treatment.
        $movers = $day === null ? collect() : Movers::page($source, $day, $direction, $limit);

        // The names: one batched, cached catalog read of the rows' distinct card ids; it never throws (P3-i1 T08).
        /** @var list<string> $ids */
        $ids = $movers->pluck('card_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $names = $catalog->cardNames($ids);
        foreach ($movers as $mover) {
            $mover->setAttribute('card', $names['cards'][(string) $mover->card_id] ?? null);
        }
        // Compared, not copied, so the exported schema types meta.degraded as a boolean (Amendment 1).
        $degraded = $names['degraded'] === true;

        return MoverResource::collection($movers)->additional(['meta' => [
            'day' => $day,
            'as_of' => $asOf,
            ...PricingConfig::provenance($source),
            'window' => 'previous_close',
            'min_previous_close' => (string) config('pricing.movers.min_previous_close'),
            'direction' => $direction,
            'limit' => $limit,
            'degraded' => $degraded,
        ]]);
    }
}
