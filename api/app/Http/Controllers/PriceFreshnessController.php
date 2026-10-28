<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pricing\Freshness;
use App\Http\CachePolicy;
use App\Http\Resources\PriceFreshnessResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Prices', 'Daily TCGplayer market prices via TCGCSV and their freshness.')]
final class PriceFreshnessController
{
    /**
     * How old the newest collected PokéPrices prices are, against the 26 hour freshness SLO.
     *
     * as_of is the snapshot marker of the newest successfully ingested TCGCSV day (TCGplayer market prices, one close per day) and day is its date; age_hours is the time from as_of to measured_at in hours with one decimal, rounded half up. within_slo is judged on the unrounded age: true while it is under slo_hours (26), so 25.96 hours is within the SLO even though it shows 26.0. measured_at is the server clock and the only clock value in the answer. With no successful ingest, as_of, day and age_hours are null and within_slo is false. last_run is the newest ingest run of any status (null when none ran). A breached SLO is a normal 200 answer: it means no newer day was ingested in time. The answer is never cached.
     *
     * @operationId getPriceFreshness
     */
    public function __invoke(Request $request): JsonResponse
    {
        return (new PriceFreshnessResource(Freshness::measure()))
            ->response($request)
            ->header('Cache-Control', CachePolicy::NO_STORE);
    }
}
