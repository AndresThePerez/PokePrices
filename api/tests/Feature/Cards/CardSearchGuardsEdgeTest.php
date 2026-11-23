<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogCache;

// Reviewer edge tests for the guards of GET /v1/cards after prices came to it: the sort parameter, the 32-word cap as
// PokéSearch counts terms, a failing or odd catalogue in each order, and the HTTP cache across orders. Every upstream
// call is faked and every /v1 answer is also validated against openapi/api.json by Tests\TestCase.

const T06G_BASE = 'http://192.0.2.10:8081';

const T06G_WORD_CAP = 'A search can have at most 32 words.';

const T06G_PROBLEMS = 'https://pokeprices.andrestheperez.com/problems/';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T06G_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T06G_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function t06gHit(string $id): array
{
    return [
        'id' => $id, 'name' => "Card {$id}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => explode('-', $id, 2)[1] ?? '1', 'set_id' => explode('-', $id, 2)[0], 'set_name' => 'Example Set',
        'set_series' => 'Example', 'set_total' => 100, 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/{$id}.png", 'image_large' => "https://images.example.com/{$id}_hires.png",
    ];
}

/** @param list<string> $ids */
function t06gBody(array $ids, ?int $total = null, int $pageSize = 100): array
{
    return ['total' => $total ?? count($ids), 'page' => 1, 'pages' => 1, 'page_size' => $pageSize, 'took_ms' => 2,
        'results' => array_map(t06gHit(...), $ids)];
}

/** @param list<string> $ids */
function t06gFake(array $ids, ?int $total = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T06G_BASE.'/api/search*' => Http::response(t06gBody($ids, $total))]);
}

function t06gClose(string $key, string $close, string $day = '2026-10-13'): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
    DB::table('price_latest')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => $close, 'day' => $day,
        'as_of' => $day.' 20:05:44+00', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null,
    ]);
}

/** @return list<string> */
function t06gIds(mixed $data): array
{
    return array_map(fn (array $row): string => $row['id'], (array) $data);
}

function t06gFresh(object $test, string $uri, array $headers = []): TestResponse
{
    Cache::store('array')->flush();
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    return $test->withHeaders($headers)->getJson($uri);
}

/** @return list<string> $count words of $word joined by $gap */
function t06gWords(int $count, string $gap = ' ', string $word = 'a'): string
{
    return implode($gap, array_fill(0, $count, $word));
}

// The sort parameter.

it('refuses every sort that is not exactly one of the three, on sort alone, and sends nothing upstream', function (string $query): void {
    t06gFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card&'.$query);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(array_keys((array) $response->json('errors')))->toBe(['sort']);
    Http::assertNothingSent();
})->with([
    'a capital letter' => ['sort=Price_Asc'],
    'upper case' => ['sort=RELEVANCE'],
    'a hyphen for the underscore' => ['sort=price-desc'],
    'two words' => ['sort=price_asc,price_desc'],
    'a path' => ['sort=../price_asc'],
    'a NUL byte inside' => ['sort=price%00_asc'],
    'a keyed array' => ['sort[a]=price_asc'],
    'an empty array' => ['sort[]='],
    'a number' => ['sort=1'],
    'a nested array' => ['sort[][]=price_asc'],
    'SQL' => ['sort='.'price_asc%27%3B+DROP+TABLE+price_latest--'],
]);

it('answers a repeated sort in the one order it names, and says which in meta.sort', function (): void {
    t06gClose('sv1-1:holo:-:-', '1.00');
    t06gClose('sv1-2:holo:-:-', '2.00');
    t06gFake(['sv1-1', 'sv1-2']);

    foreach (['sort=price_asc&sort=price_desc', 'sort=price_desc&sort=price_asc'] as $query) {
        $response = t06gFresh($this, '/v1/cards?q=card&'.$query);

        $response->assertOk();
        $sort = $response->json('meta.sort');
        expect($sort)->toBeIn(['price_asc', 'price_desc'])
            ->and(t06gIds($response->json('data')))->toBe($sort === 'price_asc' ? ['sv1-1', 'sv1-2'] : ['sv1-2', 'sv1-1']);
    }
});

