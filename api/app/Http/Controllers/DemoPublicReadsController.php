<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\CardNames;
use App\Domain\Accounts\DemoAccount;
use App\Domain\Accounts\Portfolio;
use App\Domain\Accounts\Watchlist;
use App\Domain\Alerts\DemoSamples;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\PricingConfig;
use App\Http\CachePolicy;
use App\Http\Problems\DemoUnavailable;
use App\Http\Resources\DemoSamplesResource;
use App\Http\Resources\HoldingResource;
use App\Http\Resources\WatchlistItemResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The read-only demo's watchlist, portfolio and alert sample as public reads for a signed-out visitor: no session,
 * no cookie, the same rows and resources the demo's own signed-in reads answer, and nothing that depends on who asks.
 * The demo is found by its is_demo flag, never by an id from the request.
 */
#[Group('Demo', 'The read-only demo account\'s sample data, public and the same for every visitor.')]
final class DemoPublicReadsController
{
    /**
     * Read the demo's watchlist.
     *
     * The demo account's watchlist in exactly the shape GET /v1/watchlist answers the signed-in demo: each variant with its card's name from the PokéSearch catalog and the card page's price block (the newest daily close from TCGplayer market prices via TCGCSV with source, feed, day and as_of; price is null when the collected snapshots hold no close for it), and the demo's alert threshold. Public: no session, no cookie, and the same answer for every caller. A degraded answer (meta.degraded, while PokéSearch is unavailable) is never cached. When the demo is switched off or has not been seeded, the answer is 404 demo-unavailable.
     *
     * @operationId getDemoWatchlist
     *
     * @throws HttpException<404> when the demo is switched off or not seeded (problem type demo-unavailable)
     */
    public function watchlist(Request $request, Watchlist $watchlist, CatalogRepository $catalog): AnonymousResourceCollection
    {
        $rows = $watchlist->items(self::demoId());
        $names = CardNames::attach($rows, $catalog);
        // conditional-get reads this, never the body, to answer a degraded copy no-store.
        $request->attributes->set(CachePolicy::DEGRADED, $names['degraded']);

        return WatchlistItemResource::collection($names['rows'])->additional(['meta' => [
            'count' => count($rows),
            'limit' => AccountLimits::watchlist(),
            'degraded' => $names['degraded'] === true,
        ]]);
    }

    /**
     * Read the demo's portfolio.
     *
     * The demo account's holdings in exactly the shape GET /v1/portfolio answers the signed-in demo: each with its quantity, card name and price block from TCGplayer market prices via TCGCSV, value (quantity × close, null without a close), and meta with total_value (an estimate at daily-close market prices, not a sale value), the change against the previous close (change, change_pct), priced, unpriced, no_previous_close, first_day and last_day, as GET /v1/portfolio describes them. Every amount is a decimal string computed in Postgres numeric. Public: no session, no cookie, and the same answer for every caller. A degraded answer is never cached. When the demo is switched off or has not been seeded, the answer is 404 demo-unavailable.
     *
     * @operationId getDemoPortfolio
     *
     * @throws HttpException<404> when the demo is switched off or not seeded (problem type demo-unavailable)
     * @throws HttpException<500> when the portfolio cannot be read (problem type server-error)
     */
    public function portfolio(Request $request, Portfolio $portfolio, CatalogRepository $catalog): AnonymousResourceCollection
    {
        $holdings = $portfolio->holdings(self::demoId());
        $rows = $holdings['rows'];
        $names = CardNames::attach($rows, $catalog);
        // conditional-get reads this, never the body, to answer a degraded copy no-store.
        $request->attributes->set(CachePolicy::DEGRADED, $names['degraded']);

        return HoldingResource::collection($names['rows'])->additional(['meta' => [
            'count' => $holdings['count'],
            'limit' => AccountLimits::portfolio(),
            /** @var string|null */
            'total_value' => $holdings['total_value'],
            'priced' => $holdings['priced'],
            'unpriced' => $holdings['unpriced'],
            /** @var string|null */
            'change' => $holdings['change'],
            /** @var string|null */
            'change_pct' => $holdings['change_pct'],
            'no_previous_close' => $holdings['no_previous_close'],
            /** @var string|null */
            'first_day' => $holdings['first_day'],
            /** @var string|null */
            'last_day' => $holdings['last_day'],
            ...PricingConfig::provenance(),
            'degraded' => $names['degraded'] === true,
        ]]);
    }

    /**
     * Read the demo's alert sample.
     *
     * The demo account's seeded alert (the watchlist card and threshold), a sample delivered alert email (subject and text) and a sample signed webhook delivery (price.moved: the exact body, the webhook-id, webhook-timestamp and webhook-signature headers and the webhook's url_hint), in exactly the shape GET /v1/demo/samples answers the signed-in demo. sample_secret is the fixed, published sample signing secret the signature verifies with; it belongs to no account and is never read from the environment. Nothing is ever sent for the demo. alert, message and webhook are null when the demo has none. Public: no session, no cookie, and the same answer for every caller. When the demo is switched off or has not been seeded, the answer is 404 demo-unavailable.
     *
     * @operationId getDemoAlerts
     *
     * @throws HttpException<404> when the demo is switched off or not seeded (problem type demo-unavailable)
     * @throws HttpException<500> when the samples cannot be read (problem type server-error)
     */
    public function alerts(ConnectionInterface $db): DemoSamplesResource
    {
        return new DemoSamplesResource(DemoSamples::read($db, self::demoId()));
    }

    /** The demo user's id, for the domain reads only: it never appears in an answer. */
    private static function demoId(): int
    {
        if (! DemoAccount::enabled()) {
            throw new DemoUnavailable;
        }

        return DemoAccount::user()?->id ?? throw new DemoUnavailable;
    }
}
