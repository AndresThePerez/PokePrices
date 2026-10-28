<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Http\CachePolicy;
use App\Http\Middleware\ConditionalGet;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// The public-read edge (plan Q1-i5 T02): one cache-policy source, a degraded answer marked by a request attribute
// instead of a re-decoded body flag, and the GET/HEAD validator parity the four public reads keep. Every upstream call
// is faked; every /v1 GET below is also validated against openapi/api.json by Tests\TestCase (C3). A no-store set
// without public reads back as "no-store, private": Symfony adds private to any Cache-Control without public.

const T02_BASE = 'http://192.0.2.10:8081';

const T02_REF = '0123456789abcdef0123456789abcdef01234567';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T02_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T02_BASE, T02_REF);
    Http::preventStrayRequests();
    Queue::fake([VerifyCatalog::class]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function t02Card(string $id = 'base1-4'): array
{
    return [
        'id' => $id, 'name' => 'Charizard', 'supertype' => 'Pokémon', 'subtypes' => ['Stage 2'], 'hp' => 120,
        'types' => ['Fire'], 'rarity' => 'Rare Holo', 'artist' => 'Mitsuhiro Arita', 'number' => '4',
        'set_id' => 'base1', 'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102,
        'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/base1/4.png',
        'image_large' => 'https://images.example.com/base1/4_hires.png',
    ];
}

/** PokéSearch answers the card, the search and the batched names for base1-4. */
function t02Upstream(): void
{
    Http::fake([
        T02_BASE.'/api/v1/cards/base1-4' => Http::response(['data' => t02Card(), 'seed' => ['ref' => T02_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]),
        T02_BASE.'/api/v1/cards?*' => Http::response(['data' => [t02Card()], 'missing' => [], 'seed' => ['ref' => T02_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]),
        T02_BASE.'/api/search*' => Http::response(['total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 24, 'took_ms' => 3, 'results' => [t02Card()]]),
    ]);
}

/** Every later PokéSearch call fails: the fake factory is replaced, so earlier fakes no longer answer. */
function t02UpstreamDown(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
}

/** One base1-4 variant row and one up mover of 2026-10-11 for it. */
function t02Rows(): void
{
    DB::table('card_variants')->insert(['variant_key' => 'base1-4:holo:-:-', 'card_id' => 'base1-4', 'finish' => 'holo',
        'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'base1-4:holo:-:-', 'card_id' => 'base1-4', 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
}

function t02Head(object $test, string $path, array $server = []): TestResponse
{
    return $test->call('HEAD', $path, server: $server + ['HTTP_ACCEPT' => 'application/json']);
}

it('answers no-store and no ETag on GET and HEAD when the controller marks the answer degraded, whatever the body says', function (): void {
    Route::middleware('conditional-get:card')->get('/v1/__t02_marked', function (Request $request): array {
        $request->attributes->set(CachePolicy::DEGRADED, true);

        return ['data' => ['id' => 'base1-4'], 'meta' => ['degraded' => false]];
    });

    $get = $this->getJson('/v1/__t02_marked');
    $head = t02Head($this, '/v1/__t02_marked');
    $hash = '"'.hash('xxh128', (string) $get->getContent()).'"';
    $match = $this->withHeader('If-None-Match', $hash)->getJson('/v1/__t02_marked');

    expect($get->getStatusCode())->toBe(200)
        ->and($get->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($get->headers->has('ETag'))->toBeFalse()
        ->and($head->getStatusCode())->toBe(200)
        ->and($head->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($head->headers->has('ETag'))->toBeFalse()
        ->and($match->getStatusCode())->toBe(200)
        ->and($match->headers->has('ETag'))->toBeFalse();
});

it('keeps an unmarked answer cacheable with its policy and an ETag', function (): void {
    Route::middleware('conditional-get:history')->get('/v1/__t02_unmarked', fn (Request $request): array => ['data' => ['id' => 'base1-4']]);

    $get = $this->getJson('/v1/__t02_unmarked');

    expect($get->headers->get('Cache-Control'))->toBe('max-age=300, public, stale-while-revalidate=600')
        ->and($get->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $get->getContent()).'"');
});

it('names the three conditional-get policies from the one cache-policy source', function (): void {
    expect(ConditionalGet::POLICIES)->toBe(['search' => CachePolicy::SEARCH, 'card' => CachePolicy::CARD, 'history' => CachePolicy::HISTORY])
        ->and(CachePolicy::NO_STORE)->toBe('no-store')
        ->and(CachePolicy::ACCOUNT)->toBe('no-store, private');
});

it('gives GET and HEAD the same ETag, matching the GET body, on the four public reads', function (string $path): void {
    t02Rows();
    t02Upstream();
    $this->getJson($path)->assertOk(); // warms the catalog cache, so the search's meta.cached is the same below

    $get = $this->getJson($path);
    $head = t02Head($this, $path);
    $etag = (string) $get->headers->get('ETag');
    $revalidated = t02Head($this, $path, ['HTTP_IF_NONE_MATCH' => $etag]);

    expect($get->getStatusCode())->toBe(200)
        ->and($etag)->toBe('"'.hash('xxh128', (string) $get->getContent()).'"')
        ->and($head->getStatusCode())->toBe(200)
        ->and($head->getContent())->toBe('')
        ->and($head->headers->get('ETag'))->toBe($etag)
        ->and($head->headers->get('Cache-Control'))->toBe($get->headers->get('Cache-Control'))
        ->and($revalidated->getStatusCode())->toBe(304);
})->with([
    'search' => ['/v1/cards?q=charizard'],
    'card' => ['/v1/cards/base1-4'],
    'prices' => ['/v1/cards/base1-4/prices?range=1M'],
    'movers' => ['/v1/movers'],
]);

it('answers a degraded card, search and movers read no-store with no ETag on GET and HEAD', function (string $path, int $travel): void {
    t02Rows();
    t02Upstream();
    $this->freezeSecond();
    $etag = (string) $this->getJson($path)->headers->get('ETag');
    $this->travel($travel)->seconds();
    t02UpstreamDown();

    $get = $this->getJson($path);
    $head = t02Head($this, $path);
    $match = $this->withHeader('If-None-Match', $etag)->getJson($path);

    expect($etag)->not->toBe('')
        ->and($get->getStatusCode())->toBe(200)
        ->and($get->json('meta.degraded'))->toBeTrue()
        ->and($get->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($get->headers->has('ETag'))->toBeFalse()
        ->and($head->getStatusCode())->toBe(200)
        ->and($head->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($head->headers->has('ETag'))->toBeFalse()
        ->and($match->getStatusCode())->toBe(200)
        ->and($match->headers->has('ETag'))->toBeFalse();
})->with([
    'card' => ['/v1/cards/base1-4', 86401],
    'search' => ['/v1/cards?q=charizard', 901],
    'movers' => ['/v1/movers', 86401],
]);

it('keeps no-store on readiness and price freshness', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    resetReadinessCache();

    $ready = $this->getJson('/v1/ready');
    $freshness = $this->getJson('/v1/prices/freshness');

    expect($ready->getStatusCode())->toBe(200)
        ->and($ready->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($ready->headers->has('ETag'))->toBeFalse()
        ->and($freshness->getStatusCode())->toBe(200)
        ->and($freshness->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($freshness->headers->has('ETag'))->toBeFalse();
});

it('keeps no-store, private on account answers', function (): void {
    Accounts::setUp();

    $me = AccountRows::signIn($this, Accounts::user())->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    expect($me->getStatusCode())->toBe(200)
        ->and($me->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($me->headers->has('ETag'))->toBeFalse();
});
