<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CardId;
use App\Domain\Pricing\PriceHistory;
use App\Http\Problems\CardNotFound;
use App\Http\Resources\CardPricesResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Prices', 'Daily TCGplayer market prices via TCGCSV and their freshness.')]
final class CardPricesController
{
    /**
     * Read the daily closes of a card's variants over a chart range.
     *
     * One series per PokéPrices variant of the card, ordered by variant_key, each with its daily points ascending by day: TCGplayer market prices via TCGCSV, one close per day. range is 1W, 1M, 3M, YTD or 1Y (default 1M); the window ends on to, the newest ingested day, and starts on from (to minus 6, 29, 89 or 364 days, or January 1 of to's year for YTD), so it follows the data, never the clock. Days without a row are gaps. A filled point (is_filled true) repeats the close of filled_from for at most 14 days; every point's as_of is the snapshot marker of the close it shows, and meta.as_of is the marker of the newest ingested day. series_epoch changes when the variant's TCGplayer mapping starts a new series; never compare closes across it. With nothing ingested yet, from, to and as_of are null and every series is empty. The prices come from PokéPrices alone: a valid id PokéPrices holds no variants for answers 200 with data: [], which does not mean the card is unknown (GET /v1/cards/{id} says whether a card exists). An id outside the card id pattern answers 404 card-not-found.
     *
     * @operationId getCardPrices
     */
    public function __invoke(Request $request, string $id): CardPricesResource
    {
        if (! CardId::isValid($id)) {
            throw new CardNotFound;
        }
        $validated = $request->validate([
            'range' => ['sometimes', 'required', 'string', Rule::in(array_keys(PriceHistory::RANGES))],
        ]);
        $range = isset($validated['range']) ? (string) $validated['range'] : PriceHistory::DEFAULT_RANGE;

        $history = PriceHistory::forCard($id, $range);

        return new CardPricesResource($history['series'], $range, $history['from'], $history['to'], $history['as_of']);
    }
}
