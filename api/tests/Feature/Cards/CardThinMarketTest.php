<?php

declare(strict_types=1);

use App\Domain\Pricing\LatestPrices;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// The lowest listing and the thin-market flag of GET /v1/cards/{id}. A variant's lowest listing is the low of the
// observation its close came from (the price_points row at the close's as_of). Its market is thin when that listing
// is present and above zero and the close is under half of it; exactly half is not thin. A variant with listings but
// no close carries the newest observation's lowest listing in `listing`, with that observation's day and marker,
// and its price stays null. Every row below is written directly, so each case pins its exact input; every /v1
// answer is also validated against openapi/api.json by Tests\TestCase.

const T05T_BASE = 'http://192.0.2.10:8081';

const T05T_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

function t05tAnswer(string $id): array
{
    return ['data' => [
        'id' => $id, 'name' => 'Charizard', 'supertype' => 'Pokémon', 'subtypes' => ['Stage 2'], 'hp' => 120, 'types' => ['Fire'],
        'rarity' => 'Rare Holo', 'number' => '4', 'artist' => 'Mitsuhiro Arita', 'set_id' => explode('-', $id)[0],
        'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/card.png', 'image_large' => 'https://images.example.com/card_hires.png',
    ], 'seed' => ['ref' => T05T_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']];
}

function t05tRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->orderBy('id')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-01',
        'marker' => '2026-10-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-10-01 20:10:00+00',
        'finished_at' => '2026-10-01 20:11:00+00']);
}

/**
 * Adds the variant $key of card $card and one observation per day of $days, each [market, low]: a market that is not
 * null also writes the day's real close at the observation's marker (20:05 UTC). Returns the key.
 *
 * @param  array<string, array{0: string|null, 1: string|null}>  $days
 */
function t05tVariant(string $card, string $key, array $days): string
{
    [, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
    foreach ($days as $day => [$market, $low]) {
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, 1, 1, 'Holofoil', ?::numeric, ?::numeric,
                    NULL, NULL, NULL, ?)
            SQL, [$key, $day, $day, $market, $low, t05tRun()]);
        if ($market !== null) {
            DB::statement(<<<'SQL'
                INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
                VALUES (?, 'tcgplayer', ?::date, 1, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
                SQL, [$key, $day, $market, $market, $market, $market, $day]);
        }
    }

    return $key;
}

/** @return array<string, array<string, mixed>> the variants of GET /v1/cards/$card by key, after rebuilding price_latest */
function t05tCard(object $test, string $card): array
{
    LatestPrices::rebuild(DB::connection());
    $response = $test->getJson('/v1/cards/'.rawurlencode($card));
    $response->assertOk();

    return array_column($response->json('data.variants'), null, 'variant_key');
}

/** Thirteen days, 2026-10-01 to 2026-10-13, each [$market, $low]. */
function t05tThirteen(?string $market, ?string $low): array
{
    $days = [];
    foreach (range(1, 13) as $d) {
        $days[sprintf('2026-10-%02d', $d)] = [$market, $low];
    }

    return $days;
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T05T_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T05T_BASE, T05T_SEED_REF);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    Http::preventStrayRequests();
    Http::fake([T05T_BASE.'/api/v1/cards/*' => fn ($request) => Http::response(t05tAnswer(rawurldecode(basename($request->url()))))]);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

it('flags a 1st Edition Charizard held flat at 1000.00 for thirteen closes against a lowest listing of 2500.00', function (): void {
    t05tVariant('base1-4', 'base1-4:holo:1st:-', t05tThirteen('1000.00', '2500.00'));

    $price = t05tCard($this, 'base1-4')['base1-4:holo:1st:-']['price'];

    expect($price)->toBe([
        'source' => 'tcgplayer',
        'feed' => 'tcgcsv',
        'basis' => 'market',
        'currency' => 'USD',
        'cadence' => 'daily',
        'close' => '1000.00',
        'day' => '2026-10-13',
        'as_of' => '2026-10-13T20:05:00Z',
        'previous_close' => '1000.00',
        'previous_day' => '2026-10-12',
        'change' => '0.00',
        'change_pct' => '0.00',
        'series_epoch' => 1,
        'lowest_listing' => '2500.00',
        'thin_market' => true,
    ]);
});

it('reads the lowest listing of the observation the close came from, not a newer or an older one', function (): void {
    // The close of 10-12 is the newest; the observation of 10-13 has no market and another low.
    t05tVariant('base1-4', 'base1-4:holo:1st:-', ['2026-10-11' => ['900.00', '1000.00'], '2026-10-12' => ['1000.00', '2500.00'],
        '2026-10-13' => [null, '1500.00']]);

    $variant = t05tCard($this, 'base1-4')['base1-4:holo:1st:-'];

    expect($variant['price'])->toMatchArray(['close' => '1000.00', 'day' => '2026-10-12', 'lowest_listing' => '2500.00', 'thin_market' => true])
        ->and($variant)->not->toHaveKey('listing');
});

