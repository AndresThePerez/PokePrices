<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Edge cases of the limit of the last good copy (docs/OPERATIONS.md, "The last good copy does not outlive the Redis
// cache"): the card names, and a card nobody read before PokéSearch went down.

beforeEach(function (): void {
    Accounts::setUp();
    $this->catalogPrefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

it('names the movers from the last good copy while PokéSearch is down, and with null once the Redis cache was emptied: degraded and no-store both times, and a card never read is the 503', function (): void {
    AccountRows::variant('base1-4:holo:unlimited:-');
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'base1-4:holo:unlimited:-', 'card_id' => 'base1-4', 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
    $fresh = $this->getJson('/v1/movers');

    // Past both windows of the name key, so only the last good copy (7 days) can still name the card.
    $this->travel(86401)->seconds();
    AccountRows::$down = true;
    $degraded = $this->getJson('/v1/movers');

    // What a restart of redis-cache leaves behind: nothing on the "cache" role.
    $emptied = 0;
    foreach (CatalogCache::keys('cache', $this->catalogPrefix) as $key) {
        $emptied += (int) Redis::connection('cache')->del($key);
    }
    $lost = $this->getJson('/v1/movers');
    $neverRead = $this->getJson('/v1/cards/base1-2');

    expect($fresh->getStatusCode())->toBe(200)
        ->and($fresh->json('data.0.card.name'))->toBe('Name of base1-4')
        ->and($fresh->json('meta.degraded'))->toBeFalse()
        ->and($degraded->getStatusCode())->toBe(200)
        ->and($degraded->json('data.0.card.name'))->toBe('Name of base1-4')
        ->and($degraded->json('meta.degraded'))->toBeTrue()
        ->and($degraded->headers->get('Cache-Control'))->toContain('no-store')
        ->and($degraded->headers->has('ETag'))->toBeFalse()
        ->and($emptied)->toBeGreaterThanOrEqual(1)
        ->and($lost->getStatusCode())->toBe(200)
        ->and($lost->json('data.0.card'))->toBeNull()
        ->and($lost->json('data.0.close'))->toBe('11.00')
        ->and($lost->json('meta.degraded'))->toBeTrue()
        ->and($lost->headers->get('Cache-Control'))->toContain('no-store')
        ->and($lost->headers->has('ETag'))->toBeFalse()
        ->and($neverRead->getStatusCode())->toBe(503)
        ->and($neverRead->json('type'))->toBe(ProblemDetails::BASE.'upstream-unavailable')
        ->and((int) $neverRead->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
