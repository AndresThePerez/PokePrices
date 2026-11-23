<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogCache;

// Reviewer edge tests for the headline price on search rows and the price orders of GET /v1/cards: which variant is
// the headline, closes compared as amounts, ties and unpriced rows in both directions, and every page of an order.
// Every upstream call is faked and every /v1 answer is also validated against openapi/api.json by Tests\TestCase.

const T06E_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T06E_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T06E_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** One PokéSearch hit for the card $id. */
function t06eHit(string $id): array
{
    return [
        'id' => $id, 'name' => "Card {$id}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => explode('-', $id, 2)[1] ?? '1', 'set_id' => explode('-', $id, 2)[0], 'set_name' => 'Example Set',
        'set_series' => 'Example', 'set_total' => 100, 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/{$id}.png", 'image_large' => "https://images.example.com/{$id}_hires.png",
    ];
}

/**
 * PokéSearch answers the page of 100 (what a price order asks for) with $ids in relevance order and $total matches.
 *
 * @param  list<string>  $ids
 */
function t06eFake(array $ids, ?int $total = null, int $pageSize = 100): void
{
    Http::fake([T06E_BASE.'/api/search*' => Http::response([
        'total' => $total ?? count($ids), 'page' => 1, 'pages' => max(1, (int) ceil(($total ?? count($ids)) / $pageSize)),
        'page_size' => $pageSize, 'took_ms' => 2, 'results' => array_map(t06eHit(...), $ids),
    ])]);
}

function t06eVariant(string $key): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
}

/** The latest close of $key written straight into price_latest. */
function t06eClose(string $key, string $close, string $day = '2026-10-13'): void
{
    t06eVariant($key);
    DB::table('price_latest')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => $close, 'day' => $day,
        'as_of' => $day.' 20:05:44+00', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null,
    ]);
}

/** A GET with a fresh rate limiter, so a long walk over pages is not answered 429 on the 61st request. */
function t06eGet(object $test, string $uri): TestResponse
{
    Cache::store('array')->flush();
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    return $test->getJson($uri);
}

/** A decimal string with two decimals as whole cents, exactly (never a float). */
function t06eCents(string $amount): int
{
    return (int) str_replace('.', '', $amount);
}

/** @return list<string> the card ids of an answer, in order */
function t06eIds(mixed $data): array
{
    return array_map(fn (array $row): string => $row['id'], (array) $data);
}

/**
 * The order the brief states, worked out independently of the controller: priced rows by whole cents (dearest first
 * when $descending), equal closes in catalogue order, then the unpriced rows in catalogue order.
 *
 * @param  list<string>  $ids  the catalogue's order
 * @param  array<string, string|null>  $close
 * @return list<string>
 */
function t06eExpected(array $ids, array $close, bool $descending): array
{
    $rank = array_flip($ids);
    $priced = array_values(array_filter($ids, fn (string $id): bool => ($close[$id] ?? null) !== null));
    $unpriced = array_values(array_filter($ids, fn (string $id): bool => ($close[$id] ?? null) === null));
    usort($priced, function (string $a, string $b) use ($close, $rank, $descending): int {
        $order = t06eCents((string) $close[$a]) <=> t06eCents((string) $close[$b]);

        return ($descending ? -$order : $order) ?: $rank[$a] <=> $rank[$b];
    });

    return [...$priced, ...$unpriced];
}

// The headline variant.

/** The 12 variant suffixes of a card: three finishes by four editions. */
function t06eSuffixes(): array
{
    $suffixes = [];
    foreach (['normal', 'holo', 'reverse'] as $finish) {
        foreach (['-', '1st', 'unlimited', 'shadowless'] as $edition) {
            $suffixes[] = "{$finish}:{$edition}:-";
        }
    }

    return $suffixes;
}

/** @return list<string> the suffixes of the 12 variants of $card in the order the database lists them by variant_key (the card page's order) */
function t06eCardOrder(string $card): array
{
    return DB::table('card_variants')->where('card_id', $card)->orderBy('variant_key')->pluck('variant_key')
        ->map(fn (string $key): string => substr($key, strlen($card) + 1))->all();
}

