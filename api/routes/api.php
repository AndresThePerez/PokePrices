<?php

declare(strict_types=1);

use App\Http\Controllers\CardSearchController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

// Readiness is never throttled: probes must always get an answer.
Route::get('/ready', ReadinessController::class)->name('ready');

// Routes that call PokéSearch; the contract documents their 503 upstream-unavailable and 502 upstream-contract.
Route::group(['pokesearch' => true], function (): void {
    Route::get('/cards', CardSearchController::class)
        ->middleware(['throttle:search', 'conditional-get'])
        ->name('cards.search');
});
