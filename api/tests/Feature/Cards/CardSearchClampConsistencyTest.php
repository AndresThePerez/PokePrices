<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// GET /v1/cards past the last page when the catalogue's two answers disagree: the result shrank or grew between
// the call that named the last page and the call for it, or the two answers come from cache entries of different
// ages. Whenever meta.pages is at least 1, meta.page is between 1 and meta.pages; an answer that cannot keep that
// with the rows it holds is the 503 problem with Retry-After, after at most two catalogue calls.

const CONSIST_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => CONSIST_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(CONSIST_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function consistHit(int $n): array
{
    return [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => (string) $n, 'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet',
        'release_date' => '2023-03-31', 'image_small' => "https://images.example.com/sv1/{$n}.png",
    ];
}

/** A PokéSearch answer for $page of a $total-match result, 24 a page: the page's rows, none past the last. */
function consistBody(int $total, int $page): array
{
    $pages = intdiv($total + 23, 24);
    $first = ($page - 1) * 24 + 1;
    $last = min($total, $page * 24);

    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => 24, 'took_ms' => 3,
        'results' => $first > $last ? [] : array_map(consistHit(...), range($first, $last))];
}

/** The first call says 54 pages (page 4800 echoed as 400); the call for page 54 is answered by $second. */
function consistFake(array $second): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([CONSIST_BASE.'/api/search*' => function (ClientRequest $request) use ($second) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '54'
            ? Http::response($second)
            : Http::response(['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);
    }]);
}

/** @return list<string|null> */
function consistAsked(): array
{
    return array_values(array_map(function (array $pair): ?string {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return isset($query['page']) ? (string) $query['page'] : null;
    }, Http::recorded()->all()));
}

function consistExpectUnavailable(mixed $response): void
{
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
}

it('answers the 503 problem in two calls when the result shrinks between them to fewer pages', function (int $pages): void {
    consistFake(['total' => $pages * 24, 'page' => 54, 'pages' => $pages, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    consistExpectUnavailable($response);
    expect(consistAsked())->toBe(['4800', '54']);
})->with(['to 10 pages' => 10, 'to 1 page' => 1]);

it('answers the empty result as it stands, in two calls, when the result shrinks between them to no matches', function (): void {
    consistFake(['total' => 0, 'page' => 54, 'pages' => 0, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0)
        ->and($response->json('meta.pages'))->toBe(0)
        ->and(consistAsked())->toBe(['4800', '54']);
});

it('answers page 54 with its rows, in two calls, when the result grows between them', function (): void {
    consistFake(consistBody(1500, 54));

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('meta.pages'))->toBe(63)
        ->and($response->json('data'))->toHaveCount(24)
        ->and($response->json('data.0.id'))->toBe('sv1-1273')
        ->and(consistAsked())->toBe(['4800', '54']);
});

it('answers the 503 problem in one call when the out-of-range entry is cached and the last-page entry was refreshed with fewer pages', function (): void {
    consistFake(consistBody(1286, 54));
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();
    expect(consistAsked())->toBe(['4800', '54']);

    // The last page's entry ends its window and is filled again from a catalogue that now holds 10 pages; the
    // out-of-range entry, still in its window, says 54.
    $segment = app(CatalogRepository::class)->seedRef()->segment();
    Cache::store(CatalogRepository::STORE)->forget("catalog:v1:search:{$segment}:".sha1('card|54|'));
    consistFake(['total' => 240, 'page' => 54, 'pages' => 10, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    consistExpectUnavailable($response);
    expect(consistAsked())->toBe(['54']);
});

it('answers the 503 problem in one call when the catalogue names page 0 of a result with pages', function (): void {
    Http::fake([CONSIST_BASE.'/api/search*' => Http::response(
        ['total' => 30, 'page' => 0, 'pages' => 2, 'page_size' => 24, 'took_ms' => 3, 'results' => [consistHit(1)]],
    )]);

    $response = $this->getJson('/v1/cards?q=card&page=2');

    consistExpectUnavailable($response);
    expect(count(Http::recorded()))->toBe(1);
});

it('keeps meta.page between 1 and meta.pages in every order for a page past the end of a consistent catalogue', function (string $sort): void {
    Http::fake([CONSIST_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $size = (int) ($query['page_size'] ?? 24);
        $pages = intdiv(1286 + $size - 1, $size);
        $page = min($page, 400);
        $first = ($page - 1) * $size + 1;
        $last = min(1286, $page * $size);

        return Http::response(['total' => 1286, 'page' => $page, 'pages' => $pages, 'page_size' => $size, 'took_ms' => 3,
            'results' => $first > $last ? [] : array_map(consistHit(...), range($first, $last))]);
    }]);

    $response = $this->getJson("/v1/cards?q=card&page=4800&sort={$sort}");

    $response->assertOk();
    expect($response->json('meta.page'))->toBeGreaterThanOrEqual(1)
        ->and($response->json('meta.page'))->toBe($response->json('meta.pages'))
        ->and($response->json('data'))->not->toBe([])
        ->and(count(Http::recorded()))->toBe($sort === 'relevance' ? 2 : 1);
})->with(['relevance', 'price_desc', 'price_asc']);
