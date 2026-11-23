<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// GET /v1/cards with prices: each row carries the latest close of its headline variant (the first priced variant in
// card order), the sort parameter orders the top 100 PokéSearch matches by that close, and q is capped at 32 words
// as PokéSearch counts terms. Every upstream call is faked; every /v1 answer is also validated against
// openapi/api.json by Tests\TestCase, so each test here checks the contract in the mode it runs.

const T06_BASE = 'http://192.0.2.10:8081';

const T06_WORD_CAP = 'A search can have at most 32 words.';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T06_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T06_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** One PokéSearch hit for the card $id. */
function t06Hit(string $id): array
{
    return [
        'id' => $id, 'name' => "Card {$id}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => explode('-', $id)[1] ?? '1', 'set_id' => explode('-', $id)[0], 'set_name' => 'Example Set',
        'set_series' => 'Example', 'set_total' => 100, 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/{$id}.png", 'image_large' => "https://images.example.com/{$id}_hires.png",
    ];
}

/**
 * A PokéSearch /api/search answer listing $ids in relevance order.
 *
 * @param  list<string>  $ids
 */
function t06Body(array $ids, ?int $total = null, int $page = 1, ?int $pages = null, int $pageSize = 24): array
{
    return [
        'total' => $total ?? count($ids), 'page' => $page, 'pages' => $pages ?? max(1, (int) ceil(count($ids) / $pageSize)),
        'page_size' => $pageSize, 'took_ms' => 3, 'results' => array_map(t06Hit(...), $ids),
    ];
}

/** @param list<string> $ids */
function t06Fake(array $ids, ?int $total = null, int $pageSize = 24): void
{
    Http::fake([T06_BASE.'/api/search*' => Http::response(t06Body($ids, $total, pageSize: $pageSize))]);
}

/** @return array<string, string> the recorded upstream request's query, decoded */
function t06Query(ClientRequest $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function t06Variant(string $key): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
}

/** The latest close of $key, written straight into price_latest (with its variant row). */
function t06Close(string $key, string $close, string $day = '2026-10-13', ?string $previous = null): void
{
    t06Variant($key);
    DB::table('price_latest')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => $close, 'day' => $day,
        'as_of' => $day.' 20:05:44+00',
        'previous_close' => $previous, 'previous_day' => $previous === null ? null : '2026-10-12',
        'change' => $previous === null ? null : t06Money(t06Cents($close) - t06Cents($previous)),
        'change_pct' => null,
    ]);
}

/** A decimal string with two decimals as whole cents, exactly. */
function t06Cents(string $amount): int
{
    return (int) str_replace('.', '', $amount);
}

function t06Money(int $cents): string
{
    return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
}

/** @return list<string> the card ids of an answer, in order */
function t06Ids(mixed $data): array
{
    return array_map(fn (array $row): string => $row['id'], (array) $data);
}

// a. The headline price on each row.

it('carries the headline variant and its latest close with source, day and as_of on a priced row', function (): void {
    t06Close('sv1-1:holo:-:-', '12.50', previous: '10.00');
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data.0.variant'))->toBe(['variant_key' => 'sv1-1:holo:-:-', 'finish' => 'holo', 'edition' => '-', 'treatment' => '-'])
        ->and($response->json('data.0.price'))->toBe([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
            'close' => '12.50', 'day' => '2026-10-13', 'as_of' => '2026-10-13T20:05:44Z', 'previous_close' => '10.00',
            'previous_day' => '2026-10-12', 'change' => '2.50', 'change_pct' => null, 'series_epoch' => 1,
        ]);
});

