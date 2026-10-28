<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\Problems\UpstreamUnavailable;
use App\Domain\Catalog\SeedRef;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// The catalog repository (plan T42): PokéSearch answers cached by seed ref on the real redis-cache, a 60 s
// negative cache, last-good copies and the degraded mode. Every upstream call is faked by path and counted;
// every key lives under a prefix unique to the parallel-test token and the test (Tests\Support\CatalogCache).

const T42_BASE = 'http://192.0.2.10:8081';
const T42_REF_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const T42_REF_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const T42_CARD = '/api/v1/cards/sv1-1';

function t42Meta(?string $ref): array
{
    return [
        'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
        'seed' => $ref === null ? null : ['ref' => $ref, 'seeded_at' => '2026-10-11T20:05:45Z'],
        'request_id' => 'upstream-meta',
    ];
}

function t42Card(string $name = 'Pikachu'): array
{
    return [
        'data' => ['id' => 'sv1-1', 'name' => $name, 'supertype' => 'Pokémon', 'number' => '1',
            'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'set_series' => 'Scarlet & Violet', 'set_total' => 198,
            'release_date' => '2023-03-31', 'image_small' => 'https://images.example.com/sv1/1.png', 'image_large' => 'https://images.example.com/sv1/1_hires.png',
        ],
        'seed' => ['ref' => T42_REF_A, 'seeded_at' => '2026-10-11T20:05:45Z'],
    ];
}

function t42Search(string $name = 'Pikachu'): array
{
    return [
        'total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 24,
        'results' => [[
            'id' => 'sv1-1', 'name' => $name, 'number' => '1', 'rarity' => 'Common', 'supertype' => 'Pokémon',
            'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'release_date' => '2023-03-31',
            'image_small' => 'https://images.example.com/sv1/1.png',
        ]],
    ];
}

/** How many requests the fake saw for one path (a retried attempt counts twice). */
function t42Count(array $calls, string $path): int
{
    return count(array_keys($calls, $path, true));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T42_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->prefix = CatalogCache::isolate();

    // path => [status, body] or 'timeout'; the fake answers from this table at the time of each request.
    $this->routes = ['/api/meta' => [200, t42Meta(T42_REF_A)], T42_CARD => [200, t42Card()], '/api/search' => [200, t42Search()]];
    $this->calls = [];
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = $path;
        $route = $this->routes[$path] ?? [599, ['error' => 'unrouted']];
        if ($route === 'timeout') {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response($route[1], $route[0]);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('reads a card once and serves the second read from redis-cache under the seed ref key', function (): void {
    $repository = app(CatalogRepository::class);

    $first = $repository->card('sv1-1');
    $second = $repository->card('sv1-1');

    expect(t42Count($this->calls, T42_CARD))->toBe(1)
        ->and(t42Count($this->calls, '/api/meta'))->toBe(1)
        ->and($first?->data)->toBe(t42Card())
        ->and($first?->cached)->toBeFalse()
        ->and($first?->degraded)->toBeFalse()
        ->and($second?->data)->toBe(t42Card())
        ->and($second?->cached)->toBeTrue()
        ->and($second?->degraded)->toBeFalse()
        ->and($second?->staleSince)->toBeNull()
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-1'))->toBe(1)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:card:sv1-1'))->toBe(1)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:seed_ref'))->toBe(1);
});

it('keeps the last seed ref on redis-queue for 30 days and never on redis-cache', function (): void {
    $seed = app(CatalogRepository::class)->seedRef();

    $key = $this->prefix.'catalog:v1:seed_ref:last';
    expect($seed)->toBeInstanceOf(SeedRef::class)
        ->and($seed->ref)->toBe(T42_REF_A)
        ->and($seed->seededAt)->toBe('2026-10-11T20:05:45Z')
        ->and($seed->segment())->toBe(T42_REF_A)
        ->and(Redis::connection('default')->exists($key))->toBe(1)
        ->and(Redis::connection('cache')->exists($key))->toBe(0)
        ->and(Redis::connection('default')->ttl($key))->toBeGreaterThan(29 * 86400)->toBeLessThanOrEqual(30 * 86400);
});

