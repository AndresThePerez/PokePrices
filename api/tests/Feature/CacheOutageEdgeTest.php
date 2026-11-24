<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Edge cases of a Redis outage: what the 503 says and how it may be cached, and what the alert dispatch does with
// the names it cannot read. One Redis role is pointed at an address nothing answers on, so each request waits for the
// connect timeout: the requests are few on purpose.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->catalogPrefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    config(['database.redis.default.host' => env('REDIS_HOST', 'redis-queue'), 'database.redis.cache.host' => env('REDIS_CACHE_HOST', 'redis-cache')]);
    outageEdgeForgetRedis();
    CatalogCache::flush($this->catalogPrefix);
});

function outageEdgeForgetRedis(): void
{
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    Cache::forgetDriver(['redis', 'locks']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
}

/** Points one Redis role ("default" or "cache") at an address nothing answers on. */
function outageEdgeRedisDown(string $role): void
{
    config(["database.redis.{$role}.host" => '192.0.2.10']);
    outageEdgeForgetRedis();
}

it('says nothing of the store in the 503: no address, no exception and only the problem members, no-store for a guest and never a public cache policy on a catalogue read', function (): void {
    config(['cache.limiter' => 'locks']);
    outageEdgeRedisDown('default');
    $guest = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    config(['database.redis.default.host' => env('REDIS_HOST', 'redis-queue'), 'cache.limiter' => 'array']);
    outageEdgeRedisDown('cache');
    $card = $this->getJson('/v1/cards/base1-4');
    $search = $this->getJson('/v1/cards?q=charizard');

    foreach (['/v1/me' => $guest, '/v1/cards/base1-4' => $card, '/v1/cards' => $search] as $path => $response) {
        $body = (string) $response->getContent();
        expect($response->getStatusCode())->toBe(503, $path)
            ->and($response->json('type'))->toBe(ProblemDetails::BASE.'http-503', $path)
            ->and($response->json('instance'))->toBe($path, $path)
            ->and($response->headers->get('Retry-After'))->toBe('5', $path)
            ->and(array_diff(array_keys((array) $response->json()), ['type', 'title', 'status', 'instance', 'request_id', 'detail']))->toBe([], $path)
            ->and($body)->not->toContain('192.0.2.10')
            ->and(stripos($body, 'redis'))->toBeFalse($path)
            ->and(stripos($body, 'exception'))->toBeFalse($path)
            ->and($response->headers->has('ETag'))->toBeFalse($path)
            ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public');
    }
    expect((string) $guest->headers->get('Cache-Control'))->toContain('no-store');
});

it('creates no delivery while the catalogue cache is down within the grace of the day\'s run, and after it one with a null card, never a name it could not read', function (): void {
    AlertRows::captureLogs();
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'sync', '2026-10-12 20:30:00+00');
    AlertRows::event('base1-4:holo:-:-', '2026-10-12', [
        'direction' => 'up', 'close' => '968.91', 'previous_close' => '850.00', 'previous_day' => '2026-10-11',
        'change' => '118.91', 'change_pct' => '13.99', 'as_of' => '2026-10-12 20:05:45+00',
        'previous_as_of' => '2026-10-11 20:05:45+00', 'series_epoch' => 1,
    ]);
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, 'base1-4:holo:-:-');
    AlertRows::endpoint($user);
    outageEdgeRedisDown('cache');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:59:00', 'UTC'));
    [$within, $withinLine] = AlertRows::dispatch();
    $withinRows = DB::table('deliveries')->count();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:00:01', 'UTC'));
    [$after, $afterLine] = AlertRows::dispatch();
    $body = json_decode((string) DB::table('deliveries')->value('body'), true);

    expect([$within, $after])->toBe([0, 0])
        ->and($withinLine)->toMatchArray(['deliverable_day' => '2026-10-12', 'skipped' => 'catalog degraded', 'created' => ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0]])
        ->and($withinRows)->toBe(0)
        ->and($afterLine)->toMatchArray(['skipped' => null, 'created' => ['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0]])
        ->and(DB::table('deliveries')->count())->toBe(1)
        ->and(array_keys($body['data']))->toContain('card')
        ->and($body['data']['card'])->toBeNull()
        ->and($body['data']['card_id'] ?? $body['data']['variant_key'] ?? null)->not->toBeNull();
});