it('carries a null variant and a null price on a card with no priced variant, even one with a listing', function (): void {
    t06Variant('sv1-2:normal:-:-');
    t06Fake(['sv1-1', 'sv1-2']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    foreach ([0, 1] as $row) {
        expect($response->json("data.{$row}"))->toHaveKey('price')
            ->and($response->json("data.{$row}.price"))->toBeNull()
            ->and($response->json("data.{$row}"))->toHaveKey('variant')
            ->and($response->json("data.{$row}.variant"))->toBeNull();
    }
});

it('picks the first priced variant in card order, skipping an earlier variant without a close', function (): void {
    t06Variant('sv1-1:holo:-:-');
    t06Close('sv1-1:normal:-:-', '3.00');
    t06Close('sv1-1:reverse:-:-', '1.00');
    t06Close('sv1-2:holo:-:-', '9.00');
    t06Close('sv1-2:normal:-:-', '2.00');
    t06Fake(['sv1-1', 'sv1-2']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data.0.variant.variant_key'))->toBe('sv1-1:normal:-:-')
        ->and($response->json('data.0.price.close'))->toBe('3.00')
        ->and($response->json('data.1.variant.variant_key'))->toBe('sv1-2:holo:-:-')
        ->and($response->json('data.1.price.close'))->toBe('9.00');
});

it('lists the summary fields, then variant and price, on every row', function (): void {
    t06Close('sv1-1:holo:-:-', '1.00');
    t06Fake(['sv1-1', 'sv1-2']);

    $response = $this->getJson('/v1/cards?q=card');

    $keys = ['id', 'name', 'number', 'rarity', 'supertype', 'set_id', 'set_name', 'release_date', 'image_small', 'variant', 'price'];
    expect(array_keys($response->json('data.0')))->toBe($keys)
        ->and(array_keys($response->json('data.1')))->toBe($keys);
});

// b. The query count.

it('attaches the prices of a page in one query, for 1 row and for 100', function (int $count): void {
    $ids = [];
    for ($n = 1; $n <= $count; $n++) {
        $ids[] = "sv1-{$n}";
        t06Close("sv1-{$n}:holo:-:-", sprintf('%d.00', $n));
    }
    t06Fake($ids, pageSize: 100);
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    $response = $this->getJson('/v1/cards?q=card&page_size=100');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount($count)
        ->and($response->json('data.'.($count - 1).'.price.close'))->toBe(sprintf('%d.00', $count))
        ->and($queries)->toBe(1);
})->with([1, 100]);

it('attaches the prices of a price-ordered page in one query too', function (): void {
    $ids = [];
    for ($n = 1; $n <= 100; $n++) {
        $ids[] = "sv1-{$n}";
        t06Close("sv1-{$n}:holo:-:-", sprintf('%d.00', $n));
    }
    t06Fake($ids, total: 900, pageSize: 100);
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    $this->getJson('/v1/cards?q=card&sort=price_desc')->assertOk();

    expect($queries)->toBe(1);
});

// c. The price orders.

/**
 * 100 matches: every fifth unpriced, the others priced with repeated closes so ties occur, in relevance order.
 *
 * @return array{ids: list<string>, close: array<string, string|null>}
 */
function t06Hundred(): array
{
    $ids = [];
    $close = [];
    for ($n = 1; $n <= 100; $n++) {
        $id = "sv1-{$n}";
        $ids[] = $id;
        if ($n % 5 === 0) {
            $close[$id] = null;

            continue;
        }
        // 1.00 to 13.00 cents apart, plus 1000.00 and 99.99 so a string order would differ from the numeric one.
        $close[$id] = match (true) {
            $n === 7 => '1000.00',
            $n === 8 => '99.99',
            default => sprintf('%d.%02d', ($n * 7) % 13 + 1, 0),
        };
        t06Close("{$id}:holo:-:-", $close[$id]);
    }
    shuffle($ids);
    t06Fake($ids, total: 4800, pageSize: 100);

    return ['ids' => $ids, 'close' => $close];
}

/**
 * The expected order: priced by close (numerically, $descending or not), ties in relevance order, then the unpriced
 * in relevance order.
 *
 * @param  array{ids: list<string>, close: array<string, string|null>}  $matches
 * @return list<string>
 */
function t06Expected(array $matches, bool $descending): array
{
    $priced = array_values(array_filter($matches['ids'], fn (string $id): bool => $matches['close'][$id] !== null));
    $unpriced = array_values(array_filter($matches['ids'], fn (string $id): bool => $matches['close'][$id] === null));
    $rank = array_flip($matches['ids']);
    usort($priced, function (string $a, string $b) use ($matches, $rank, $descending): int {
        $order = t06Cents((string) $matches['close'][$a]) <=> t06Cents((string) $matches['close'][$b]);

        return ($descending ? -$order : $order) ?: $rank[$a] <=> $rank[$b];
    });

    return [...$priced, ...$unpriced];
}

/** @return list<string> every card id of pages 1 to $pages of the sorted search, in order */
function t06AllPages(object $test, string $sort, int $pages): array
{
    $ids = [];
    for ($page = 1; $page <= $pages; $page++) {
        $response = $test->getJson("/v1/cards?q=card&sort={$sort}&page={$page}");
        $response->assertOk();
        expect($response->json('meta.page'))->toBe($page);
        $ids = [...$ids, ...t06Ids($response->json('data'))];
    }

    return $ids;
}

it('orders the top 100 matches by headline close, dearest first, unpriced last, ties in relevance order, page 2 continuing page 1', function (): void {
    mt_srand(20261123);
    $matches = t06Hundred();

    $first = $this->getJson('/v1/cards?q=card&sort=price_desc');

    $first->assertOk();
    expect($first->json('meta.pages'))->toBe(5)
        ->and($first->json('data'))->toHaveCount(24)
        ->and($first->json('data.0.id'))->toBe('sv1-7')
        ->and($first->json('data.0.price.close'))->toBe('1000.00')
        ->and($first->json('data.1.price.close'))->toBe('99.99');
    $all = t06AllPages($this, 'price_desc', 5);
    expect($all)->toBe(t06Expected($matches, true))
        ->and(array_unique($all))->toHaveCount(100);
});

it('orders the top 100 matches cheapest first with the unpriced still last', function (): void {
    mt_srand(20261124);
    $matches = t06Hundred();

    $all = t06AllPages($this, 'price_asc', 5);

    expect($all)->toBe(t06Expected($matches, false))
        ->and(array_slice($all, -20))->toBe(array_values(array_filter($matches['ids'], fn (string $id): bool => $matches['close'][$id] === null)));
});

it('asks PokéSearch once for its first 100 matches in a price order, whatever page is asked', function (): void {
    t06Hundred();

    $this->getJson('/v1/cards?q=card&sort=price_desc&page=3&page_size=10')->assertOk();

    Http::assertSentCount(1);
    expect(t06Query(Http::recorded()->first()[0]))->toBe(['q' => 'card', 'page' => '1', 'page_size' => '100']);
});

// d. What meta says in a price order.

it('reports the matches it ordered and the true total, and never a page past the last', function (int $total, int $listed, int $pageSize, int $asked, int $pages, int $page, int $rows): void {
    $ids = [];
    for ($n = 1; $n <= $listed; $n++) {
        $ids[] = "sv1-{$n}";
    }
    t06Fake($ids, total: $total, pageSize: 100);

    $response = $this->getJson("/v1/cards?q=card&sort=price_asc&page_size={$pageSize}&page={$asked}");

    $response->assertOk();
    expect($response->json('meta'))->toBe([
        'total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => $pageSize,
        'degraded' => false, 'cached' => false, 'sort' => 'price_asc', 'ordered' => $listed,
    ])
        ->and($response->json('data'))->toHaveCount($rows)
        ->and($response->json('meta.page'))->toBeLessThanOrEqual($response->json('meta.pages'));
})->with([
    'over 100 matches' => [4800, 100, 24, 1, 5, 1, 24],
    'the last page of 100' => [4800, 100, 24, 5, 5, 5, 4],
    'a page past the end answers the last page' => [4800, 100, 24, 9, 5, 5, 4],
    '37 matches' => [37, 37, 24, 1, 2, 1, 24],
    'a page past 37 matches' => [37, 37, 24, 3, 2, 2, 13],
    'no match' => [0, 0, 24, 1, 1, 1, 0],
    'no match, page 4' => [0, 0, 24, 4, 1, 1, 0],
    'one page of 100' => [150, 100, 100, 2, 1, 1, 100],
]);

it('reports the relevance order and no ordered count without a price order', function (): void {
    t06Fake(['sv1-1'], total: 4800);

    $response = $this->getJson('/v1/cards?q=card&page=7');

    expect($response->json('meta.sort'))->toBe('relevance')
        ->and($response->json('meta'))->toHaveKey('ordered')
        ->and($response->json('meta.ordered'))->toBeNull()
        ->and($response->json('meta.page'))->toBe(1);
});

// e. The sort parameter itself.

it('refuses an unknown sort with a 422 problem on sort and sends nothing upstream', function (string $sort): void {
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card&sort='.rawurlencode($sort));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('errors'))->toHaveKey('sort')
        ->and($response->json('errors'))->not->toHaveKey('q');
    Http::assertNothingSent();
})->with(['price', 'PRICE_DESC', 'name', '']);

it('refuses a sort given as a list', function (): void {
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card&sort[]=price_desc');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('sort');
    Http::assertNothingSent();
});

it('answers sort=relevance byte for byte as no sort', function (): void {
    t06Close('sv1-2:holo:-:-', '4.00');
    t06Fake(['sv1-1', 'sv1-2', 'sv1-3'], total: 3);

    $plain = $this->getJson('/v1/cards?q=card');
    $relevance = $this->getJson('/v1/cards?q=card&sort=relevance');
    $plainAgain = $this->getJson('/v1/cards?q=card');

    $plain->assertOk();
    expect($relevance->getContent())->toBe($plainAgain->getContent())
        ->and(t06Ids($plain->json('data')))->toBe(['sv1-1', 'sv1-2', 'sv1-3']);
});

it('still refuses an unknown parameter beside a valid sort', function (): void {
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card&sort=price_desc&order=asc');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('order');
    Http::assertNothingSent();
});

// f. The 32-word cap.

it('refuses a q of more than 32 words with a 422 on q and the sentence, and sends nothing upstream', function (string $q): void {
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q='.rawurlencode($q));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('errors.q'))->toBe([T06_WORD_CAP]);
    Http::assertNothingSent();
})->with([
    '33 words' => [implode(' ', array_fill(0, 33, 'a'))],
    '33 words between tabs' => [implode("\t", array_fill(0, 33, 'a'))],
    '33 words between no-break spaces' => [implode("\u{00A0}", array_fill(0, 33, 'a'))],
    '33 words between ideographic spaces' => [implode("\u{3000}", array_fill(0, 33, 'ab'))],
    '33 words between line feeds' => [implode("\n", array_fill(0, 33, 'a'))],
    '40 words' => [implode(' ', array_fill(0, 40, 'pika'))],
]);

it('passes 32 words however they are spaced', function (string $q): void {
    t06Fake(['sv1-1']);

    $this->getJson('/v1/cards?q='.rawurlencode($q))->assertOk();

    Http::assertSentCount(1);
})->with([
    '32 words' => [implode(' ', array_fill(0, 32, 'a'))],
    '32 words between repeated spaces and tabs' => ['  '.implode(" \t  ", array_fill(0, 32, 'a'))."\t "],
    '32 words between no-break spaces' => [implode("\u{00A0}\u{00A0}", array_fill(0, 32, 'a'))],
    '32 words with zero-width spaces inside words' => [implode(' ', array_fill(0, 32, "a\u{200B}b"))],
    'one word' => ['charizard'],
]);

it('caps a price-ordered search at 32 words too', function (): void {
    t06Fake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q='.rawurlencode(implode(' ', array_fill(0, 33, 'a'))).'&sort=price_desc');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors.q'))->toBe([T06_WORD_CAP]);
    Http::assertNothingSent();
});