it('calls PokéSearch again for a cached card once the seed ref changes', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');

    $this->routes['/api/meta'] = [200, t42Meta(T42_REF_B)];
    $this->routes[T42_CARD] = [200, t42Card('Pikachu reseeded')];
    $this->travel(301)->seconds();
    $after = $repository->card('sv1-1');

    expect(t42Count($this->calls, T42_CARD))->toBe(2)
        ->and($after?->data['data']['name'])->toBe('Pikachu reseeded')
        ->and($after?->cached)->toBeFalse()
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:card:'.T42_REF_B.':sv1-1'))->toBe(1);
});

it('keys an unseeded PokéSearch index under the segment unseeded', function (): void {
    $this->routes['/api/meta'] = [200, t42Meta(null)];

    $seed = app(CatalogRepository::class)->seedRef();
    app(CatalogRepository::class)->card('sv1-1');

    expect($seed->ref)->toBeNull()
        ->and($seed->seededAt)->toBeNull()
        ->and($seed->segment())->toBe('unseeded')
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:card:unseeded:sv1-1'))->toBe(1);
});

it('uses the remembered seed ref when PokéSearch is down, so a fresh card stays a cache hit', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');

    $this->routes['/api/meta'] = 'timeout';
    $this->travel(301)->seconds();
    $seed = $repository->seedRef();
    $card = $repository->card('sv1-1');

    expect($seed->ref)->toBe(T42_REF_A)
        ->and(t42Count($this->calls, T42_CARD))->toBe(1)
        ->and($card?->cached)->toBeTrue()
        ->and($card?->degraded)->toBeFalse();
});

it('caches a PokéSearch 404 for 60 s: a missing id twice within 60 s is one call, at 61 s two', function (): void {
    $this->routes[T42_CARD] = [404, ['error' => ['code' => 'not_found']]];
    $repository = app(CatalogRepository::class);

    $first = $repository->card('sv1-1');
    $this->travel(30)->seconds();
    $second = $repository->card('sv1-1');
    $withinWindow = t42Count($this->calls, T42_CARD);
    $this->travel(31)->seconds();
    $third = $repository->card('sv1-1');

    $none = $this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-1:none';
    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and($third)->toBeNull()
        ->and($withinWindow)->toBe(1)
        ->and(t42Count($this->calls, T42_CARD))->toBe(2)
        ->and(Redis::connection('cache')->ttl($none))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-1'))->toBe(0)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:card:sv1-1'))->toBe(0);
});

it('caches nothing for a card when PokéSearch fails, and the next read calls it again', function (string|array $failure): void {
    $this->routes[T42_CARD] = $failure;
    $repository = app(CatalogRepository::class);

    expect(fn () => $repository->card('sv1-1'))->toThrow(UpstreamUnavailable::class);
    $failedCalls = t42Count($this->calls, T42_CARD);
    $cardKeys = array_values(array_filter(
        CatalogCache::keys('cache', $this->prefix),
        fn (string $key): bool => str_contains($key, 'sv1-1'),
    ));

    $this->routes[T42_CARD] = [200, t42Card()];
    $recovered = $repository->card('sv1-1');

    expect($cardKeys)->toBe([])
        ->and($failedCalls)->toBeGreaterThanOrEqual(1)
        ->and(t42Count($this->calls, T42_CARD))->toBe($failedCalls + 1)
        ->and($recovered?->cached)->toBeFalse()
        ->and($recovered?->data)->toBe(t42Card());
})->with([
    'a 500' => [[500, ['error' => 'boom']]],
    'a 503' => [[503, ['error' => 'es down']]],
    'a timeout' => ['timeout'],
]);