it('never reports an order in meta.sort that the rows are not in', function (string $sort): void {
    t06gClose('sv1-1:holo:-:-', '1.00');
    t06gClose('sv1-2:holo:-:-', '9.00');
    t06gClose('sv1-3:holo:-:-', '5.00');
    t06gFake(['sv1-1', 'sv1-2', 'sv1-3']);

    $response = $this->getJson('/v1/cards?q=card&sort='.$sort);

    $order = ['relevance' => ['sv1-1', 'sv1-2', 'sv1-3'], 'price_desc' => ['sv1-2', 'sv1-3', 'sv1-1'], 'price_asc' => ['sv1-1', 'sv1-3', 'sv1-2']];
    expect($response->json('meta.sort'))->toBe($sort)
        ->and(t06gIds($response->json('data')))->toBe($order[$sort])
        ->and($response->json('meta.ordered'))->toBe($sort === 'relevance' ? null : 3);
})->with(['relevance', 'price_desc', 'price_asc']);

// The 32-word cap, counted as PokéSearch counts terms (runs of Unicode white space).

it('refuses 33 words and passes 32 whatever the white space between them', function (string $gap): void {
    t06gFake(['sv1-1']);

    $refused = t06gFresh($this, '/v1/cards?q='.rawurlencode(t06gWords(33, $gap)));
    $passed = t06gFresh($this, '/v1/cards?q='.rawurlencode(t06gWords(32, $gap)));

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($refused->json('errors'))->toBe(['q' => [T06G_WORD_CAP]])
        ->and($passed->getStatusCode())->toBe(200);
    Http::assertSentCount(1);
})->with([
    'a vertical tab' => ["\x0B"],
    'a form feed' => ["\x0C"],
    'a carriage return' => ["\r"],
    'next line, U+0085' => ["\u{0085}"],
    'ogham space mark, U+1680' => ["\u{1680}"],
    'an em space, U+2003' => ["\u{2003}"],
    'a hair space, U+200A' => ["\u{200A}"],
    'a line separator, U+2028' => ["\u{2028}"],
    'a paragraph separator, U+2029' => ["\u{2029}"],
    'a narrow no-break space, U+202F' => ["\u{202F}"],
    'a medium mathematical space, U+205F' => ["\u{205F}"],
    'three mixed separators' => [" \t\u{00A0}"],
]);

it('counts characters that are not white space to PokéSearch as part of a word, so 40 of them around no space are one word', function (string $joiner): void {
    t06gFake(['sv1-1']);

    $response = t06gFresh($this, '/v1/cards?q='.rawurlencode(t06gWords(40, $joiner)));

    expect($response->getStatusCode())->toBe(200);
    Http::assertSentCount(1);
})->with([
    'zero-width space, U+200B' => ["\u{200B}"],
    'zero-width no-break space, U+FEFF' => ["\u{FEFF}"],
    'word joiner, U+2060' => ["\u{2060}"],
    'soft hyphen, U+00AD' => ["\u{00AD}"],
    'no separator at all' => [''],
]);

it('counts words made only of punctuation, and 33 of them are refused', function (): void {
    t06gFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q='.rawurlencode(t06gWords(33, ' ', '-')));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors.q'))->toBe([T06G_WORD_CAP]);
    Http::assertNothingSent();
});

it('refuses 33 one-letter words by the word cap and 32 words over 200 characters by the length rule, both on q alone', function (): void {
    t06gFake(['sv1-1']);

    $many = $this->getJson('/v1/cards?q='.rawurlencode(t06gWords(33)));
    $long = t06gFresh($this, '/v1/cards?q='.rawurlencode(t06gWords(32, ' ', 'pikachu')));
    $both = t06gFresh($this, '/v1/cards?q='.rawurlencode(t06gWords(33, ' ', 'pikachu')));

    expect($many->getStatusCode())->toBe(422)
        ->and($many->json('errors'))->toBe(['q' => [T06G_WORD_CAP]])
        ->and($long->getStatusCode())->toBe(422)
        ->and(array_keys((array) $long->json('errors')))->toBe(['q'])
        ->and($long->json('errors.q'))->not->toContain(T06G_WORD_CAP)
        ->and($both->getStatusCode())->toBe(422)
        ->and(array_keys((array) $both->json('errors')))->toBe(['q'])
        ->and($both->json('errors.q'))->toContain(T06G_WORD_CAP);
    foreach ([$many, $long, $both] as $response) {
        expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and((string) $response->getContent())->not->toContain('PokéSearch answered');
    }
    Http::assertNothingSent();
});

