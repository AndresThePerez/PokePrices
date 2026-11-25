<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Edge cases for a search answer whose page is past its own last page: never stored, still answered, and every
// other answer (empty result, last page) cached as before. The catalogue is faked as a function of a mutable
// result size, a mutable failure switch and a per-page override.

const PAST_EDGE_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => PAST_EDGE_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(PAST_EDGE_BASE);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->total = 1286;
    $this->down = false;
    $this->override = null;
    $this->queue = [];
    $this->asked = [];
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([PAST_EDGE_BASE.'/api/meta' => Http::response([
        'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 1,
        'seed' => ['ref' => '0123456789abcdef0123456789abcdef01234567', 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'r',
    ]), PAST_EDGE_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $this->asked[] = $page;
        if ($this->down) {
            return Http::response('', 503);
        }

        if ($this->queue !== []) {
            return Http::response(array_shift($this->queue));
        }

        return Http::response($this->override ?? pastEdgeAnswer($this->total, $page));
    }]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CatalogCache::flush($this->prefix);
});

/** The catalogue's answer: a page past the last is echoed (capped at the 400-page window) with no rows; no matches answers pages 0 with the page asked for. */
function pastEdgeAnswer(int $total, int $page): array
{
    $pages = intdiv($total + 23, 24);
    $page = $pages === 0 ? $page : min($page, 400);
    $first = ($page - 1) * 24 + 1;
    $last = min($total, $page * 24);
    $rows = array_map(fn (int $n): array => [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => (string) $n, 'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet',
        'release_date' => '2023-03-31', 'image_small' => "https://images.example.com/sv1/{$n}.png",
    ], $first > $last ? [] : range($first, $last));

    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => 24, 'took_ms' => 3, 'results' => $rows];
}

function pastEdgeKey(object $test, string $q, int $page): string
{
    return $test->prefix.'catalog:v1:search:'.app(CatalogRepository::class)->seedRef()->segment().':'.sha1("{$q}|{$page}|");
}

function pastEdgeStored(object $test, string $q, int $page): bool
{
    return in_array(pastEdgeKey($test, $q, $page), CatalogCache::keys('cache', $test->prefix), true);
}

it('answers page 2 of a one-page result as page 1 and stores nothing under page 2', function (): void {
    $this->total = 10;

    $response = $this->getJson('/v1/cards?q=card&page=2');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(1)
        ->and($response->json('meta.pages'))->toBe(1)
        ->and($response->json('data'))->toHaveCount(10)
        ->and($this->asked)->toBe([2, 1])
        ->and(pastEdgeStored($this, 'card', 2))->toBeFalse()
        ->and(pastEdgeStored($this, 'card', 1))->toBeTrue();
});

it('answers the last page when the result shrinks by one page and the last page itself is asked for', function (): void {
    $this->getJson('/v1/cards?q=card&page=54')->assertOk();
    Cache::store(CatalogRepository::STORE)->forget('catalog:v1:search:'.app(CatalogRepository::class)->seedRef()->segment().':'.sha1('card|54|'));
    $this->total = 53 * 24;
    $this->asked = [];

    $response = $this->getJson('/v1/cards?q=card&page=54');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(53)
        ->and($response->json('meta.pages'))->toBe(53)
        ->and($this->asked)->toBe([54, 53])
        ->and(pastEdgeStored($this, 'card', 54))->toBeFalse();
});

it('caches an empty result asked at page 3 (pages 0) and answers it from the cache the second time', function (): void {
    $this->total = 0;

    $first = $this->getJson('/v1/cards?q=zzzz&page=3');
    $second = $this->getJson('/v1/cards?q=zzzz&page=3');

    $first->assertOk();
    $second->assertOk();
    expect($first->json('meta.pages'))->toBe(0)
        ->and($first->json('meta.page'))->toBe(3)
        ->and($second->json('meta.cached'))->toBeTrue()
        ->and($this->asked)->toBe([3])
        ->and(pastEdgeStored($this, 'zzzz', 3))->toBeTrue();
});

