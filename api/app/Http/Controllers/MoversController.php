<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PricingConfig;
use App\Http\CachePolicy;
use App\Http\Requests\MoversRequest;
use App\Http\Resources\MoverResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Prices', 'Daily TCGplayer market prices via TCGCSV and their freshness.')]
final class MoversController
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Read the biggest movers of the newest collected day.
     *
     * The variants whose TCGplayer market close (via TCGCSV, one close per day) moved most against their previous close, from the ranked snapshot PokéPrices builds when it ingests a day: meta.day is the newest day with movers and meta.as_of its snapshot marker. A variant counts on a day when all of this holds: it has a real close on the day and a previous close of at least meta.min_previous_close (2.00 USD) in the same series epoch within 14 days; the move is at least 5.00 % and at least 1.00 USD, the percent as stored with two decimals; at least 4 of its 5 most recent real closes in the epoch, none older than 14 days, are different values; both closes lie between 0.5 x the low listing and 1.5 x the mid listing of their own snapshot, bounds included, and a close whose listings are missing or 0.00 does not count; and the close is not a thin market, under 0.5 x the lowest listing of its snapshot. A day therefore ranks only the variants that meet all of it, often far fewer than limit, and one direction may be empty; a day with none has no rows, and the newest day that has some is served. direction up lists rises and down lists falls (default up), ranked by the absolute change in percent, then the absolute change, then variant_key; limit is 1 to 50 (default 20). The window is the previous close, which may be more than one day back when a day had no close. Each row names its card from the PokéSearch catalog (card is null when PokéSearch does not have the id); while PokéSearch is unavailable, names come from the last good copy or are null, and meta.degraded is true. With no movers yet, data is [] and meta.day and meta.as_of are null.
     *
     * @operationId getMovers
     */
    public function __invoke(MoversRequest $request, CatalogRepository $catalog): AnonymousResourceCollection
    {
        $direction = $request->direction();
        $limit = $request->limit();
        $source = PricingConfig::source();

        // Query 1 of 2: the newest movers day and the snapshot marker stored with its rows.
        ['day' => $day, 'as_of' => $asOf] = Movers::newest($this->db, $source);

        // Query 2 of 2: that day's ranked rows with each variant's finish, edition and treatment.
        $movers = $day === null ? collect() : Movers::page($source, $day, $direction, $limit);

        // The names: one batched, cached catalog read of the rows' distinct card ids; it never throws.
        /** @var list<string> $ids */
        $ids = $movers->pluck('card_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $names = $catalog->cardNames($ids);
        foreach ($movers as $mover) {
            $mover->setAttribute('card', $names['cards'][(string) $mover->card_id] ?? null);
        }
        // Compared, not copied, so the exported schema types meta.degraded as a boolean (Amendment 1).
        $degraded = $names['degraded'] === true;
        // conditional-get reads this, never the body, to answer a degraded copy no-store. It goes on the
        // request the middleware holds, request(): the form request is a copy with attributes of its own, and a
        // second Request parameter would be filled with the form request again.
        request()->attributes->set(CachePolicy::DEGRADED, $degraded);

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
