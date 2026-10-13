<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\CardNames;
use App\Domain\Accounts\VariantKey;
use App\Domain\Accounts\Watchlist;
use App\Domain\Catalog\CatalogRepository;
use App\Http\Resources\WatchlistEntryResource;
use App\Http\Resources\WatchlistItemResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class WatchlistController
{
    public function __construct(private readonly Watchlist $watchlist) {}

    /**
     * Read your watchlist.
     *
     * The variants on the signed-in account's watchlist, ordered by card id, then variant key. Each carries its card's name, set name and number from the PokéSearch catalog (card is null when PokéSearch does not have the id; while PokéSearch is unavailable, names come from the last good copy or are null, and meta.degraded is true) and the card page's price block: the variant's newest daily close from TCGplayer market prices via TCGCSV with source, feed, day and as_of, the previous close and the change (price is null when the collected snapshots hold no close for it). meta.limit is the most variants a watchlist holds. The answer is never cached.
     *
     * @operationId getWatchlist
     */
    public function index(Request $request, CatalogRepository $catalog): AnonymousResourceCollection
    {
        $rows = $this->watchlist->items(self::userId($request));
        $names = CardNames::attach($rows, $catalog);

        return WatchlistItemResource::collection($names['rows'])->additional(['meta' => [
            'count' => count($rows),
            'limit' => AccountLimits::watchlist(),
            'degraded' => $names['degraded'] === true,
        ]]);
    }

    /**
     * Add a variant to your watchlist.
     *
     * Puts the variant on the watchlist; the answer is the same 200 whether it was added now or was already there. A key that names no PokéPrices variant, or a full watchlist (meta.limit variants), answers 422 with errors.variant_key. The demo account is read-only (403 demo-read-only).
     *
     * @operationId putWatchlistItem
     *
     * @throws ValidationException 422 errors.variant_key for an unknown key or a full list
     */
    public function put(Request $request, string $variantKey): WatchlistEntryResource
    {
        // The key's shape is checked before any query (plan P4-i1 D5).
        if (! VariantKey::isValid($variantKey)) {
            throw ValidationException::withMessages(['variant_key' => [AccountLimits::UNKNOWN_VARIANT]]);
        }
        $error = $this->watchlist->add(self::userId($request), $variantKey);
        if ($error !== null) {
            throw ValidationException::withMessages(['variant_key' => [$error]]);
        }

        return new WatchlistEntryResource($variantKey);
    }

    /**
     * Remove a variant from your watchlist.
     *
     * Takes the variant off the watchlist; 204 also when it was not there. The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteWatchlistItem
     */
    public function destroy(Request $request, string $variantKey): Response
    {
        $this->watchlist->remove(self::userId($request), $variantKey);

        return response()->noContent();
    }

    /** auth:sanctum on the account group has already answered 401 to a signed-out request. */
    private static function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