it('answers a card past its fresh hour from the cache and refreshes it after the answer', function (): void {
    $this->withoutDefer();
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');

    $this->routes[T42_CARD] = [200, t42Card('Pikachu refreshed')];
    $this->travel(3601)->seconds();
    $stale = $repository->card('sv1-1');
    $refreshed = $repository->card('sv1-1');

    expect($stale?->data)->toBe(t42Card())
        ->and($stale?->cached)->toBeTrue()
        ->and($stale?->degraded)->toBeFalse()
        ->and($refreshed?->data)->toBe(t42Card('Pikachu refreshed'))
        ->and($refreshed?->cached)->toBeTrue()
        ->and(t42Count($this->calls, T42_CARD))->toBe(2);
});

it('serves the stale last-good card flagged degraded once both flexible windows are over and PokéSearch is down', function (): void {
    $repository = app(CatalogRepository::class);
    $fetchedAt = Carbon::now()->startOfSecond();
    $this->travelTo($fetchedAt);
    $repository->card('sv1-1');

    $this->routes['/api/meta'] = 'timeout';
    $this->routes[T42_CARD] = 'timeout';
    $this->travel(86400 + 1)->seconds();
    $stale = $repository->card('sv1-1');

    expect($stale?->degraded)->toBeTrue()
        ->and($stale?->cached)->toBeTrue()
        ->and($stale?->data)->toBe(t42Card())
        ->and($stale?->staleSince)->toBe($fetchedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and(t42Count($this->calls, T42_CARD))->toBeGreaterThan(1);
});

it('throws UpstreamUnavailable with Retry-After on a cold read while PokéSearch is down', function (): void {
    $this->routes = ['/api/meta' => 'timeout', T42_CARD => 'timeout', '/api/search' => 'timeout'];
    $repository = app(CatalogRepository::class);

    foreach ([fn () => $repository->card('sv1-1'), fn () => $repository->search('pikachu')] as $read) {
        $thrown = null;
        try {
            $read();
        } catch (UpstreamUnavailable $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
            ->and($thrown?->getStatusCode())->toBe(503)
            ->and((int) ($thrown?->getHeaders()['Retry-After'] ?? 0))->toBeGreaterThanOrEqual(1);
    }
});

it('stops serving a last-good copy after 7 days and answers UpstreamUnavailable instead', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');

    $this->routes = ['/api/meta' => 'timeout', T42_CARD => 'timeout', '/api/search' => 'timeout'];
    $this->travel(7 * 86400 + 1)->seconds();

    expect(fn () => $repository->card('sv1-1'))->toThrow(UpstreamUnavailable::class);
});

it('caches a search by seed ref and the sha1 of the normalised q, page and page_size', function (): void {
    $repository = app(CatalogRepository::class);

    $first = $repository->search('  Pika   CHU ', 2, 10);
    $second = $repository->search('pika chu', 2, 10);
    $otherPage = $repository->search('pika chu', 3, 10);

    $key = $this->prefix.'catalog:v1:search:'.T42_REF_A.':'.sha1('pika chu|2|10');
    expect(t42Count($this->calls, '/api/search'))->toBe(2)
        ->and($first->cached)->toBeFalse()
        ->and($second->cached)->toBeTrue()
        ->and($otherPage->cached)->toBeFalse()
        ->and($second->data)->toBe($first->data)
        ->and(Redis::connection('cache')->exists($key))->toBe(1)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:search:'.sha1('pika chu|2|10')))->toBe(1);
});

it('never folds two q values that are not valid UTF-8 onto one search key', function (): void {
    $repository = app(CatalogRepository::class);

    $repository->search("\xFF");
    $other = $repository->search("\xFE");

    expect(t42Count($this->calls, '/api/search'))->toBe(2)
        ->and($other->cached)->toBeFalse();
});

it('serves a stale last-good search flagged degraded after both search windows', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->search('pikachu');

    $this->routes = ['/api/meta' => 'timeout', T42_CARD => 'timeout', '/api/search' => 'timeout'];
    $this->travel(901)->seconds();
    $stale = $repository->search('pikachu');

    expect($stale->degraded)->toBeTrue()
        ->and($stale->data['results'][0]['name'])->toBe('Pikachu')
        ->and($stale->staleSince)->not->toBeNull();
});