it('passes one word of exactly 200 characters and refuses one of 201, never reaching the upstream-contract problem', function (): void {
    t06gFake(['sv1-1']);

    $exact = $this->getJson('/v1/cards?q='.str_repeat('a', 200));
    $over = t06gFresh($this, '/v1/cards?q='.str_repeat('a', 201));

    expect($exact->getStatusCode())->toBe(200)
        ->and($over->getStatusCode())->toBe(422)
        ->and(array_keys((array) $over->json('errors')))->toBe(['q']);
    Http::assertSentCount(1);
});

it('answers a q of nothing but white space with a 422 or a normal answer, never a 500 and never the upstream-contract problem', function (string $q): void {
    t06gFake([]);

    $response = $this->getJson('/v1/cards?q='.rawurlencode($q));

    expect($response->getStatusCode())->toBeIn([200, 422])
        ->and($response->json('type'))->not->toBe(T06G_PROBLEMS.'upstream-contract');
})->with([
    'spaces' => ['    '],
    'a tab' => ["\t\t"],
    'no-break spaces' => ["\u{00A0}\u{00A0}"],
    'ideographic spaces' => ["\u{3000}"],
]);

it('refuses 33 words that are not valid UTF-8 as a 422 on q, not a 500', function (): void {
    t06gFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q='.rawurlencode(t06gWords(33, ' ', "\xFF")));

    expect($response->getStatusCode())->toBeIn([422])
        ->and(array_keys((array) $response->json('errors')))->toBe(['q']);
    Http::assertNothingSent();
});

it('does not echo the refused query back in the 422', function (): void {
    t06gFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q='.rawurlencode(t06gWords(33, ' ', 'zzsentinel0')));

    expect($response->getStatusCode())->toBe(422)
        ->and((string) $response->getContent())->not->toContain('zzsentinel0');
});

// A failing or odd catalogue, in each order.

