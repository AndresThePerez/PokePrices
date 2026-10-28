<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\CardNames;
use App\Domain\Accounts\Portfolio;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\PricingConfig;
use App\Http\Requests\PutQuantityRequest;
use App\Http\Resources\HoldingEntryResource;
use App\Http\Resources\HoldingResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class PortfolioController
{
    public function __construct(private readonly Portfolio $portfolio) {}

    /**
     * Read your portfolio.
     *
     * The variants the signed-in account holds, ordered by card id, then variant key, each with its quantity, its card's name from the PokéSearch catalog (as on the watchlist; meta.degraded is true while PokéSearch is unavailable) and the card page's price block from TCGplayer market prices via TCGCSV. value is quantity × close, computed by PokéPrices as a decimal string; it is null when the collected snapshots hold no close for the variant, which is then never counted as zero. meta.total_value sums the priced holdings (null when none is priced), meta.priced and meta.unpriced count them, and meta.first_day and meta.last_day are the oldest and newest close days the total rests on. The answer is never cached.
     *
     * @operationId getPortfolio
     */
    public function index(#[CurrentUser] User $user, CatalogRepository $catalog): AnonymousResourceCollection
    {
        $holdings = $this->portfolio->holdings($user->id);
        $rows = $holdings['rows'];
        $names = CardNames::attach($rows, $catalog);
        $priced = count(array_filter($rows, fn (array $row): bool => ($row['price_variant_key'] ?? null) !== null));

        return HoldingResource::collection($names['rows'])->additional(['meta' => [
            'count' => count($rows),
            'limit' => AccountLimits::portfolio(),
            /** @var string|null */
            'total_value' => $holdings['total_value'],
            'priced' => $priced,
            'unpriced' => count($rows) - $priced,
            /** @var string|null */
            'first_day' => $holdings['first_day'],
            /** @var string|null */
            'last_day' => $holdings['last_day'],
            ...PricingConfig::provenance(),
            'degraded' => $names['degraded'] === true,
        ]]);
    }

    /**
     * Set how many of a variant you hold.
     *
     * Sets the quantity (1 to 9999) held of the variant, creating the holding when needed; the quantity is set, never added to, and the answer is the same 200 either way. A key that names no PokéPrices variant, a full portfolio (meta.limit variants) or a bad quantity answers 422. The demo account is read-only (403 demo-read-only).
     *
     * @operationId putHolding
     *
     * @throws ValidationException 422 errors.variant_key for an unknown key or a full list
     */
    public function put(PutQuantityRequest $request, #[CurrentUser] User $user, string $variantKey): HoldingEntryResource
    {
        $quantity = $request->quantity();
        $this->portfolio->set($user->id, $variantKey, $quantity);

        return new HoldingEntryResource($variantKey, $quantity);
    }

    /**
     * Remove a variant from your portfolio.
     *
     * Removes the holding; 204 also when it was not there. The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteHolding
     */
    public function destroy(#[CurrentUser] User $user, string $variantKey): Response
    {
        $this->portfolio->remove($user->id, $variantKey);

        return response()->noContent();
    }
}
