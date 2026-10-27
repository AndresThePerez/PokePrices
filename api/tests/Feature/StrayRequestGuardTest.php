<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

// Q1-i2-T04 (d3 / D9): the suite refuses a real HTTP call by default, with no per-file opt-in.
it('refuses an HTTP request that no fake matches, without a per-file preventStrayRequests call', function (): void {
    expect(fn () => Http::timeout(1)->get('http://192.0.2.10:8081/api/v1/cards/x'))
        ->toThrow(RuntimeException::class, 'Attempted request to [http://192.0.2.10:8081/api/v1/cards/x] without a matching fake.');
});

it('points PokéSearch at a documentation address in every test, whatever the compose environment says', function (): void {
    expect(config('services.pokesearch.url'))->toBe('http://192.0.2.10:8081');
});