/** @return list<array{string, Closure}> */
function t06gFailures(): array
{
    return [
        ['an overloaded 503', fn () => Http::response(['error' => 'overloaded'], 503)],
        ['a 502', fn () => Http::response(['error' => 'bad gateway'], 502)],
        ['a timeout', fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
        ['a 400 invalid_param', fn () => Http::response(['error' => 'invalid_param', 'field' => 'q'], 400)],
        ['a 429', fn () => Http::response(['error' => 'rate_limited'], 429)],
        ['a total that is a string', fn () => Http::response(['total' => 'many', 'page' => 1, 'pages' => 1, 'page_size' => 100, 'results' => []])],
        ['results that are not a list', fn () => Http::response(['total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 100, 'results' => ['a' => 1]])],
        ['a body that is not JSON', fn () => Http::response('<html>proxy error</html>', 200, ['Content-Type' => 'text/html'])],
        ['a hit without a name', fn () => Http::response(['total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 100, 'results' => [['id' => 'sv1-1']]])],
    ];
}

it('answers a failing catalogue in a price order exactly as it does in relevance order, as a problem and never a 500', function (string $scenario): void {
    $reply = array_column(t06gFailures(), 1, 0)[$scenario];
    $seen = [];
    foreach (['sort=relevance', 'sort=price_desc', 'sort=price_asc'] as $query) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([T06G_BASE.'/api/search*' => $reply]);

        $response = t06gFresh($this, '/v1/cards?q=card&'.$query);

        expect($response->getStatusCode())->not->toBe(500, "{$scenario} with {$query}")
            ->and($response->getStatusCode())->toBeGreaterThanOrEqual(400)
            ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
        $seen[$query] = [$response->getStatusCode(), $response->json('type')];
    }

    expect($seen['sort=price_desc'])->toBe($seen['sort=relevance'])
        ->and($seen['sort=price_asc'])->toBe($seen['sort=relevance']);
})->with(array_column(t06gFailures(), 0));

it('answers 503 upstream-unavailable with Retry-After for a catalogue that is down, in every order', function (string $query): void {
    Http::fake([T06G_BASE.'/api/search*' => Http::response(['error' => 'overloaded'], 503)]);

    $response = $this->getJson('/v1/cards?q=card&'.$query);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('type'))->toBe(T06G_PROBLEMS.'upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($response->json('data'))->toBeNull();
})->with(['sort=relevance', 'sort=price_desc', 'sort=price_asc']);

it('answers the problem for a catalogue 400 as the upstream-contract problem without opening the breaker, in every order', function (): void {
    Http::fake([T06G_BASE.'/api/search*' => Http::response(['error' => 'invalid_param', 'field' => 'q'], 400)]);

    foreach (['sort=relevance', 'sort=price_desc', 'sort=price_asc', 'sort=price_desc', 'sort=price_asc', 'sort=price_desc'] as $query) {
        $response = t06gFresh($this, '/v1/cards?q=card&'.$query);

        expect($response->getStatusCode())->toBe(502)
            ->and($response->json('type'))->toBe(T06G_PROBLEMS.'upstream-contract');
    }
    // Six 400s are six requests: a 400 is not retried and does not count towards the breaker.
    Http::assertSentCount(6);
});

it('answers the 503 problem, never a 500, in every order once the breaker is open, and sends nothing while it is', function (): void {
    Http::fake([T06G_BASE.'/api/search*' => Http::response(['error' => 'overloaded'], 503)]);
    for ($n = 1; $n <= 6; $n++) {
        $response = t06gFresh($this, '/v1/cards?q=card&sort=price_desc&page='.$n);
        expect($response->getStatusCode())->toBe(503);
    }
    $sent = count(Http::recorded());

    foreach (['sort=relevance', 'sort=price_desc', 'sort=price_asc'] as $query) {
        $response = t06gFresh($this, '/v1/cards?q=other&'.$query);

        expect($response->getStatusCode())->toBe(503)
            ->and($response->json('type'))->toBe(T06G_PROBLEMS.'upstream-unavailable')
            ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
    }
    expect(count(Http::recorded()))->toBe($sent);
});

it('answers the last good copy in each order with null prices for cards the price tables never saw, never a 500', function (string $query): void {
    t06gFake(['sv1-1', 'sv1-2', 'sv1-3'], 3);
    t06gFresh($this, '/v1/cards?q=card&'.$query)->assertOk();
    CatalogCache::flush($this->catalogPrefix.'catalog:v1:search:');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T06G_BASE.'/api/search*' => Http::response(['error' => 'down'], 503)]);

    $response = t06gFresh($this, '/v1/cards?q=card&'.$query);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and(array_column($response->json('data'), 'price'))->toBe([null, null, null])
        ->and(array_column($response->json('data'), 'variant'))->toBe([null, null, null])
        ->and(t06gIds($response->json('data')))->toBe(['sv1-1', 'sv1-2', 'sv1-3']);
})->with(['sort=relevance&page_size=100', 'sort=price_desc', 'sort=price_asc']);

// An upstream page that is not the size the API asked for.

it('orders what a short page holds and reports the true total when PokéSearch lists fewer rows than it claims', function (): void {
    t06gClose('sv1-2:holo:-:-', '8.00');
    t06gClose('sv1-1:holo:-:-', '3.00');
    t06gFake(['sv1-1', 'sv1-2', 'sv1-3'], 4800);

    $response = $this->getJson('/v1/cards?q=card&sort=price_desc');

    $response->assertOk();
    expect(t06gIds($response->json('data')))->toBe(['sv1-2', 'sv1-1', 'sv1-3'])
        ->and($response->json('meta.total'))->toBe(4800)
        ->and($response->json('meta.ordered'))->toBe(3)
        ->and($response->json('meta.pages'))->toBe(1);
});

it('keeps every row of a page longer than the 100 it asked for, once, across its own pages', function (): void {
    $ids = [];
    for ($n = 1; $n <= 130; $n++) {
        $ids[] = "sv1-{$n}";
        if ($n % 3 !== 0) {
            t06gClose("sv1-{$n}:holo:-:-", sprintf('%d.00', $n % 17));
        }
    }
    t06gFake($ids, 130);

    $walk = [];
    $pages = null;
    for ($page = 1; $page === 1 || $page <= $pages; $page++) {
        $response = t06gFresh($this, "/v1/cards?q=card&sort=price_asc&page={$page}&page_size=40");
        $response->assertOk();
        $pages = $response->json('meta.pages');
        expect($response->json('meta.page'))->toBeLessThanOrEqual($pages);
        $walk = [...$walk, ...t06gIds($response->json('data'))];
    }

    expect($walk)->toHaveCount((int) $response->json('meta.ordered'))
        ->and(array_unique($walk))->toHaveCount(count($walk))
        ->and($response->json('meta.ordered'))->toBe(130);
});

// The HTTP cache across orders, and a price that moves.

it('never answers 304 to the tag of one order when another order is asked, and not after a price moved', function (): void {
    t06gClose('sv1-1:holo:-:-', '1.00');
    t06gClose('sv1-2:holo:-:-', '3.00');
    t06gFake(['sv1-1', 'sv1-2']);
    t06gFresh($this, '/v1/cards?q=card&sort=price_desc')->assertOk(); // warm the upstream page: the tag is of a cached answer
    $desc = t06gFresh($this, '/v1/cards?q=card&sort=price_desc');
    $etag = (string) $desc->headers->get('ETag');

    $sameOrder = t06gFresh($this, '/v1/cards?q=card&sort=price_desc', ['If-None-Match' => $etag]);
    $otherOrder = t06gFresh($this, '/v1/cards?q=card&sort=price_asc', ['If-None-Match' => $etag]);
    $relevance = t06gFresh($this, '/v1/cards?q=card', ['If-None-Match' => $etag]);
    DB::table('price_latest')->where('variant_key', 'sv1-1:holo:-:-')->update(['close' => '1.01']);
    $afterMove = t06gFresh($this, '/v1/cards?q=card&sort=price_desc', ['If-None-Match' => $etag]);

    expect($etag)->not->toBe('')
        ->and($sameOrder->getStatusCode())->toBe(304)
        ->and($otherOrder->getStatusCode())->toBe(200)
        ->and(t06gIds($otherOrder->json('data')))->toBe(['sv1-1', 'sv1-2'])
        ->and($relevance->getStatusCode())->toBe(200)
        ->and($afterMove->getStatusCode())->toBe(200)
        ->and($afterMove->json('data.1.price.close'))->toBe('1.01');
});

it('stamps a cached upstream page with the day the price tables hold now, not the day it was cached on', function (): void {
    t06gClose('sv1-1:holo:-:-', '1.00', '2026-10-12');
    t06gFake(['sv1-1']);
    $before = t06gFresh($this, '/v1/cards?q=card&sort=price_asc');
    DB::table('price_latest')->where('variant_key', 'sv1-1:holo:-:-')->update(['close' => '2.50', 'day' => '2026-10-13', 'as_of' => '2026-10-13 20:05:44+00']);

    $after = t06gFresh($this, '/v1/cards?q=card&sort=price_asc');

    expect($before->json('data.0.price.day'))->toBe('2026-10-12')
        ->and($after->json('meta.cached'))->toBeTrue()
        ->and($after->json('data.0.price.close'))->toBe('2.50')
        ->and($after->json('data.0.price.day'))->toBe('2026-10-13')
        ->and($after->json('data.0.price.as_of'))->toBe('2026-10-13T20:05:44Z');
});

it('asks PokéSearch once for a price order however the page, the page size and the case of q change', function (): void {
    t06gFake(['sv1-1', 'sv1-2', 'sv1-3'], 3);

    foreach (['q=card&sort=price_desc', 'q=card&sort=price_asc&page=2&page_size=2', 'q=CARD&sort=price_desc&page_size=1&page=3', 'q=card&sort=price_asc&page=40'] as $query) {
        t06gFresh($this, '/v1/cards?'.$query)->assertOk();
    }

    Http::assertSentCount(1);
});