it('reads the catalogue for an out-of-range page each time and serves the last page from its cache entry', function (): void {
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();
    $again = $this->getJson('/v1/cards?q=card&page=4800');

    $again->assertOk();
    expect($this->asked)->toBe([4800, 54, 4800])
        ->and($again->json('meta.page'))->toBe(54)
        ->and($again->json('meta.cached'))->toBeTrue()
        ->and(pastEdgeStored($this, 'card', 4800))->toBeFalse()
        ->and(pastEdgeStored($this, 'card', 54))->toBeTrue();
});

it('serves the cached last page, not a stale out-of-range copy, when the catalogue fails on the out-of-range read', function (): void {
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();
    $this->down = true;
    $this->asked = [];

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('data'))->toHaveCount(14)
        ->and($response->json('meta.degraded'))->toBeFalse()
        ->and(array_values(array_unique($this->asked)))->toBe([4800])
        ->and(pastEdgeStored($this, 'card', 4800))->toBeFalse();
});

it('answers degraded from the last-good copies, never as fresh, when the catalogue is down and no entry is live', function (): void {
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();
    foreach (CatalogCache::keys('cache', $this->prefix) as $key) {
        if (str_contains($key, 'catalog:v1:search:')) {
            Cache::store(CatalogRepository::STORE)->forget(substr($key, strlen($this->prefix)));
        }
    }
    $this->down = true;

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.degraded'))->toBeTrue()
        ->and($response->json('meta.page'))->toBe(54)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('keeps answering 200 from a stale in-range entry whose refresh lands past the end, without raising out of the request', function (): void {
    $this->getJson('/v1/cards?q=card&page=54')->assertOk();
    Carbon::setTestNow(Carbon::now()->addSeconds(CatalogRepository::SEARCH_TTL[0] + 60));
    $this->total = 240;
    $this->asked = [];

    $response = $this->getJson('/v1/cards?q=card&page=54');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('meta.cached'))->toBeTrue();
    $follow = $this->getJson('/v1/cards?q=card&page=54');
    $follow->assertOk();
    expect(pastEdgeStored($this, 'card', 54))->toBeTrue();
});

it('stores nothing and answers a problem when the catalogue gives a non-numeric pages', function (): void {
    $this->override = ['total' => 1286, 'page' => 9, 'pages' => '5', 'page_size' => 24, 'took_ms' => 3, 'results' => []];

    $response = $this->getJson('/v1/cards?q=card&page=9');

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(500)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(pastEdgeStored($this, 'card', 9))->toBeFalse()
        ->and(CatalogCache::keys('cache', $this->prefix))->not->toContain($this->prefix.'catalog:v1:lastgood:search:'.sha1('card|9|'));
});

it('answers the 503 problem when the result shrinks between the two reads, stores nothing for it, and heals on the next request', function (): void {
    // The out-of-range read says 54 pages; the read of page 54 comes back from a result that now has 10.
    $this->queue = [pastEdgeAnswer(1286, 4800), ['total' => 240, 'page' => 54, 'pages' => 10, 'page_size' => 24, 'took_ms' => 3, 'results' => []]];

    $stuck = $this->getJson('/v1/cards?q=card&page=4800');

    expect($stuck->getStatusCode())->toBe(503)
        ->and($stuck->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(pastEdgeStored($this, 'card', 4800))->toBeFalse()
        ->and(pastEdgeStored($this, 'card', 54))->toBeFalse();

    $this->total = 240;
    $healed = $this->getJson('/v1/cards?q=card&page=4800');

    $healed->assertOk();
    expect($healed->json('meta.page'))->toBe(10)
        ->and($healed->json('meta.pages'))->toBe(10)
        ->and($healed->json('data'))->toHaveCount(24);
});
