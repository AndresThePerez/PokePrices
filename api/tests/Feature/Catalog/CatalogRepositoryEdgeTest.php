<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\Problems\UpstreamContract;
use App\Domain\Catalog\Problems\UpstreamUnavailable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// Reviewer edge tests for T42 (catalog repository). Same harness as CatalogRepositoryTest: real Redis under a
// per-test prefix, every upstream call faked by path and counted.

const T42E_BASE = 'http://192.0.2.10:8081';
const T42E_REF_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const T42E_REF_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const T42E_CARD = '/api/v1/cards/sv1-1';

function t42eMeta(?string $ref): array
{
    return [
        'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
        'seed' => $ref === null ? null : ['ref' => $ref, 'seeded_at' => '2026-10-11T20:05:45Z'],
        'request_id' => 'upstream-meta',
    ];
}

function t42eCard(string $name = 'Pikachu'): array
{
    return [
        'data' => ['id' => 'sv1-1', 'name' => $name, 'supertype' => 'Pokémon', 'number' => '1',
            'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'set_series' => 'Scarlet & Violet', 'set_total' => 198,
            'release_date' => '2023-03-31', 'image_small' => 'https://images.example.com/sv1/1.png', 'image_large' => 'https://images.example.com/sv1/1_hires.png',
        ],
        'seed' => ['ref' => T42E_REF_A, 'seeded_at' => '2026-10-11T20:05:45Z'],
    ];
}

function t42eSearch(string $name = 'Pikachu'): array
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

function t42eCount(array $calls, string $path): int
{
    return count(array_keys($calls, $path, true));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T42E_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->prefix = CatalogCache::isolate();

    $this->routes = ['/api/meta' => [200, t42eMeta(T42E_REF_A)], T42E_CARD => [200, t42eCard()], '/api/search' => [200, t42eSearch()]];
    $this->calls = [];
    $this->queries = [];
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = $path;
        $this->queries[] = (string) parse_url($request->url(), PHP_URL_QUERY);
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

it('refuses a card id that could address another key (:none, pipes, newline, unicode, 129 chars) before any read or write', function (string $id): void {
    $thrown = null;
    try {
        app(CatalogRepository::class)->card($id);
    } catch (InvalidArgumentException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($this->calls)->toBe([])
        ->and(CatalogCache::keys('cache', $this->prefix))->toBe([])
        ->and(CatalogCache::keys('default', $this->prefix))->toBe([]);
})->with([
    'negative marker suffix' => ['sv1-1:none'],
    'pipe' => ['sv1|1'],
    'trailing newline' => ["sv1-1\n"],
    'unicode' => ['sv1-é'],
    'empty' => [''],
    '129 chars' => [str_repeat('a', 129)],
]);

it('keeps a q holding pipes apart from the same text plus a page: x|1 without a page is not x on page 1', function (): void {
    $repository = app(CatalogRepository::class);

    $repository->search('x|1');
    $repository->search('x', 1);
    $repository->search('x|', 1, 2);
    $repository->search('x', null, 2);

    expect(t42eCount($this->calls, '/api/search'))->toBe(4);
});

it('keeps every key short and the search working for a 100 KB q and for case and whitespace variants of one q', function (): void {
    $repository = app(CatalogRepository::class);

    $huge = $repository->search(str_repeat('é|:', 40000));
    $repository->search("  PIKACHU \t  ex ");
    $repository->search('pikachu ex');
    $repository->search('PikaChu   EX');

    $keys = array_merge(CatalogCache::keys('cache', $this->prefix), CatalogCache::keys('default', $this->prefix));
    $longest = max(array_map('strlen', $keys));
    expect($huge->data['total'])->toBe(1)
        ->and($longest - strlen($this->prefix))->toBeLessThan(200)
        ->and(t42eCount($this->calls, '/api/search'))->toBe(2);
});

it('does not let a 404 remembered under one seed ref shadow the card after a reseed', function (): void {
    $this->routes[T42E_CARD] = [404, ['error' => ['code' => 'not_found']]];
    $repository = app(CatalogRepository::class);
    expect($repository->card('sv1-1'))->toBeNull();

    // The card appears with the new index, well inside the 60 s negative window.
    $this->routes['/api/meta'] = [200, t42eMeta(T42E_REF_B)];
    $this->routes[T42E_CARD] = [200, t42eCard('Pikachu reseeded')];
    Cache::store('redis')->forget('catalog:v1:seed_ref');
    Cache::store('redis')->forget('illuminate:cache:flexible:created:catalog:v1:seed_ref');
    $after = $repository->card('sv1-1');

    expect($after?->data['data']['name'])->toBe('Pikachu reseeded')
        ->and($after?->degraded)->toBeFalse()
        ->and(t42eCount($this->calls, T42E_CARD))->toBe(2);
});

it('never serves a search cached under one seed ref after the ref moved', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->search('pikachu');

    $this->routes['/api/meta'] = [200, t42eMeta(T42E_REF_B)];
    $this->routes['/api/search'] = [200, t42eSearch('Pikachu reseeded')];
    $this->travel(301)->seconds();
    $after = $repository->search('pikachu');

    expect($after->data['results'][0]['name'])->toBe('Pikachu reseeded')
        ->and($after->cached)->toBeFalse()
        ->and(t42eCount($this->calls, '/api/search'))->toBe(2);
});

it('leaves the last-good copy untouched while degraded: stale_since stays put and no fresh key appears', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');
    $lastGood = $this->prefix.'catalog:v1:lastgood:card:sv1-1';
    $before = Redis::connection('cache')->get($lastGood);

    $this->routes = ['/api/meta' => 'timeout', T42E_CARD => 'timeout', '/api/search' => 'timeout'];
    $this->travel(86401)->seconds();
    $first = $repository->card('sv1-1');
    $this->travel(120)->seconds();
    $second = $repository->card('sv1-1');

    expect($first?->degraded)->toBeTrue()
        ->and($second?->degraded)->toBeTrue()
        ->and($second?->staleSince)->toBe($first?->staleSince)
        ->and(Redis::connection('cache')->get($lastGood))->toBe($before)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:card:'.T42E_REF_A.':sv1-1'))->toBe(0);

    // PokéSearch comes back: the answer is fresh again, not a degraded echo.
    $this->routes = ['/api/meta' => [200, t42eMeta(T42E_REF_A)], T42E_CARD => [200, t42eCard('Pikachu back')], '/api/search' => [200, t42eSearch()]];
    $recovered = $repository->card('sv1-1');
    expect($recovered?->degraded)->toBeFalse()
        ->and($recovered?->staleSince)->toBeNull()
        ->and($recovered?->data['data']['name'])->toBe('Pikachu back');
});