// g. A degraded catalogue.

it('answers the last good copy with prices, degraded, when PokéSearch fails, with and without a price order', function (string $query): void {
    t06Close('sv1-2:holo:-:-', '8.00');
    t06Close('sv1-3:holo:-:-', '2.00');
    t06Fake(['sv1-1', 'sv1-2', 'sv1-3'], total: 3, pageSize: 100);
    $this->getJson('/v1/cards?'.$query)->assertOk();
    CatalogCache::flush($this->catalogPrefix.'catalog:v1:search:');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T06_BASE.'/api/search*' => Http::response(['error' => 'es down'], 503)]);

    $response = $this->getJson('/v1/cards?'.$query);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and(array_values(array_filter(array_column($response->json('data'), 'price'))))->toHaveCount(2)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'relevance' => ['q=card&page_size=100'],
    'price_desc' => ['q=card&sort=price_desc'],
    'price_asc' => ['q=card&sort=price_asc'],
]);

it('answers the 503 problem when PokéSearch fails with no last good copy, with and without a price order', function (string $query): void {
    Http::fake([T06_BASE.'/api/search*' => Http::response(['error' => 'es down'], 503)]);

    $response = $this->getJson('/v1/cards?'.$query);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable');
})->with(['q=card', 'q=card&sort=relevance', 'q=card&sort=price_desc', 'q=card&sort=price_asc']);