it('gives every key it writes a TTL on redis-cache and redis-queue (R13)', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');
    $repository->search('pikachu', 1, 24);
    $this->routes['/api/v1/cards/sv1-2'] = [404, ['error' => ['code' => 'not_found']]];
    $repository->card('sv1-2');

    $cacheKeys = CatalogCache::keys('cache', $this->prefix);
    $queueKeys = CatalogCache::keys('default', $this->prefix);
    expect($cacheKeys)->toContain($this->prefix.'catalog:v1:seed_ref')
        ->toContain($this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-1')
        ->toContain($this->prefix.'catalog:v1:lastgood:card:sv1-1')
        ->toContain($this->prefix.'catalog:v1:search:'.T42_REF_A.':'.sha1('pikachu|1|24'))
        ->toContain($this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-2:none')
        ->and($queueKeys)->toBe([$this->prefix.'catalog:v1:seed_ref:last']);
    foreach ($cacheKeys as $key) {
        expect(Redis::connection('cache')->ttl($key))->toBeGreaterThan(0, "{$key} has no TTL");
    }
    foreach ($queueKeys as $key) {
        expect(Redis::connection('default')->ttl($key))->toBeGreaterThan(0, "{$key} has no TTL");
    }
    expect(Redis::connection('cache')->ttl($this->prefix.'catalog:v1:card:'.T42_REF_A.':sv1-1'))->toBeLessThanOrEqual(86400)
        ->and(Redis::connection('cache')->ttl($this->prefix.'catalog:v1:lastgood:card:sv1-1'))->toBeGreaterThan(86400)->toBeLessThanOrEqual(7 * 86400)
        ->and(Redis::connection('cache')->ttl($this->prefix.'catalog:v1:seed_ref'))->toBeLessThanOrEqual(300);
});

it('fills a cold key under the catalog:v1:fill lock and gives up after about 2 s while another filler holds it', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->seedRef();
    $lock = Cache::store('redis')->lock('catalog:v1:fill:catalog:v1:card:'.T42_REF_A.':sv1-1', 5);
    expect($lock->get())->toBeTrue();

    $started = hrtime(true);
    $thrown = null;
    try {
        $repository->card('sv1-1');
    } catch (UpstreamUnavailable $e) {
        $thrown = $e;
    }
    $elapsed = (hrtime(true) - $started) / 1e9;
    $lock->release();

    expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and(t42Count($this->calls, T42_CARD))->toBe(0)
        ->and($elapsed)->toBeGreaterThanOrEqual(1.5)->toBeLessThan(3.0);
});

it('answers a repeated search from the cache with meta.cached true and the public cache policy', function (): void {
    $first = $this->getJson('/v1/cards?q=pikachu');
    $second = $this->getJson('/v1/cards?q=pikachu');

    expect($first->getStatusCode())->toBe(200)
        ->and($first->json('meta.cached'))->toBeFalse()
        ->and($first->json('meta.degraded'))->toBeFalse()
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->json('meta.cached'))->toBeTrue()
        ->and($second->json('meta.degraded'))->toBeFalse()
        ->and($second->json('data'))->toBe($first->json('data'))
        ->and($second->headers->has('ETag'))->toBeTrue()
        ->and(t42Count($this->calls, '/api/search'))->toBe(1);
});

it('answers a degraded search with meta.degraded true, no-store and no ETag when PokéSearch is down', function (): void {
    $this->getJson('/v1/cards?q=pikachu')->assertOk();

    $this->routes = ['/api/meta' => 'timeout', T42_CARD => 'timeout', '/api/search' => 'timeout'];
    $this->travel(901)->seconds();
    $response = $this->getJson('/v1/cards?q=pikachu');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and($response->json('meta.cached'))->toBeTrue()
        ->and($response->json('data.0.name'))->toBe('Pikachu')
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});