it('answers the last-good copy flagged degraded when another filler holds the fill lock past 2 s on a cold key', function (): void {
    $repository = app(CatalogRepository::class);
    $repository->card('sv1-1');
    $key = 'catalog:v1:card:'.T42E_REF_A.':sv1-1';
    Cache::store('redis')->forget($key);
    Cache::store('redis')->forget('illuminate:cache:flexible:created:'.$key);
    $lock = Cache::store('redis')->lock('catalog:v1:fill:'.$key, 10);
    expect($lock->get())->toBeTrue();
    $callsBefore = t42eCount($this->calls, T42E_CARD);

    try {
        $result = $repository->card('sv1-1');
    } finally {
        $lock->release();
    }

    expect($result?->degraded)->toBeTrue()
        ->and($result?->cached)->toBeTrue()
        ->and($result?->staleSince)->not->toBeNull()
        ->and(t42eCount($this->calls, T42E_CARD))->toBe($callsBefore);
});

it('answers null, not the last-good copy, when PokéSearch 404s a card that was cached before it was removed', function (): void {
    $repository = app(CatalogRepository::class);
    expect($repository->card('sv1-1')?->data['data']['name'])->toBe('Pikachu');

    $this->routes[T42E_CARD] = [404, ['error' => ['code' => 'not_found']]];
    $this->travel(86401)->seconds();
    $after = $repository->card('sv1-1');

    expect($after)->toBeNull();
});

it('rejects a seed ref that could break a key (colon, space, 129 chars) as UpstreamContract and caches nothing', function (string $ref): void {
    $this->routes['/api/meta'] = [200, t42eMeta($ref)];

    $thrown = null;
    try {
        app(CatalogRepository::class)->card('sv1-1');
    } catch (UpstreamContract|UpstreamUnavailable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UpstreamContract::class)
        ->and(t42eCount($this->calls, T42E_CARD))->toBe(0)
        ->and(CatalogCache::keys('cache', $this->prefix))->toBe([])
        ->and(CatalogCache::keys('default', $this->prefix))->toBe([]);
})->with([
    'colon' => ['abc:none'],
    'space' => ['abc def'],
    'too long' => [str_repeat('a', 129)],
]);

it('answers a degraded search 200 with no-store even when the client sends If-None-Match *', function (): void {
    $this->getJson('/v1/cards?q=pikachu')->assertOk();
    $this->routes = ['/api/meta' => 'timeout', T42E_CARD => 'timeout', '/api/search' => 'timeout'];
    $this->travel(901)->seconds();

    $response = $this->withHeader('If-None-Match', '*')->getJson('/v1/cards?q=pikachu');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('s-maxage');
});

it('answers a cold search while PokéSearch is down with a 503 problem carrying Retry-After of at least 1', function (): void {
    $this->routes = ['/api/meta' => 'timeout', T42E_CARD => 'timeout', '/api/search' => 'timeout'];

    $response = $this->getJson('/v1/cards?q=never-cached');

    expect($response->getStatusCode())->toBe(503)
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('s-maxage');
});