// h. The orders never share an answer.

it('never serves an answer of one order for another, though the three share one upstream page', function (): void {
    t06Close('sv1-1:holo:-:-', '1.00');
    t06Close('sv1-2:holo:-:-', '3.00');
    t06Close('sv1-3:holo:-:-', '2.00');
    t06Fake(['sv1-1', 'sv1-2', 'sv1-3'], total: 3, pageSize: 100);

    $desc = $this->getJson('/v1/cards?q=card&sort=price_desc');
    $asc = $this->getJson('/v1/cards?q=card&sort=price_asc');
    $relevance = $this->getJson('/v1/cards?q=card&page=1&page_size=100');
    $descAgain = $this->getJson('/v1/cards?q=card&sort=price_desc');

    expect(t06Ids($desc->json('data')))->toBe(['sv1-2', 'sv1-3', 'sv1-1'])
        ->and(t06Ids($asc->json('data')))->toBe(['sv1-1', 'sv1-3', 'sv1-2'])
        ->and(t06Ids($relevance->json('data')))->toBe(['sv1-1', 'sv1-2', 'sv1-3'])
        ->and(t06Ids($descAgain->json('data')))->toBe(['sv1-2', 'sv1-3', 'sv1-1'])
        ->and($asc->json('meta.cached'))->toBeTrue()
        ->and($desc->headers->get('ETag'))->not->toBe($asc->headers->get('ETag'))
        ->and($asc->headers->get('ETag'))->not->toBe($relevance->headers->get('ETag'));
    Http::assertSentCount(1);
});

