<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogCache;

// Reviewer edge tests for GET /v1/cards with a page past the last one: the matrix of pages, page sizes and sort
// orders, a catalogue whose total changes between the two calls, a failing second call, the cache keys, and the
// answers that must not change. Every upstream call is faked; every /v1 answer is also validated against
// openapi/api.json by Tests\TestCase.

const CLAMPEDGE_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => CLAMPEDGE_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(CLAMPEDGE_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CatalogCache::flush($this->catalogPrefix);
});

/** @return array<string, mixed> */
function clampEdgeHit(int $n): array
{
    return [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => (string) $n, 'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'set_series' => 'Scarlet & Violet',
        'set_total' => 258, 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/sv1/{$n}.png", 'image_large' => "https://images.example.com/sv1/{$n}_hires.png",
    ];
}

/**
 * The body PokéSearch gives for $page of a $total-match result at $size: rows of that page, pages stopping at its
 * 9,600-result window, and a page past the window echoed back at the window's last page with no rows.
 *
 * @return array<string, mixed>
 */
function clampEdgeBody(int $total, int $page, int $size): array
{
    $window = intdiv(9600, $size);
    $pages = min($window, intdiv($total + $size - 1, $size));
    $shown = max(1, min($page, $window));
    $first = ($shown - 1) * $size + 1;
    $last = min($total, $shown * $size);

    return ['total' => $total, 'page' => $shown, 'pages' => $pages, 'page_size' => $size, 'took_ms' => 3,
        'results' => $first > $last ? [] : array_map(clampEdgeHit(...), range($first, $last))];
}

function clampEdgeCatalogue(int $total, int $size = 24): void
{
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) use ($total, $size) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(clampEdgeBody($total, (int) ($query['page'] ?? 1), (int) ($query['page_size'] ?? $size)));
    }]);
}

/** @return list<string|null> the page each recorded catalogue call asked for */
function clampEdgeAsked(): array
{
    return array_values(array_map(function (array $pair): ?string {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return isset($query['page']) ? (string) $query['page'] : null;
    }, Http::recorded()->all()));
}

/** A GET with a fresh rate limiter, so a long walk is not answered 429. */
function clampEdgeGet(object $test, string $uri): TestResponse
{
    Cache::store('array')->flush();

    return $test->getJson($uri);
}

it('keeps meta.page within meta.pages, in at most one extra call, for every page past the end, page size and order', function (array $case): void {
    [$sort, $size, $page] = [$case['sort'], $case['size'], $case['page']];
    $total = 1286;
    clampEdgeCatalogue($total);
    $pages = intdiv($total + $size - 1, $size);
    $asked = $page === 'last' ? (string) $pages : ($page === 'next' ? (string) ($pages + 1) : $page);

    $response = clampEdgeGet($this, "/v1/cards?q=card&page={$asked}&page_size={$size}".($sort === 'relevance' ? '' : "&sort={$sort}"));

    $response->assertOk();
    $meta = $response->json('meta');
    $expectedPages = $sort === 'relevance' ? $pages : max(1, intdiv(100 + $size - 1, $size));
    expect($meta['page'])->toBeInt()->toBeGreaterThanOrEqual(1)
        ->and($meta['pages'])->toBe($expectedPages)
        ->and($meta['page'])->toBeLessThanOrEqual($meta['pages'])
        ->and($meta['page_size'])->toBe($size)
        ->and($response->json('data'))->not->toBeEmpty()
        ->and(count(Http::recorded()))->toBeLessThanOrEqual(2);
    // A price order is one call whatever the page; relevance needs one more only when the page is past the end.
    $past = (int) $asked > $pages;
    expect(count(Http::recorded()))->toBe($sort === 'relevance' && $past ? 2 : 1);
    if ($sort === 'relevance') {
        $lastRows = $total - ($pages - 1) * $size;
        expect($meta['page'])->toBe(min((int) $asked, $pages))
            ->and(count($response->json('data')))->toBe($past || (int) $asked === $pages ? $lastRows : $size);
    }
})->with(function (): array {
    $cases = [];
    foreach (['relevance', 'price_desc', 'price_asc'] as $sort) {
        foreach ([1, 7, 20, 100] as $size) {
            foreach (['last', 'next', '4800', (string) PHP_INT_MAX] as $page) {
                $cases["{$sort} size {$size} page {$page}"] = [['sort' => $sort, 'size' => $size, 'page' => $page]];
            }
        }
    }

    return $cases;
});