it('names the first priced variant in card order when the first eleven variants have no close', function (): void {
    foreach (t06eSuffixes() as $suffix) {
        t06eVariant("sv1-1:{$suffix}");
    }
    $order = t06eCardOrder('sv1-1');
    t06eClose('sv1-1:'.$order[11], '7.00');
    t06eVariant('sv1-2:normal:-:-');
    t06eFake(['sv1-1', 'sv1-2', 'sv1-3']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data.0.variant.variant_key'))->toBe('sv1-1:'.$order[11])
        ->and($response->json('data.0.price.close'))->toBe('7.00')
        ->and($response->json('data.1.variant'))->toBeNull()
        ->and($response->json('data.1.price'))->toBeNull()
        ->and($response->json('data.2.variant'))->toBeNull()
        ->and($response->json('data.2.price'))->toBeNull();
});

it('carries the headline variant\'s own day, not the newest day of the card\'s other variants', function (): void {
    t06eClose('sv1-1:holo:-:-', '2.00', '2026-10-10');
    t06eClose('sv1-1:normal:-:-', '900.00', '2026-10-13');
    t06eFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data.0.variant.variant_key'))->toBe('sv1-1:holo:-:-')
        ->and($response->json('data.0.price.close'))->toBe('2.00')
        ->and($response->json('data.0.price.day'))->toBe('2026-10-10')
        ->and($response->json('data.0.price.as_of'))->toBe('2026-10-10T20:05:44Z');
});

it('orders by the headline close, not by the dearest or cheapest close among the card\'s variants', function (): void {
    t06eClose('sv1-1:holo:-:-', '1.00');
    t06eClose('sv1-1:normal:-:-', '500.00');
    t06eClose('sv1-2:holo:-:-', '2.00');
    t06eClose('sv1-2:normal:-:-', '0.50');
    t06eFake(['sv1-1', 'sv1-2']);

    $desc = $this->getJson('/v1/cards?q=card&sort=price_desc');
    $asc = $this->getJson('/v1/cards?q=card&sort=price_asc');

    expect(t06eIds($desc->json('data')))->toBe(['sv1-2', 'sv1-1'])
        ->and(t06eIds($asc->json('data')))->toBe(['sv1-1', 'sv1-2'])
        ->and($desc->json('data.0.price.close'))->toBe('2.00');
});

it('reads a card id carrying ? and ! and a case-different twin as bound values, never as SQL or as the same card', function (): void {
    t06eClose('ex10-?:holo:-:-', '5.00');
    t06eClose('ex10-!:holo:-:-', '6.00');
    t06eClose('Ab-1:holo:-:-', '7.00');
    t06eFake(['ex10-?', 'ex10-!', 'Ab-1', 'ab-1', 'a.b_c-1']);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect(array_column($response->json('data'), 'price'))->toHaveCount(5)
        ->and($response->json('data.0.price.close'))->toBe('5.00')
        ->and($response->json('data.1.price.close'))->toBe('6.00')
        ->and($response->json('data.2.price.close'))->toBe('7.00')
        ->and($response->json('data.3.price'))->toBeNull()
        ->and($response->json('data.4.price'))->toBeNull();
});

it('picks the headline the card page lists first among its priced variants, for every subset of tricky keys', function (): void {
    $keys = ['sv9-1:holo:-:-', 'sv9-1:holo:1st:-', 'sv9-1:holo:-:cosmos', 'sv9-1:normal:-:-', 'sv9-1:reverse:-:-', 'sv9-1:holo:unlimited:-'];
    Http::fake([
        T06E_BASE.'/api/search*' => Http::response(['total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 24, 'took_ms' => 1, 'results' => [t06eHit('sv9-1')]]),
        T06E_BASE.'/api/v1/cards/*' => Http::response(['data' => t06eHit('sv9-1') + ['subtypes' => [], 'hp' => 1, 'types' => [], 'artist' => 'A'], 'seed' => ['ref' => '0123456789abcdef0123456789abcdef01234567', 'seeded_at' => '2026-10-11T20:05:45Z']]),
    ]);
    foreach ($keys as $key) {
        t06eVariant($key);
    }

    for ($mask = 1; $mask < 2 ** count($keys); $mask++) {
        DB::table('price_latest')->whereIn('variant_key', $keys)->delete();
        foreach ($keys as $bit => $key) {
            if (($mask >> $bit) & 1) {
                t06eClose($key, sprintf('%d.00', $bit + 1));
            }
        }
        $card = t06eGet($this, '/v1/cards/sv9-1');
        $firstPriced = collect($card->json('data.variants'))->first(fn (array $variant): bool => ($variant['price'] ?? null) !== null);
        $search = t06eGet($this, '/v1/cards?q=card');

        expect($firstPriced)->not->toBeNull()
            ->and($search->json('data.0.variant.variant_key'))->toBe($firstPriced['variant_key'], "subset {$mask}")
            ->and($search->json('data.0.price.close'))->toBe($firstPriced['price']['close'], "subset {$mask}");
    }
});

it('prices 100 cards of 12 variants each in one query, naming each card\'s first priced variant', function (): void {
    $ids = [];
    $rows = [];
    $variants = [];
    $expect = [];
    $amount = [];
    foreach (range(1, 100) as $n) {
        foreach (t06eSuffixes() as $suffix) {
            $variants[] = ['variant_key' => "sv2-{$n}:{$suffix}", 'card_id' => "sv2-{$n}", 'finish' => explode(':', $suffix)[0],
                'edition' => explode(':', $suffix)[1], 'treatment' => '-', 'catalog_status' => 'unverified'];
        }
    }
    DB::table('card_variants')->insert($variants);
    $order = t06eCardOrder('sv2-1');
    for ($n = 1; $n <= 100; $n++) {
        $ids[] = "sv2-{$n}";
        // The first (n mod 12) variants in card order have no close; the others do.
        $first = $n % 12;
        foreach ($order as $i => $suffix) {
            if ($i >= $first) {
                $rows[] = ['variant_key' => "sv2-{$n}:{$suffix}", 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => sprintf('%d.%02d', $n, $i),
                    'day' => '2026-10-13', 'as_of' => '2026-10-13 20:05:44+00', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null];
            }
        }
        $expect["sv2-{$n}"] = "sv2-{$n}:{$order[$first]}";
        $amount["sv2-{$n}"] = sprintf('%d.%02d', $n, $first);
    }
    DB::table('price_latest')->insert($rows);
    t06eFake($ids);
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    $response = $this->getJson('/v1/cards?q=card&page_size=100');

    $response->assertOk();
    expect($queries)->toBe(1)
        ->and($response->json('data'))->toHaveCount(100);
    foreach ($response->json('data') as $row) {
        expect($row['variant']['variant_key'])->toBe($expect[$row['id']])
            ->and($row['price']['close'])->toBe($amount[$row['id']]);
    }
});

// Closes compared as amounts.

it('orders closes as amounts, not as text or floats, from 0.00 to the largest the column holds, in both directions', function (): void {
    $close = [
        'sv1-1' => '9.99', 'sv1-2' => '10.00', 'sv1-3' => '100.00', 'sv1-4' => '0.00', 'sv1-5' => '0.01', 'sv1-6' => '1000.00',
        'sv1-7' => '99.99', 'sv1-8' => '9999999999.99', 'sv1-9' => '9999999999.98', 'sv1-10' => '1.10', 'sv1-11' => '1.09',
        'sv1-12' => '2.00', 'sv1-13' => '0.10', 'sv1-14' => null, 'sv1-15' => '19.99', 'sv1-16' => '199.90',
    ];
    foreach ($close as $id => $amount) {
        if ($amount !== null) {
            t06eClose("{$id}:holo:-:-", $amount);
        }
    }
    $ids = array_keys($close);
    t06eFake($ids);

    $desc = $this->getJson('/v1/cards?q=card&sort=price_desc&page_size=100');
    $asc = $this->getJson('/v1/cards?q=card&sort=price_asc&page_size=100');

    $desc->assertOk();
    $asc->assertOk();
    expect(t06eIds($desc->json('data')))->toBe(t06eExpected($ids, $close, true))
        ->and(t06eIds($asc->json('data')))->toBe(t06eExpected($ids, $close, false))
        ->and($desc->json('data.0.price.close'))->toBe('9999999999.99')
        ->and($asc->json('data.0.price.close'))->toBe('0.00')
        ->and($asc->json('data.14.id'))->toBe('sv1-8')
        ->and($asc->json('data.15.price'))->toBeNull()
        ->and($desc->json('data.15.price'))->toBeNull();
});

it('counts a close of 0.00 as a price: above every unpriced row, last of the priced from the top and first from the bottom', function (): void {
    t06eClose('sv1-1:holo:-:-', '0.00');
    t06eClose('sv1-2:holo:-:-', '0.01');
    t06eFake(['sv1-3', 'sv1-1', 'sv1-2']);

    $desc = $this->getJson('/v1/cards?q=card&sort=price_desc');
    $asc = $this->getJson('/v1/cards?q=card&sort=price_asc');

    expect(t06eIds($desc->json('data')))->toBe(['sv1-2', 'sv1-1', 'sv1-3'])
        ->and(t06eIds($asc->json('data')))->toBe(['sv1-1', 'sv1-2', 'sv1-3'])
        ->and($desc->json('data.1.price.close'))->toBe('0.00');
});

it('keeps equal closes and the unpriced rows in the catalogue order in both directions, so price_asc is not price_desc reversed', function (): void {
    $close = ['sv1-1' => null, 'sv1-2' => '5.00', 'sv1-3' => '5.00', 'sv1-4' => null, 'sv1-5' => '5.00', 'sv1-6' => '1.00', 'sv1-7' => '5.00'];
    foreach ($close as $id => $amount) {
        if ($amount !== null) {
            t06eClose("{$id}:holo:-:-", $amount);
        }
    }
    t06eFake(array_keys($close));

    $desc = t06eIds($this->getJson('/v1/cards?q=card&sort=price_desc')->json('data'));
    $asc = t06eIds($this->getJson('/v1/cards?q=card&sort=price_asc')->json('data'));
    $again = t06eIds($this->getJson('/v1/cards?q=card&sort=price_desc')->json('data'));

    expect($desc)->toBe(['sv1-2', 'sv1-3', 'sv1-5', 'sv1-7', 'sv1-6', 'sv1-1', 'sv1-4'])
        ->and($asc)->toBe(['sv1-6', 'sv1-2', 'sv1-3', 'sv1-5', 'sv1-7', 'sv1-1', 'sv1-4'])
        ->and($again)->toBe($desc)
        ->and($asc)->not->toBe(array_reverse($desc));
});

it('answers every page the same in a repeated request when every price is equal', function (): void {
    $ids = [];
    for ($n = 1; $n <= 100; $n++) {
        $ids[] = "sv1-{$n}";
        t06eClose("sv1-{$n}:holo:-:-", '3.33');
    }
    shuffle($ids);
    t06eFake($ids, 100);

    $first = [];
    foreach ([1, 2, 3] as $round) {
        $walk = [];
        foreach ([1, 2, 3, 4] as $page) {
            $walk = [...$walk, ...t06eIds(t06eGet($this, "/v1/cards?q=card&sort=price_asc&page={$page}&page_size=30")->json('data'))];
        }
        $first = $round === 1 ? $walk : $first;
        expect($walk)->toBe($first)->and($walk)->toBe($ids);
    }
});

// Every page of an order.

it('walks every page of an order with no card repeated or dropped, whatever the number of matches and the page size', function (int $matches, int $size): void {
    mt_srand(2026112300 + $matches * 1000 + $size);
    $ids = [];
    $close = [];
    for ($n = 1; $n <= $matches; $n++) {
        $ids[] = "sv1-{$n}";
    }
    shuffle($ids);
    foreach (array_slice($ids, 0, 100) as $at => $id) {
        $close[$id] = $at % 6 === 5 ? null : sprintf('%d.%02d', mt_rand(0, 40), mt_rand(0, 3) * 25);
        if ($close[$id] !== null) {
            t06eClose("{$id}:holo:-:-", $close[$id]);
        }
    }
    $listed = array_slice($ids, 0, 100);
    t06eFake($listed, $matches);
    $pages = max(1, intdiv(count($listed) + $size - 1, $size));

    foreach (['price_desc' => true, 'price_asc' => false] as $sort => $descending) {
        $walk = [];
        for ($page = 1; $page <= $pages; $page++) {
            $response = t06eGet($this, "/v1/cards?q=card&sort={$sort}&page={$page}&page_size={$size}");
            $response->assertOk();
            $rows = t06eIds($response->json('data'));
            expect($response->json('meta.page'))->toBe($page)
                ->and($response->json('meta.pages'))->toBe($pages)
                ->and($response->json('meta.page_size'))->toBe($size)
                ->and($response->json('meta.total'))->toBe($matches)
                ->and($response->json('meta.ordered'))->toBe(count($listed))
                ->and($response->json('meta.page'))->toBeLessThanOrEqual($response->json('meta.pages'));
            if ($page < $pages) {
                expect($rows)->toHaveCount($size);
            } elseif ($listed !== []) {
                expect($rows)->not->toBeEmpty()->and(count($rows))->toBeLessThanOrEqual($size);
            }
            $walk = [...$walk, ...$rows];
        }
        expect($walk)->toBe(t06eExpected($listed, $close, $descending), "{$sort} over {$matches} at {$size}")
            ->and(array_unique($walk))->toHaveCount(count($listed));

        $past = t06eGet($this, "/v1/cards?q=card&sort={$sort}&page=".($pages + 1).'&page_size='.$size);
        expect($past->json('meta.page'))->toBe($pages)
            ->and(t06eIds($past->json('data')))->toBe(array_slice($walk, ($pages - 1) * $size));
    }
    // One upstream page of 100 served every request of this walk (a cache hit after the first).
    Http::assertSentCount(1);
    expect(Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->first()->url())->toContain('page_size=100');
})->with([
    '99 matches, 7 a page' => [99, 7],
    '100 matches, 7 a page' => [100, 7],
    '100 matches, 33 a page' => [100, 33],
    '100 matches, 100 a page' => [100, 100],
    '100 matches, 1 a page' => [100, 1],
    '101 matches (100 ordered), 7 a page' => [101, 7],
    '101 matches (100 ordered), 24 a page' => [101, 24],
    '4800 matches, 99 a page' => [4800, 99],
    'one match' => [1, 24],
    'one match, 1 a page' => [1, 1],
    'no match' => [0, 24],
]);

it('refuses a page or page_size that is not a whole number in range in a price order, and sends nothing upstream', function (string $query): void {
    t06eFake(['sv1-1']);

    $response = $this->getJson('/v1/cards?q=card&sort=price_desc&'.$query);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    Http::assertNothingSent();
})->with([
    'page 0' => ['page=0'],
    'a negative page' => ['page=-1'],
    'a word for a page' => ['page=abc'],
    'a decimal page' => ['page=1.5'],
    'an empty page' => ['page='],
    'a page beyond 64 bits' => ['page=99999999999999999999'],
    'page_size 0' => ['page_size=0'],
    'page_size 101' => ['page_size=101'],
    'a negative page_size' => ['page_size=-5'],
    'a word for page_size' => ['page_size=big'],
]);

it('answers the last page, not an error, for the largest page number a 64-bit integer holds', function (): void {
    t06eClose('sv1-2:holo:-:-', '4.00');
    t06eFake(['sv1-1', 'sv1-2', 'sv1-3'], 3);

    $response = $this->getJson('/v1/cards?q=card&sort=price_desc&page=9223372036854775807&page_size=2');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(2)
        ->and($response->json('meta.pages'))->toBe(2)
        ->and(t06eIds($response->json('data')))->toBe(['sv1-3']);
});