it('flags the Gyarados close of 111.61 against a lowest listing of 2380.00, and not against one of 150.00', function (): void {
    t05tVariant('base1-6', 'base1-6:holo:1st:-', ['2026-10-12' => ['100.00', '95.00'], '2026-10-13' => ['111.61', '2380.00']]);
    t05tVariant('base1-6', 'base1-6:holo:shadowless:-', ['2026-10-12' => ['100.00', '95.00'], '2026-10-13' => ['111.61', '150.00']]);

    $variants = t05tCard($this, 'base1-6');

    expect($variants['base1-6:holo:1st:-']['price'])->toMatchArray(['close' => '111.61', 'lowest_listing' => '2380.00', 'thin_market' => true])
        ->and($variants['base1-6:holo:shadowless:-']['price'])->toMatchArray(['close' => '111.61', 'lowest_listing' => '150.00', 'thin_market' => false]);
});

it('does not flag a close of exactly half the lowest listing, flags one cent under half and not one cent over', function (): void {
    // Half of 2000.00 is 1000.00; half of 2000.02 is 1000.01 (1000.00 is a cent under it); half of 1999.98 is 999.99.
    t05tVariant('base1-4', 'base1-4:holo:1st:-', ['2026-10-13' => ['1000.00', '2000.00']]);
    t05tVariant('base1-4', 'base1-4:holo:shadowless:-', ['2026-10-13' => ['1000.00', '2000.02']]);
    t05tVariant('base1-4', 'base1-4:holo:unlimited:-', ['2026-10-13' => ['1000.00', '1999.98']]);
    // An odd low: half of 2000.01 is 1000.005, and 1000.00 is under it.
    t05tVariant('base1-4', 'base1-4:holo:unlimited:error-black-dot', ['2026-10-13' => ['1000.00', '2000.01']]);

    $variants = t05tCard($this, 'base1-4');

    expect($variants['base1-4:holo:1st:-']['price']['thin_market'])->toBeFalse()
        ->and($variants['base1-4:holo:shadowless:-']['price']['thin_market'])->toBeTrue()
        ->and($variants['base1-4:holo:unlimited:-']['price']['thin_market'])->toBeFalse()
        ->and($variants['base1-4:holo:unlimited:error-black-dot']['price']['thin_market'])->toBeTrue()
        ->and(array_map(fn (array $v): ?string => $v['price']['lowest_listing'], $variants))->toBe([
            'base1-4:holo:1st:-' => '2000.00',
            'base1-4:holo:shadowless:-' => '2000.02',
            'base1-4:holo:unlimited:-' => '1999.98',
            'base1-4:holo:unlimited:error-black-dot' => '2000.01',
        ]);
});

it('reads a lowest listing of 0.00 or null as no listing, flags a market of 0.00 under a listing and keeps the largest values exact', function (): void {
    t05tVariant('zz1-1', 'zz1-1:holo:-:-', ['2026-10-13' => ['10.00', '0.00']]);
    t05tVariant('zz1-1', 'zz1-1:normal:-:-', ['2026-10-13' => ['10.00', null]]);
    t05tVariant('zz1-1', 'zz1-1:reverse:-:-', ['2026-10-13' => ['0.00', '5.00']]);
    t05tVariant('zz1-1', 'zz1-1:reverse:1st:-', ['2026-10-13' => ['0.00', null]]);
    t05tVariant('zz1-1', 'zz1-1:holo:1st:-', ['2026-10-13' => ['9999999999.99', '9999999999.99']]);
    // Half of 9999999999.99 is 4999999999.995: 4999999999.99 is under it, 5000000000.00 is not.
    t05tVariant('zz1-1', 'zz1-1:holo:unlimited:-', ['2026-10-13' => ['4999999999.99', '9999999999.99']]);
    t05tVariant('zz1-1', 'zz1-1:holo:shadowless:-', ['2026-10-13' => ['5000000000.00', '9999999999.99']]);

    $flags = array_map(fn (array $v): array => [$v['price']['lowest_listing'], $v['price']['thin_market']], t05tCard($this, 'zz1-1'));

    expect($flags)->toBe([
        'zz1-1:holo:-:-' => [null, false],
        'zz1-1:holo:1st:-' => ['9999999999.99', false],
        'zz1-1:holo:shadowless:-' => ['9999999999.99', false],
        'zz1-1:holo:unlimited:-' => ['9999999999.99', true],
        'zz1-1:normal:-:-' => [null, false],
        'zz1-1:reverse:-:-' => ['5.00', true],
        'zz1-1:reverse:1st:-' => [null, false],
    ]);
});

it('carries the newest lowest listing of a variant with listings but no close, with its day and marker, and keeps its price null', function (): void {
    t05tVariant('base1-4', 'base1-4:holo:1st:-', ['2026-10-12' => [null, '120000.00'], '2026-10-13' => [null, '100000.00']]);
    t05tVariant('base1-4', 'base1-4:holo:unlimited:-', ['2026-10-13' => ['990.98', '489.99']]);

    $variants = t05tCard($this, 'base1-4');

    expect($variants['base1-4:holo:1st:-']['price'])->toBeNull()
        ->and($variants['base1-4:holo:1st:-']['listing'])->toBe([
            'source' => 'tcgplayer',
            'feed' => 'tcgcsv',
            'currency' => 'USD',
            'cadence' => 'daily',
            'lowest_listing' => '100000.00',
            'day' => '2026-10-13',
            'as_of' => '2026-10-13T20:05:00Z',
        ])
        ->and(array_keys($variants['base1-4:holo:1st:-']))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'sources', 'price', 'listing'])
        ->and($variants['base1-4:holo:unlimited:-'])->not->toHaveKey('listing');
});