it('refuses the page one past the largest integer, and the largest integer is clamped, never a 500', function (): void {
    clampEdgeCatalogue(1286);

    $past = clampEdgeGet($this, '/v1/cards?q=card&page=9223372036854775808');
    $max = clampEdgeGet($this, '/v1/cards?q=card&page=9223372036854775807');

    expect($past->getStatusCode())->toBe(422)
        ->and($max->getStatusCode())->toBe(200)
        ->and($max->json('meta.page'))->toBe(54);
});

it('answers a result of exactly one page and one row over a page with the right last page', function (array $case): void {
    [$total, $pages, $rows] = [$case['total'], $case['pages'], $case['rows']];
    clampEdgeCatalogue($total);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe($pages)
        ->and($response->json('meta.pages'))->toBe($pages)
        ->and($response->json('data'))->toHaveCount($rows)
        ->and(count(Http::recorded()))->toBe(2);
})->with([
    'one row' => [['total' => 1, 'pages' => 1, 'rows' => 1]],
    'exactly one page' => [['total' => 24, 'pages' => 1, 'rows' => 24]],
    'one row over a page' => [['total' => 25, 'pages' => 2, 'rows' => 1]],
    'exactly two pages' => [['total' => 48, 'pages' => 2, 'rows' => 24]],
]);

it('keeps page asked and pages 0 for a result of no matches however large the page, in one call', function (string $page): void {
    clampEdgeCatalogue(0);

    $response = clampEdgeGet($this, "/v1/cards?q=zzzz&page={$page}");

    $response->assertOk();
    expect($response->json('meta.pages'))->toBe(0)
        ->and($response->json('data'))->toBe([])
        ->and(count(Http::recorded()))->toBe(1);
})->with(['1', '2', '4800', (string) PHP_INT_MAX]);

it('answers the 503 problem with Retry-After, never a 200 past its pages, when the total shrinks between the two calls', function (): void {
    // First call: 54 pages, page 4800 echoed back as 400 with no rows. Second call, for page 54: the result has
    // shrunk to 10 pages, so the catalogue echoes page 54 (its window is far larger) with no rows.
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '54'
            ? Http::response(['total' => 240, 'page' => 54, 'pages' => 10, 'page_size' => 24, 'took_ms' => 3, 'results' => []])
            : Http::response(['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);
    }]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($response->getContent())->not->toContain('"meta"')
        ->and(clampEdgeAsked())->toBe(['4800', '54']);
});

it('answers the last page it was sent to when the total grows between the two calls, in two calls', function (): void {
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '54'
            ? Http::response(clampEdgeBody(1500, 54, 24))
            : Http::response(['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);
    }]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('meta.pages'))->toBe(63)
        ->and($response->json('data'))->toHaveCount(24)
        ->and(count(Http::recorded()))->toBe(2);
});

it('never makes a third call when the catalogue keeps echoing a page past its end, and answers the 503 problem', function (): void {
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => Http::response(
        ['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []],
    )]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    expect(count(Http::recorded()))->toBe(2)
        ->and($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});

it('answers 200 with the empty list the catalogue gave when the last page comes back with no rows', function (): void {
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['total' => 1286, 'page' => (int) $query['page'] === 54 ? 54 : 400, 'pages' => 54,
            'page_size' => 24, 'took_ms' => 3, 'results' => []]);
    }]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54)
        ->and($response->json('data'))->toBe([])
        ->and(count(Http::recorded()))->toBe(2);
});

it('answers a problem document, never a 500, when the last-page call fails', function (array $case): void {
    $second = $case['second'];
    $seen = [];
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) use ($second, &$seen) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $seen[] = $query['page'] ?? null;
        if (($query['page'] ?? null) !== '54') {
            return Http::response(['total' => 1286, 'page' => 400, 'pages' => 54, 'page_size' => 24, 'took_ms' => 3, 'results' => []]);
        }

        return $second();
    }]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    expect($response->getStatusCode())->toBe($case['status'])
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(array_slice($seen, 0, 2))->toBe(['4800', '54'])
        ->and(count($seen))->toBeLessThanOrEqual($case['calls']);
})->with([
    'a 400' => [['second' => fn () => Http::response(['error' => 'bad page'], 400), 'status' => 502, 'calls' => 2]],
    'a 500' => [['second' => fn () => Http::response(['error' => 'boom'], 500), 'status' => 503, 'calls' => 3]],
    'a connection failure' => [['second' => function (): never {
        throw new ConnectionException('timeout');
    }, 'status' => 503, 'calls' => 3]],
    'an answer in the wrong shape' => [['second' => fn () => Http::response(['total' => 'many', 'page' => 54, 'pages' => 54, 'page_size' => 24, 'results' => []]), 'status' => 502, 'calls' => 2]],
    'a body that is a list' => [['second' => fn () => Http::response([1, 2, 3]), 'status' => 502, 'calls' => 2]],
]);

