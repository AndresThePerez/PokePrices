<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Support\Facades\Redis;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// The limit of the catalogue's last good copy (docs/OPERATIONS.md, "The last good copy does not outlive the Redis
// cache"): the copy lives on redis-cache only, so it is gone once that store is emptied, as a restart empties it.

beforeEach(function (): void {
    Accounts::setUp();
    $this->catalogPrefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

it('answers a card from its last good copy while PokéSearch is down, and with the 503 once the Redis cache was emptied', function (): void {
    $fresh = $this->getJson('/v1/cards/base1-4');

    // Past both windows of the card key, so only the last good copy (7 days) can still answer.
    $this->travel(86401)->seconds();
    AccountRows::$down = true;
    $degraded = $this->getJson('/v1/cards/base1-4');

    // What a restart of redis-cache leaves behind: nothing on the "cache" role; redis-queue keeps its keys.
    $emptied = 0;
    foreach (CatalogCache::keys('cache', $this->catalogPrefix) as $key) {
        $emptied += (int) Redis::connection('cache')->del($key);
    }
    $lost = $this->getJson('/v1/cards/base1-4');

    expect($fresh->getStatusCode())->toBe(200)
        ->and($fresh->json('meta.degraded'))->toBeFalse()
        ->and($degraded->getStatusCode())->toBe(200)
        ->and($degraded->json('data.id'))->toBe('base1-4')
        ->and($degraded->json('meta.degraded'))->toBeTrue()
        ->and($degraded->json('meta.stale_since'))->toBeString()
        ->and($emptied)->toBeGreaterThanOrEqual(1)
        ->and(CatalogCache::keys('default', $this->catalogPrefix))->not->toBe([])
        ->and($lost->getStatusCode())->toBe(503)
        ->and($lost->json('type'))->toBe(ProblemDetails::BASE.'upstream-unavailable')
        ->and((int) $lost->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
