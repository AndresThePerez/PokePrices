<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// GET /v1/cards with a page past the last one. PokéSearch echoes a page past its last (clamped only to its
// 9,600-result window) with no rows; the API answers the last page instead, with one more catalogue call, in
// relevance order, and the price orders keep answering their own last page with no call more. Every upstream
// call is faked; every /v1 answer is also validated against openapi/api.json by Tests\TestCase.

const CLAMP_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => CLAMP_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(CLAMP_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function clampHit(int $n): array
{
    return [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => (string) $n, 'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'set_series' => 'Scarlet & Violet',
        'set_total' => 258, 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/sv1/{$n}.png", 'image_large' => "https://images.example.com/sv1/{$n}_hires.png",
    ];
}

/**
 * A catalogue of $total matches that answers each page the way PokéSearch does: the asked page's rows, and a page
 * past the last one echoed back (no larger than 400, its window) with no rows. $size is its page size when the
 * request names none.
 */
function clampCatalogue(int $total, int $size = 24): void
{
    Http::fake([CLAMP_BASE.'/api/search*' => function (ClientRequest $request) use ($total, $size) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $pageSize = isset($query['page_size']) ? (int) $query['page_size'] : $size;
        $page = min(400, isset($query['page']) ? (int) $query['page'] : 1);
        $pages = intdiv($total + $pageSize - 1, $pageSize);
        $first = ($page - 1) * $pageSize + 1;
        $last = min($total, $page * $pageSize);
        $results = $first > $last ? [] : array_map(clampHit(...), range($first, $last));

        return Http::response(['total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => $pageSize,
            'took_ms' => 3, 'results' => $results]);
    }]);
}

/** @return list<string|null> the page each recorded catalogue call asked for (null when it named none) */
function clampAskedPages(): array
{
    return array_values(array_map(function (array $pair): ?string {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return isset($query['page']) ? (string) $query['page'] : null;
    }, Http::recorded()->all()));
}

/** @return list<string> */
function clampIds(mixed $data): array
{
    return array_map(fn (array $row): string => $row['id'], (array) $data);
}

it('answers the last page, with its rows, for a page past the end of a 54-page result, in one more catalogue call', function (string $page): void {
    clampCatalogue(1296 - 10); // 54 pages of 24, the last holding 14

    $response = $this->getJson("/v1/cards?q=card&page={$page}");

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('meta.pages'))->toBe(54)
        ->and($response->json('meta.total'))->toBe(1286)
        ->and($response->json('meta.page_size'))->toBe(24)
        ->and(clampIds($response->json('data')))->toBe(array_map(fn (int $n): string => "sv1-{$n}", range(1273, 1286)))
        ->and(clampAskedPages())->toBe([$page, '54']);
})->with(['4800' => '4800', 'pages plus one' => '55', '400, the window' => '400']);

it('answers the last page with no extra call when page equals pages', function (): void {
    clampCatalogue(1286);

    $response = $this->getJson('/v1/cards?q=card&page=54');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('data'))->toHaveCount(14)
        ->and(clampAskedPages())->toBe(['54']);
});

it('clamps with the page size the request names', function (): void {
    clampCatalogue(100);

    $response = $this->getJson('/v1/cards?q=card&page=16&page_size=7');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(15)
        ->and($response->json('meta.pages'))->toBe(15)
        ->and(clampIds($response->json('data')))->toBe(['sv1-99', 'sv1-100'])
        ->and(Http::recorded())->toHaveCount(2);
});

it('answers an out-of-range page from the cached last page, with one catalogue call for the out-of-range read', function (): void {
    clampCatalogue(1286);
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();
    $before = count(Http::recorded());

    $again = $this->getJson('/v1/cards?q=card&page=4800');

    $again->assertOk();
    expect($before)->toBe(2)
        ->and(count(Http::recorded()))->toBe(3)
        ->and(clampAskedPages())->toBe(['4800', '54', '4800'])
        ->and($again->json('meta.page'))->toBe(54)
        ->and($again->json('meta.cached'))->toBeTrue()
        ->and($again->json('data'))->toHaveCount(14);
});

it('keeps the shape it has today for a search with no matches, in one call', function (): void {
    clampCatalogue(0);

    $response = $this->getJson('/v1/cards?q=zzzz&page=3');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [],
        'meta' => ['total' => 0, 'page' => 3, 'pages' => 0, 'page_size' => 24, 'degraded' => false, 'cached' => false, 'sort' => 'relevance', 'ordered' => null],
    ])->and(clampAskedPages())->toBe(['3']);
});

it('answers the last page in a price order with its one catalogue call', function (string $sort): void {
    clampCatalogue(60);

    $response = $this->getJson("/v1/cards?q=card&sort={$sort}&page=4800");

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(3)
        ->and($response->json('meta.pages'))->toBe(3)
        ->and($response->json('meta.ordered'))->toBe(60)
        ->and($response->json('data'))->toHaveCount(12)
        ->and(Http::recorded())->toHaveCount(1);
})->with(['price_desc', 'price_asc']);

it('answers an in-range page exactly as before, in one call', function (): void {
    clampCatalogue(1286);

    $response = $this->getJson('/v1/cards?q=card&page=2&page_size=2');

    $response->assertOk();
    $row = fn (int $n): array => [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'number' => (string) $n, 'rarity' => 'Common', 'supertype' => 'Pokémon',
        'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/sv1/{$n}.png", 'variant' => null, 'price' => null,
    ];
    expect($response->getContent())->toBe(json_encode([
        'data' => [$row(3), $row(4)],
        'meta' => ['total' => 1286, 'page' => 2, 'pages' => 643, 'page_size' => 2, 'degraded' => false, 'cached' => false, 'sort' => 'relevance', 'ordered' => null],
    ]))->and(clampAskedPages())->toBe(['2']);
});

it('still refuses page 0 and a page that is not an integer with a 422, sending nothing', function (string $page): void {
    clampCatalogue(1286);

    $response = $this->getJson("/v1/cards?q=card&page={$page}");

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('errors'))->toHaveKey('page');
    Http::assertNothingSent();
})->with(['0', 'abc', '1.5']);

it('answers 503 with Retry-After when the catalogue fails on the last-page call and no copy is kept', function (): void {
    Http::fake([CLAMP_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '54'
            ? Http::response(['error' => 'es down'], 503)
            : Http::response(['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);
    }]);

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
