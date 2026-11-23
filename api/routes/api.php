<?php

declare(strict_types=1);

use App\Http\Controllers\AlertController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CardPricesController;
use App\Http\Controllers\CardSearchController;
use App\Http\Controllers\DemoSamplesController;
use App\Http\Controllers\DemoSessionController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MoversController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PriceFreshnessController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\DemoReadOnly;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RequireAlertEligibility;
use App\Http\Middleware\ThrottleBeforeSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Readiness is never throttled: probes must always get an answer.
Route::get('/ready', ReadinessController::class)->name('ready');

// Price freshness: computed from ingest_runs on every request, so no ETag and never cached.
Route::get('/prices/freshness', PriceFreshnessController::class)
    ->middleware('throttle:public-read')
    ->name('prices.freshness');

// Daily price reads: Postgres only, never PokéSearch, so outside its group. Their bodies
// change at most once per ingested day: conditional-get:history.
Route::get('/cards/{id}/prices', CardPricesController::class)
    ->middleware(['throttle:public-read', 'conditional-get:history'])
    ->name('cards.prices');

Route::get('/movers', MoversController::class)
    ->middleware(['throttle:public-read', 'conditional-get:history'])
    ->name('movers');

// The account group: account-guest counts anonymous traffic before any session starts (ThrottleBeforeSession runs
// first by middleware priority), then Sanctum makes first-party requests stateful, auth:sanctum requires the session,
// the account tier counts per user (the demo per IP), DemoReadOnly refuses every demo write and NoStore keeps every
// answer out of caches.
Route::middleware([
    ThrottleBeforeSession::class.':account-guest',
    EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'throttle:account',
    DemoReadOnly::class,
    NoStore::class,
])->group(function (): void {
    Route::get('/me', MeController::class)->name('me.show');
    // Your data: the export is a read, so the demo may use it; deletion is a POST so the password
    // travels in the body, never in a URL, and its password checks share the password-confirm tier
    // (5 a minute per user).
    Route::get('/me/export', [MeController::class, 'export'])->name('me.export');
    Route::post('/me/deletion', [MeController::class, 'deletion'])->middleware('throttle:password-confirm')->name('me.deletion');

    // {variantKey} arrives percent-decoded (ex10-%3F:holo:-:- reaches the controller as ex10-?:holo:-:-). It matches
    // any non-empty text, an encoded slash included, so every malformed key reaches VariantKey::isValid and answers
    // 422 (PUT) or 204 (DELETE) before any query, never a 404.
    Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::put('/watchlist/{variantKey}', [WatchlistController::class, 'put'])->where('variantKey', '.+')->name('watchlist.put');
    Route::delete('/watchlist/{variantKey}', [WatchlistController::class, 'destroy'])->where('variantKey', '.+')->name('watchlist.destroy');

    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
    Route::put('/portfolio/{variantKey}', [PortfolioController::class, 'put'])->where('variantKey', '.+')->name('portfolio.put');
    Route::delete('/portfolio/{variantKey}', [PortfolioController::class, 'destroy'])->where('variantKey', '.+')->name('portfolio.destroy');

    // Webhooks. Adding one and sending a test message need a verified, non-demo account
    // (RequireAlertEligibility attached; the demo meets DemoReadOnly first). The parameter is {id}, numeric
    // and at most 18 digits: anything else is the framework's 404, and the controller looks the id up
    // only among the caller's own webhooks, so another user's id is answered as if it did not exist.
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->middleware(RequireAlertEligibility::class)->name('webhooks.store');
    Route::delete('/webhooks/{id}', [WebhookController::class, 'destroy'])->where('id', '[0-9]{1,18}')->name('webhooks.destroy');
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test'])
        ->where('id', '[0-9]{1,18}')
        ->middleware([RequireAlertEligibility::class, 'throttle:webhook-test'])
        ->name('webhooks.test');

    // Per-card alerts and where they go. Setting a threshold and choosing the channel need a
    // verified, non-demo account; clearing a threshold and reading the settings do not. {variantKey} matches any
    // non-empty text, as on the watchlist routes, so a malformed key answers 422 (PUT) or 204 (DELETE) before any query.
    Route::put('/alerts/{variantKey}', [AlertController::class, 'put'])->where('variantKey', '.+')->middleware(RequireAlertEligibility::class)->name('alerts.put');
    Route::delete('/alerts/{variantKey}', [AlertController::class, 'destroy'])->where('variantKey', '.+')->name('alerts.destroy');
    Route::get('/me/notifications', [NotificationController::class, 'show'])->name('notifications.show');
    Route::put('/me/notifications', [NotificationController::class, 'put'])->middleware(RequireAlertEligibility::class)->name('notifications.put');

    // The read-only demo's seeded alert, sample delivered message and sample signed payload: a read, for the demo only
    // (any other account gets 404). Nothing is ever sent for the demo.
    Route::get('/demo/samples', DemoSamplesController::class)->name('demo.samples');
});

// One-click entry to the read-only demo: outside the account group (a visitor is not signed in yet), stateful
// for first-party requests, counted per IP before any session starts, and never stored by a cache.
Route::post('/demo/session', DemoSessionController::class)
    ->middleware([ThrottleBeforeSession::class.':demo-session', EnsureFrontendRequestsAreStateful::class, NoStore::class])
    ->name('demo.session');

// Routes that call PokéSearch; the contract documents their 503 upstream-unavailable and 502 upstream-contract.
Route::group(['pokesearch' => true], function (): void {
    Route::get('/cards', CardSearchController::class)
        ->middleware(['throttle:search', 'conditional-get'])
        ->name('cards.search');

    // The {id} segment arrives percent-decoded: /v1/cards/ex10-%3F reaches the controller as ex10-?.
    Route::get('/cards/{id}', CardController::class)
        ->middleware(['throttle:public-read', 'conditional-get:card'])
        ->name('cards.show');
});