it('reads each price afresh rather than from a cached answer', function (): void {
    t06Close('sv1-1:holo:-:-', '1.00');
    t06Close('sv1-2:holo:-:-', '3.00');
    t06Fake(['sv1-1', 'sv1-2'], total: 2, pageSize: 100);
    $before = $this->getJson('/v1/cards?q=card&sort=price_desc');
    DB::table('price_latest')->where('variant_key', 'sv1-1:holo:-:-')->update(['close' => '5.00']);

    $after = $this->getJson('/v1/cards?q=card&sort=price_desc');

    expect(t06Ids($before->json('data')))->toBe(['sv1-2', 'sv1-1'])
        ->and(t06Ids($after->json('data')))->toBe(['sv1-1', 'sv1-2'])
        ->and($after->json('data.0.price.close'))->toBe('5.00');
});

// i. The contract.

it('documents sort, the row variant and price, and meta sort and ordered in the exported contract', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/cards']['get'];
    $parameters = array_column($operation['parameters'], null, 'name');
    $body = $operation['responses']['200']['content']['application/json']['schema'];
    $body = isset($body['$ref']) ? $spec['components']['schemas'][basename($body['$ref'])] : $body;
    $item = $body['properties']['data']['items'];
    $item = isset($item['$ref']) ? $spec['components']['schemas'][basename($item['$ref'])] : $item;
    $encoded = json_encode($item, JSON_THROW_ON_ERROR);

    expect(array_keys($parameters))->toBe(['q', 'page', 'page_size', 'sort'])
        ->and($parameters['sort']['schema']['enum'] ?? null)->toBe(['relevance', 'price_desc', 'price_asc'])
        ->and(array_keys($item['properties']))->toBe(['id', 'name', 'number', 'rarity', 'supertype', 'set_id', 'set_name', 'release_date', 'image_small', 'variant', 'price'])
        ->and($item['required'])->toContain('variant')
        ->and($item['required'])->toContain('price')
        ->and($encoded)->toContain('as_of')
        ->and(array_keys($body['properties']['meta']['properties']))->toBe(['total', 'page', 'pages', 'page_size', 'degraded', 'cached', 'sort', 'ordered']);
});
