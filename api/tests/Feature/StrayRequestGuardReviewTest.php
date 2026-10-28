<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogCache;

// Q1-i3-T06 review: the per-file preventStrayRequests() calls are gone, so the one guard in TestCase::setUp() is all
// that stands between a test and a real host. These corners are the ones the removed calls used to sit beside.

const T06R_STRAY = 'http://192.0.2.10:8081/api/v1/cards/x';

it('still refuses a request that no stub callback answers, after Http::fake() with a callback that returns null', function (): void {
    Http::fake(function (Request $request) {
        return str_starts_with($request->url(), 'https://discord.com/') ? Http::response('', 204) : null;
    });

    expect(Http::get('https://discord.com/api/webhooks/1/x')->status())->toBe(204)
        ->and(fn () => Http::timeout(1)->get(T06R_STRAY))
        ->toThrow(RuntimeException::class, 'Attempted request to ['.T06R_STRAY.'] without a matching fake.');
});

it('still refuses an unmatched url after Http::fake() with a url-keyed table', function (): void {
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    expect(fn () => Http::timeout(1)->get(T06R_STRAY))
        ->toThrow(RuntimeException::class, 'Attempted request to ['.T06R_STRAY.'] without a matching fake.');
});

it('refuses a stray request after CatalogCache::primeSeedRef() and a fresh Http::fake(), so the 7 calls kept after it are not what arms the guard', function (): void {
    CatalogCache::primeSeedRef('http://192.0.2.10:8081');
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    expect(fn () => Http::timeout(1)->get(T06R_STRAY))
        ->toThrow(RuntimeException::class, 'Attempted request to ['.T06R_STRAY.'] without a matching fake.');
});

it('leaves a test free to swap the factory and open every stray request without arming the next test', function (): void {
    Http::swap(new Factory);
    Http::allowStrayRequests();
    Http::fake(['https://discord.com/*' => Http::response('', 204)]);

    expect(Http::get('https://discord.com/api/webhooks/1/x')->status())->toBe(204);
});

it('arms the next test again after a test that swapped the factory for an unarmed one and allowed every stray request', function (): void {
    expect(fn () => Http::timeout(1)->get(T06R_STRAY))
        ->toThrow(RuntimeException::class, 'Attempted request to ['.T06R_STRAY.'] without a matching fake.');
});
