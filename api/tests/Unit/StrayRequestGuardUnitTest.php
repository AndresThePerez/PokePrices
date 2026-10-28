<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Q1-i3-T06 review: Pest extends TestCase for Feature only, so a Unit file is armed by the guard in TestCase::setUp()
// only when it says uses(TestCase::class). The two Unit files that lost their own call (PokeSearchClientTest and
// PokeSearchClientEdgeTest) do; this proves a Unit file that does gets the same refusal with no call of its own.

uses(TestCase::class);

it('refuses an unmatched request in a Unit file that has no preventStrayRequests call of its own', function (): void {
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    expect(fn () => Http::timeout(1)->get('http://192.0.2.10:8081/api/v1/cards/x'))
        ->toThrow(RuntimeException::class, 'Attempted request to [http://192.0.2.10:8081/api/v1/cards/x] without a matching fake.');
});

it('refuses a request made with no fake at all in a Unit file', function (): void {
    expect(fn () => Http::timeout(1)->get('http://192.0.2.10:8081/api/v1/cards/y'))
        ->toThrow(RuntimeException::class, 'Attempted request to [http://192.0.2.10:8081/api/v1/cards/y] without a matching fake.');
});