it('leaves a variant with no close and no listing as it was: price null and no listing field', function (): void {
    // No observation at all; a newest observation without a low; one whose newest low is 0.00 after an older listing.
    DB::table('card_variants')->insert(['variant_key' => 'base1-4:holo:1st:-', 'card_id' => 'base1-4', 'finish' => 'holo',
        'edition' => '1st', 'treatment' => '-', 'catalog_status' => 'unverified']);
    t05tVariant('base1-4', 'base1-4:holo:shadowless:-', ['2026-10-12' => [null, '3000.00'], '2026-10-13' => [null, null]]);
    t05tVariant('base1-4', 'base1-4:holo:unlimited:-', ['2026-10-12' => [null, '700.00'], '2026-10-13' => [null, '0.00']]);

    $variants = t05tCard($this, 'base1-4');

    foreach ($variants as $key => $variant) {
        expect(array_keys($variant))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'sources', 'price'], $key)
            ->and($variant['price'])->toBeNull();
    }
    expect($variants)->toHaveCount(3);
});

it('attaches the listings of every variant with one query, whatever the number of variants', function (): void {
    $statements = null;
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (is_array($statements)) {
            $statements[] = $query->sql;
        }
    });
    $count = function (string $card) use (&$statements): array {
        LatestPrices::rebuild(DB::connection());
        $statements = [];
        $this->getJson("/v1/cards/{$card}")->assertOk();
        $seen = $statements;
        $statements = null;

        return [count($seen), count(array_filter($seen, fn (string $sql): bool => str_contains($sql, 'price_points')))];
    };
    t05tVariant('zz2-1', 'zz2-1:holo:-:-', ['2026-10-13' => ['10.00', '30.00']]);
    $one = $count('zz2-1');
    foreach (['normal:-', 'reverse:-', 'holo:-', 'holo:1st', 'holo:unlimited', 'holo:shadowless'] as $i => $kind) {
        t05tVariant('zz2-2', "zz2-2:{$kind}:-", $i % 2 === 0
            ? ['2026-10-12' => ['10.00', '15.00'], '2026-10-13' => ['11.00', '30.00']]
            : ['2026-10-13' => [null, '20.00']]);
    }
    $six = $count('zz2-2');

    expect($one[1])->toBe(1)
        ->and($six)->toBe($one);
});

it('documents the lowest listing and the thin-market flag on the card price block only, and an optional listing', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $object = function (array $schema): array {
        return collect($schema['anyOf'] ?? $schema['oneOf'] ?? [$schema])->first(fn (array $s): bool => isset($s['properties']));
    };
    $variant = $spec['components']['schemas']['CardResource']['properties']['variants']['items'];
    $price = $object($variant['properties']['price']);
    $listing = $object($variant['properties']['listing']);
    $watched = $object($spec['components']['schemas']['WatchlistItemResource']['properties']['price']);

    expect(array_keys($price['properties']))->toBe(['source', 'feed', 'basis', 'currency', 'cadence', 'close', 'day', 'as_of',
        'previous_close', 'previous_day', 'change', 'change_pct', 'series_epoch', 'lowest_listing', 'thin_market'])
        ->and($price['required'])->toBe(array_keys($price['properties']))
        ->and($price['properties']['lowest_listing']['type'])->toBe(['string', 'null'])
        ->and($price['properties']['thin_market']['type'])->toBe('boolean')
        ->and($variant['required'])->not->toContain('listing')
        ->and($listing['additionalProperties'])->toBeFalse()
        ->and(array_keys($listing['properties']))->toBe(['source', 'feed', 'currency', 'cadence', 'lowest_listing', 'day', 'as_of'])
        ->and($listing['required'])->toBe(array_keys($listing['properties']))
        ->and(array_keys($watched['properties']))->not->toContain('lowest_listing')
        ->and(array_keys($watched['properties']))->not->toContain('thin_market');
});

it('still answers 404 card-not-found for an id outside the pattern and 503 when PokéSearch is down with nothing cached', function (): void {
    t05tVariant('base1-4', 'base1-4:holo:1st:-', t05tThirteen('1000.00', '2500.00'));
    LatestPrices::rebuild(DB::connection());

    $notFound = $this->getJson('/v1/cards/'.rawurlencode('bad id!'));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'es down'], 503)]);
    $down = $this->getJson('/v1/cards/base1-4');

    expect($notFound->getStatusCode())->toBe(404)
        ->and($notFound->json('type'))->toEndWith('/card-not-found')
        ->and($down->getStatusCode())->toBe(503)
        ->and($down->json('type'))->toEndWith('/upstream-unavailable');
});
