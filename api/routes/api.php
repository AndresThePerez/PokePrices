<?php

declare(strict_types=1);

use App\Http\Controllers\CardController;
use App\Http\Controllers\CardPricesController;
use App\Http\Controllers\CardSearchController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MoversController;
use App\Http\Controllers\PriceFreshnessController;
use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ThrottleBeforeSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Readiness is never throttled: probes must always get an answer.
Route::get('/ready', ReadinessController::class)->name('ready');

// Price freshness (P2-i1 T10, D8): computed from ingest_runs on every request, so no ETag and never cached.
Route::get('/prices/freshness', PriceFreshnessController::class)
    ->middleware('throttle:public-read')
    ->name('prices.freshness');

// Daily price reads (P2-i1 T11, T12): Postgres only, never PokéSearch (R177(8)), so outside its group. Their bodies
// change at most once per ingested day: conditional-get:history.
Route::get('/cards/{id}/prices', CardPricesController::class)
    ->middleware(['throttle:public-read', 'conditional-get:history'])
    ->name('cards.prices');

Route::get('/movers', MoversController::class)
    ->middleware(['throttle:public-read', 'conditional-get:history'])
    ->name('movers');

// The account group (plan P4-i1 D6): account-guest counts anonymous traffic before any session starts (review
// F-P4-07; ThrottleBeforeSession runs first by middleware priority), then Sanctum makes first-party requests
// stateful, auth:sanctum requires the session, the account tier counts per user, and NoStore keeps every answer out
// of caches (review F-P4-09).
Route::middleware([
    ThrottleBeforeSession::class.':account-guest',
    EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'throttle:account',
    NoStore::class,
])->group(function (): void {
    Route::get('/me', MeController::class)->name('me.show');
});

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