it('answers the last good copy of both pages, degraded, when the catalogue is down after the pages were once read', function (): void {
    clampEdgeCatalogue(1286);
    clampEdgeGet($this, '/v1/cards?q=card&page=4800')->assertOk();
    // Past the cache's hard window (900 s) and within the last-good copy's 7 days, with the catalogue now down.
    Carbon::setTestNow(Carbon::now()->addSeconds(1000));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([CLAMPEDGE_BASE.'/*' => Http::response(['error' => 'down'], 503)]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.degraded'))->toBeTrue()
        ->and($response->json('meta.page'))->toBe(54)
        ->and($response->json('data'))->toHaveCount(14);
});

it('does not poison another page size, another query or another order with a clamped answer', function (): void {
    clampEdgeCatalogue(1286);
    $clamped = clampEdgeGet($this, '/v1/cards?q=card&page=4800')->assertOk();
    $calls = count(Http::recorded());

    // The last page, asked for in range with the same query and size, is the clamped answer's own cache entry.
    $same = clampEdgeGet($this, '/v1/cards?q=card&page=54')->assertOk();
    expect(count(Http::recorded()))->toBe($calls)
        ->and($same->json('data'))->toBe($clamped->json('data'));

    // Another page size, another query and another order each still read their own answer.
    $size = clampEdgeGet($this, '/v1/cards?q=card&page=54&page_size=7')->assertOk();
    expect($size->json('meta.page'))->toBe(54)->and($size->json('meta.pages'))->toBe(184)
        ->and($size->json('meta.page_size'))->toBe(7)
        ->and($size->json('data'))->toHaveCount(7);
    $other = clampEdgeGet($this, '/v1/cards?q=other&page=2')->assertOk();
    expect($other->json('meta.page'))->toBe(2)
        ->and($other->json('data.0.id'))->toBe('sv1-25');
    $price = clampEdgeGet($this, '/v1/cards?q=card&sort=price_asc&page=2')->assertOk();
    expect($price->json('meta.sort'))->toBe('price_asc')
        ->and($price->json('meta.page'))->toBe(2)
        ->and($price->json('meta.ordered'))->toBe(100);

    // And page 4800 for page_size 7 is its own answer: 184 pages, 5 rows on the last (1286 = 183 * 7 + 5).
    $seven = clampEdgeGet($this, '/v1/cards?q=card&page=4800&page_size=7')->assertOk();
    expect($seven->json('meta.page'))->toBe(184)->and($seven->json('data'))->toHaveCount(5);
});

it('answers the same bytes for the last page whether it was reached by a clamp or asked for', function (): void {
    clampEdgeCatalogue(1286);
    $viaClamp = clampEdgeGet($this, '/v1/cards?q=card&page=4800')->assertOk()->getContent();
    $direct = clampEdgeGet($this, '/v1/cards?q=card&page=54')->assertOk()->getContent();

    $decode = fn (string $body): array => json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    $a = $decode((string) $viaClamp);
    $b = $decode((string) $direct);
    unset($a['meta']['cached'], $b['meta']['cached']);
    expect($a)->toBe($b);
});

it('still answers every 422 as before for a page, a page size, a sort, an unknown parameter and a 33-word q', function (string $uri): void {
    clampEdgeCatalogue(1286);

    $response = clampEdgeGet($this, $uri);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    Http::assertNothingSent();
})->with([
    'a negative page' => ['/v1/cards?q=card&page=-1'],
    'page 0' => ['/v1/cards?q=card&page=0'],
    'a decimal page' => ['/v1/cards?q=card&page=5.5'],
    'an exponent page' => ['/v1/cards?q=card&page=1e3'],
    'an empty page' => ['/v1/cards?q=card&page='],
    'a page array' => ['/v1/cards?q=card&page[]=2'],
    'page size 0' => ['/v1/cards?q=card&page_size=0&page=4800'],
    'page size 101' => ['/v1/cards?q=card&page_size=101&page=4800'],
    'an unknown sort' => ['/v1/cards?q=card&sort=cheap&page=4800'],
    'an unknown parameter' => ['/v1/cards?q=card&page=4800&debug=1'],
    'no q' => ['/v1/cards?page=4800'],
    '33 words' => ['/v1/cards?q='.implode('%20', array_fill(0, 33, 'a')).'&page=4800'],
]);

it('accepts exactly 32 words with a page past the end and clamps it', function (): void {
    clampEdgeCatalogue(1286);

    $response = clampEdgeGet($this, '/v1/cards?q='.implode('%20', array_fill(0, 32, 'a')).'&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(54);
});

it('answers every in-range page of a 54-page result with its own rows in one call, as before', function (int $page): void {
    clampEdgeCatalogue(1286);

    $response = clampEdgeGet($this, "/v1/cards?q=card&page={$page}");

    $response->assertOk();
    $first = ($page - 1) * 24 + 1;
    expect($response->json('meta.page'))->toBe($page)
        ->and($response->json('meta.pages'))->toBe(54)
        ->and($response->json('data.0.id'))->toBe("sv1-{$first}")
        ->and(clampEdgeAsked())->toBe([(string) $page]);
})->with([1, 2, 27, 53, 54]);

it('never lets a NaN, negative or zero page leave the API for any page the validator accepts', function (string $page): void {
    clampEdgeCatalogue(1286);

    $response = clampEdgeGet($this, "/v1/cards?q=card&page={$page}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('NaN')->and($response->getContent())->not->toContain('INF')
        ->and($response->json('meta.page'))->toBeInt()->toBeGreaterThanOrEqual(1);
})->with(['1', '9999', '400', '401', (string) PHP_INT_MAX]);

// ---- Round 1: the answer is within its own pages, or it is the 503 problem; never a third call ----

/** A catalogue that answers the first call (page 4800) with $first and every other page with $second: [page, pages, rows]. */
function clampEdgePair(array $first, array $second): void
{
    Http::fake([CLAMPEDGE_BASE.'/api/search*' => function (ClientRequest $request) use ($first, $second) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        [$page, $pages, $rows] = ($query['page'] ?? null) === '4800' ? $first : $second;

        return Http::response(['total' => 1286, 'page' => $page, 'pages' => $pages, 'page_size' => 24, 'took_ms' => 3,
            'results' => $rows === 0 ? [] : array_map(clampEdgeHit(...), range(1, $rows))]);
    }]);
}

it('answers a 200 only when its page lies within its pages, else the 503 problem, after at most two calls', function (array $case): void {
    [$first, $second] = [$case['first'], $case['second']];
    clampEdgePair($first, $second);
    $pastEnd = $first[1] >= 1 && $first[0] > $first[1];
    $final = $pastEnd ? $second : $first;
    $consistent = $final[1] < 1 || ($final[0] >= 1 && $final[0] <= $final[1]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    expect(count(Http::recorded()))->toBe($pastEnd ? 2 : 1);
    if ($consistent) {
        $response->assertOk();
        expect($response->json('meta.page'))->toBe($final[0])
            ->and($response->json('meta.pages'))->toBe($final[1])
            ->and($response->json('data'))->toHaveCount($final[2]);
        if ($final[1] >= 1) {
            expect($response->json('meta.page'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($response->json('meta.pages'));
        }
    } else {
        expect($response->getStatusCode())->toBe(503)
            ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60)
            ->and($response->getContent())->not->toContain('"meta"');
    }
})->with(function (): array {
    $firsts = ['past 400/54' => [400, 54, 0], 'past 55/54' => [55, 54, 0], 'past max/54' => [PHP_INT_MAX, 54, 0],
        'in range 54/54' => [54, 54, 3], 'page 0 of 54' => [0, 54, 0], 'negative of 54' => [-1, 54, 0],
        'no matches 400/0' => [400, 0, 0], 'one page 1/1' => [1, 1, 2], 'past one page 2/1' => [2, 1, 0], 'past 5/1' => [5, 1, 0]];
    $seconds = ['same 54/54' => [54, 54, 24], 'shrunk 54/10' => [54, 10, 0], 'shrunk 54/1' => [54, 1, 0],
        'none 54/0' => [54, 0, 0], 'page 0 of 54' => [0, 54, 0], 'negative of 54' => [-1, 54, 0],
        'past 55/54' => [55, 54, 0], 'grew 54/63' => [54, 63, 24], 'one 1/1' => [1, 1, 2], 'max/54' => [PHP_INT_MAX, 54, 0],
        '54/max' => [54, PHP_INT_MAX, 24], 'max/max' => [PHP_INT_MAX, PHP_INT_MAX, 1]];
    $cases = [];
    foreach ($firsts as $a => $first) {
        foreach ($seconds as $b => $second) {
            $cases["{$a} then {$b}"] = [['first' => $first, 'second' => $second]];
        }
    }

    return $cases;
});

it('answers the last page, not a 503, when the last-page entry was refreshed with fewer pages, and stores nothing for the out-of-range page', function (): void {
    $store = Cache::store('redis');
    clampEdgeCatalogue(1286);
    clampEdgeGet($this, '/v1/cards?q=card&page=4800')->assertOk();
    // The last-page entry is refreshed with a result that has shrunk to 10 pages. The out-of-range answer was never
    // stored, so it cannot say 54: it is read again, and so is the last page it names.
    $segment = app(CatalogRepository::class)->seedRef()->segment();
    $outHash = sha1('card|4800|');
    $store->forget("catalog:v1:search:{$segment}:".sha1('card|54|'));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    clampEdgeCatalogue(240);

    $first = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    $first->assertOk();
    expect($first->json('meta.page'))->toBe(10)
        ->and($first->json('meta.pages'))->toBe(10)
        ->and(clampEdgeAsked())->toBe(['4800', '10'])
        ->and(CatalogCache::keys('cache', $this->catalogPrefix))->not->toContain($this->catalogPrefix."catalog:v1:search:{$segment}:{$outHash}");

    // An in-range page of the same query answers 200 too, and the out-of-range read stays uncached.
    $inRange = clampEdgeGet($this, '/v1/cards?q=card&page=2');
    $inRange->assertOk();
    expect($inRange->json('meta.page'))->toBe(2)->and($inRange->json('meta.pages'))->toBe(10);
    expect(clampEdgeGet($this, '/v1/cards?q=card&page=4800')->getStatusCode())->toBe(200)
        ->and(CatalogCache::keys('cache', $this->catalogPrefix))->not->toContain($this->catalogPrefix."catalog:v1:search:{$segment}:{$outHash}");
});

it('keeps the window of a stuck 503 at the documented lifetime in the code', function (): void {
    expect(CatalogRepository::SEARCH_TTL)->toBe([300, 900]);
});

it('answers a degraded copy only when it is consistent: a 200 within its pages, else the 503 problem', function (array $case): void {
    $lastPagePages = $case['lastPagePages'];
    clampEdgeCatalogue(1286);
    clampEdgeGet($this, '/v1/cards?q=card&page=4800')->assertOk();
    if ($lastPagePages !== 54) {
        // Refresh the last-page copy with a shrunk result, then lose the catalogue after the cache window.
        $segment = app(CatalogRepository::class)->seedRef()->segment();
        Cache::store('redis')->forget("catalog:v1:search:{$segment}:".sha1('card|54|'));
        Cache::store('redis')->forget("catalog:v1:search:{$segment}:".sha1('card|4800|'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        // The out-of-range copy says 54 pages, the last-page copy says $lastPagePages (the answer is the 503 now, and
        // both copies are written all the same).
        clampEdgePair([400, 54, 0], [54, $lastPagePages, 0]);
        expect(clampEdgeGet($this, '/v1/cards?q=card&page=4800')->getStatusCode())->toBe(503);
    }
    Carbon::setTestNow(Carbon::now()->addSeconds(1000));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([CLAMPEDGE_BASE.'/*' => Http::response(['error' => 'down'], 503)]);

    $response = clampEdgeGet($this, '/v1/cards?q=card&page=4800');

    expect($response->getStatusCode())->toBe($lastPagePages === 54 ? 200 : 503);
    if ($response->getStatusCode() === 200) {
        expect($response->json('meta.degraded'))->toBeTrue()
            ->and($response->json('meta.page'))->toBeLessThanOrEqual($response->json('meta.pages'))
            ->and($response->json('meta.page'))->toBeGreaterThanOrEqual(1)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    } else {
        expect($response->getStatusCode())->toBe(503)
            ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
    }
    expect($response->getContent())->not->toContain('"page":54,"pages":10');
})->with(['consistent copies' => [['lastPagePages' => 54]], 'a shrunk last-page copy' => [['lastPagePages' => 10]]]);

it('never answers a price order with the consistency 503, whatever the catalogue says about its page', function (array $case): void {
    clampEdgePair($case['first'], $case['first']);

    $response = clampEdgeGet($this, '/v1/cards?q=card&sort=price_desc&page=4800');

    $response->assertOk();
    expect(count(Http::recorded()))->toBe(1)
        ->and($response->json('meta.page'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($response->json('meta.pages'));
})->with([
    'past its pages' => [['first' => [400, 54, 24]]],
    'page 0 of 54' => [['first' => [0, 54, 24]]],
    'negative' => [['first' => [-1, 54, 24]]],
    'no matches' => [['first' => [400, 0, 0]]],
]);
