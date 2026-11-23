<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PriceDigest;
use App\Domain\Pricing\PriceHistory;
use App\Domain\Pricing\PricePartitions;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Edge cases for the daily candles and the movers snapshot (plan P2-i1 T11, T12, D4, D7). Rows are inserted straight
// into card_variants and price_daily (one real close per variant and day) and Movers::rebuild() ranks them, so each
// case pins the exact input; two cases ingest the synthetic fixture days instead. A series the movers are meant to rank
// is written with edgeObservedVariant() and has four distinct closes: the movers rank a move of at least 5 % and 1.00
// in a series with four distinct values among its five most recent real closes, both closes in line with their listings.

/** Adds one variant "<card>:normal:-:-" and its real closes; $closes maps day => close. Returns the variant_key. */
function edgeVariant(string $card, array $closes, int $epoch = 1): string
{
    $key = "{$card}:normal:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'normal', 'edition' => '-',
        'treatment' => '-', 'catalog_status' => 'unverified']);
    foreach ($closes as $day => $close) {
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
            SQL, [$key, $day, $epoch, $close, $close, $close, $close, $day]);
    }

    return $key;
}

/**
 * Adds a variant as edgeVariant() does, each close with the observation it came from: a price_points row at the
 * candle's as_of that lists low = 90 % and mid = 105 % of the close, so the close is in line with its listings. The
 * movers rank a move only when both of its closes have one. The observations hang on one ok run of 2026-09-01, older
 * than every run edgeRun() adds.
 */
function edgeObservedVariant(string $card, array $closes, int $epoch = 1): string
{
    $key = edgeVariant($card, $closes, $epoch);
    $run = DB::table('ingest_runs')->where('day', '2026-09-01')->value('id')
        ?? DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-09-01', 'marker' => '2026-09-01 20:05:00+00',
            'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-09-01 20:10:00+00', 'finished_at' => '2026-09-01 20:11:00+00']);
    foreach (array_unique(array_map(fn (string $day): string => substr($day, 0, 7), array_keys($closes))) as $month) {
        PricePartitions::ensureFor(CarbonImmutable::parse("{$month}-01 00:00:00+00"));
    }
    foreach ($closes as $day => $close) {
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, ?, 1, 'Normal', ?::numeric,
                    trunc(?::numeric * 0.90, 2), trunc(?::numeric * 1.05, 2), NULL, NULL, ?)
            SQL, [$key, $day, $day, $epoch, $close, $close, $close, $run]);
    }

    return $key;
}

/** Inserts the ok run of $day, so the history window ends there. */
function edgeRun(string $day): void
{
    DB::table('ingest_runs')->insert(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $day.' 20:05:00+00',
        'mode' => 'sync', 'status' => 'ok', 'started_at' => $day.' 20:10:00+00', 'finished_at' => $day.' 20:11:00+00']);
}

/** @return list<object> the price_movers rows of $day in $direction by rank, money as text */
function edgeMovers(string $day, string $direction): array
{
    return DB::select(<<<'SQL'
        SELECT rank, variant_key, close::text AS close, previous_close::text AS previous_close, previous_day::text AS previous_day,
               change::text AS change, change_pct::text AS change_pct
        FROM price_movers WHERE day = ?::date AND direction = ? ORDER BY rank
        SQL, [$day, $direction]);
}

beforeEach(function (): void {
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
    // P3-i1 T08: the movers name their cards through the catalog; PokéSearch is faked for every fixture card id and
    // the catalog keys live under a prefix of this test's own, flushed when the application is torn down.
    $catalogPrefix = CatalogCache::isolate();
    $this->beforeApplicationDestroyed(fn () => CatalogCache::flush($catalogPrefix));
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::fake(function (Request $request) {
        if (parse_url($request->url(), PHP_URL_PATH) === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 1,
                'seed' => ['ref' => '0123456789abcdef0123456789abcdef01234567', 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'fake-meta']);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'name' => "Name of {$id}",
            'number' => substr((string) strrchr($id, '-'), 1), 'set_name' => 'Fixture set'], explode(',', (string) $query['ids'])), 'missing' => []]);
    });
});

test('51 or more variants tied on pct and change keep exactly 50 per direction, the first 50 in byte order of variant_key', function (): void {
    // 56 variants all +10 % / +$1.00 on 10-11 after three moving days, upper-case ids included: byte order puts "ZQT-99"
    // before every "zqt-".
    $cards = ['ZQT-99', 'zqt-aa'];
    for ($i = 1; $i <= 54; $i++) {
        $cards[] = sprintf('zqt-%02d', $i);
    }
    foreach ($cards as $card) {
        edgeObservedVariant($card, ['2026-10-08' => '9.80', '2026-10-09' => '9.90', '2026-10-10' => '10.00', '2026-10-11' => '11.00']);
    }
    $expected = array_map(fn (string $c): string => "{$c}:normal:-:-", $cards);
    sort($expected, SORT_STRING);
    $expected = array_slice($expected, 0, 50);

    Movers::rebuild(DB::connection(), '2026-10-10', '2026-10-11');
    $first = PriceDigest::movers(DB::connection());
    Movers::rebuild(DB::connection(), '2026-10-10', '2026-10-11');

    $up = edgeMovers('2026-10-11', 'up');
    expect(array_map(fn (object $r): string => $r->variant_key, $up))->toBe($expected)
        ->and(array_map(fn (object $r): int => $r->rank, $up))->toBe(range(1, 50))
        ->and(edgeMovers('2026-10-11', 'down'))->toBe([])
        ->and(PriceDigest::movers(DB::connection()))->toBe($first);

    $this->getJson('/v1/movers?limit=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('data.0.variant_key', 'ZQT-99:normal:-:-');
    $this->getJson('/v1/movers?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rank', 1);
});

test('a zero change is dropped before ranking and never trips the change check, whatever its neighbours', function (): void {
    // Every series moved on the three days before; only the last step differs.
    edgeObservedVariant('zqz-1', ['2026-10-08' => '9.80', '2026-10-09' => '9.90', '2026-10-10' => '10.00', '2026-10-11' => '10.00']);   // unchanged
    edgeObservedVariant('zqz-2', ['2026-10-08' => '9.80', '2026-10-09' => '9.90', '2026-10-10' => '10.00', '2026-10-11' => '11.00']);   // +10.00 %
    edgeObservedVariant('zqz-3', ['2026-10-08' => '10.20', '2026-10-09' => '10.10', '2026-10-10' => '10.00', '2026-10-11' => '9.00']);  // -10.00 %
    // +$0.01 and 0.00 % after rounding: a change that is not zero, and far too small to rank.
    edgeObservedVariant('zqz-4', ['2026-10-08' => '998.00', '2026-10-09' => '999.00', '2026-10-10' => '1000.00', '2026-10-11' => '1000.01']);

    Movers::rebuild(DB::connection(), '2026-10-10', '2026-10-11');

    $all = DB::table('price_movers')->where('day', '2026-10-11')->pluck('variant_key')->all();
    expect($all)->not->toContain('zqz-1:normal:-:-')
        ->and($all)->not->toContain('zqz-4:normal:-:-')
        ->and(DB::table('price_movers')->where('change', 0)->count())->toBe(0)
        ->and(array_map(fn (object $r): string => $r->variant_key, edgeMovers('2026-10-11', 'up')))->toBe(['zqz-2:normal:-:-'])
        ->and(array_map(fn (object $r): string => $r->variant_key, edgeMovers('2026-10-11', 'down')))->toBe(['zqz-3:normal:-:-']);
});

test('a close counts at exactly 14 days back and not at 15, and never when the closes are in different epochs', function (): void {
    // 10-11 reaches back to 09-27. A mover needs four distinct closes inside that reach, so the oldest previous close
    // a ranked move can have is 12 days back, with the 14-day edge deciding whether the fourth close counts.
    edgeObservedVariant('zqc-1', ['2026-09-27' => '9.80', '2026-09-28' => '9.90', '2026-09-29' => '10.00', '2026-10-11' => '12.00']); // 14 days
    edgeObservedVariant('zqc-2', ['2026-09-26' => '9.80', '2026-09-28' => '9.90', '2026-09-29' => '10.00', '2026-10-11' => '12.00']); // 15 days
    edgeObservedVariant('zqc-3', ['2026-09-27' => '9.80', '2026-09-28' => '9.90', '2026-09-29' => '10.00'], 1);
    edgeObservedVariant('zqc-3', ['2026-10-11' => '12.00'], 2);
    // Two closes exactly 14 days apart are still compared, but two closes are no activity: not ranked.
    edgeObservedVariant('zqc-4', ['2026-09-27' => '10.00', '2026-10-11' => '12.00']);

    Movers::rebuild(DB::connection(), '2026-10-11', '2026-10-11');

    $up = edgeMovers('2026-10-11', 'up');
    expect(array_map(fn (object $r): string => $r->variant_key, $up))->toBe(['zqc-1:normal:-:-'])
        ->and($up[0]->previous_day)->toBe('2026-09-29');
});

test('direction and pct math stay honest: 165.00 to 65.11 is down -99.89 / -60.54, a half-cent pct rounds away from zero, no variant sits in both directions', function (): void {
    edgeObservedVariant('zqd-1', ['2026-10-08' => '163.00', '2026-10-09' => '164.00', '2026-10-10' => '165.00', '2026-10-11' => '65.11']);
    edgeObservedVariant('zqd-2', ['2026-10-08' => '1998.00', '2026-10-09' => '1999.00', '2026-10-10' => '2000.00', '2026-10-11' => '1899.90']); // -5.005 % -> -5.01
    edgeObservedVariant('zqd-3', ['2026-10-08' => '1998.00', '2026-10-09' => '1999.00', '2026-10-10' => '2000.00', '2026-10-11' => '2100.10']); // +5.005 % -> +5.01
    edgeObservedVariant('zqd-4', ['2026-10-08' => '2.02', '2026-10-09' => '2.01', '2026-10-10' => '2.00', '2026-10-11' => '19999999.00']);

    Movers::rebuild(DB::connection(), '2026-10-10', '2026-10-11');

    $down = edgeMovers('2026-10-11', 'down');
    $up = edgeMovers('2026-10-11', 'up');
    $byKey = fn (array $rows): array => collect($rows)->mapWithKeys(fn (object $r): array => [$r->variant_key => [$r->change, $r->change_pct]])->all();
    expect($byKey($down))->toBe(['zqd-1:normal:-:-' => ['-99.89', '-60.54'], 'zqd-2:normal:-:-' => ['-100.10', '-5.01']])
        ->and($byKey($up)['zqd-3:normal:-:-'])->toBe(['100.10', '5.01'])
        ->and(array_keys($byKey($up))[0])->toBe('zqd-4:normal:-:-')
        ->and(DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM (SELECT day, variant_key FROM price_movers GROUP BY day, variant_key HAVING count(*) > 1) t
            SQL)->n)->toBe(0);
});

test('a day ingested last, anywhere in the order, leaves price_movers equal to a full rebuild of every day', function (): void {
    $this->seed(SetAliasSeeder::class);
    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots')]))->toBe(0);

    $orders = [['2026-10-14', '2026-10-12', '2026-10-10', '2026-10-09', '2026-10-11'], ['2026-10-14', '2026-10-11', '2026-10-09', '2026-10-12', '2026-10-10']];
    foreach ($orders as $days) {
        foreach (['price_movers', 'price_latest', 'price_daily', 'price_points', 'ingest_run_chunks', 'ingest_runs'] as $table) {
            DB::table($table)->delete();
        }
        foreach ($days as $day) {
            expect(Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true,
                '--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]))->toBe(0);
        }
        $incremental = PriceDigest::movers(DB::connection());
        DB::table('price_movers')->delete();
        Movers::rebuild(DB::connection(), '2026-10-01', '2026-10-31');

        expect($incremental['rows'])->toBeGreaterThan(0)
            ->and(PriceDigest::movers(DB::connection()))->toBe($incremental);
    }
});

test('/v1/movers answers 422 (never 500, never silently clamped) for every malformed limit or direction', function (string $query): void {
    $response = $this->getJson('/v1/movers?'.$query);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toContain('problem+json');
})->with([
    'limit 0' => 'limit=0',
    'limit 51' => 'limit=51',
    'limit negative' => 'limit=-1',
    'limit exponent' => 'limit=1e1',
    'limit fraction' => 'limit=5.0',
    'limit text' => 'limit=abc',
    'limit empty' => 'limit=',
    'limit beyond int64' => 'limit=99999999999999999999',
    'limit array' => 'limit[]=5',
    'direction upper case' => 'direction=UP',
    'direction empty' => 'direction=',
    'direction array' => 'direction[]=up',
    'direction sideways' => 'direction=flat',
]);

test('hostile card ids and ranges on the prices route answer 404 or 422, never 200 data [] and never 500', function (string $path, int $status): void {
    $response = $this->getJson($path);

    expect($response->getStatusCode())->toBe($status);
})->with([
    'path traversal' => ['/v1/cards/..%2F..%2Fetc%2Fpasswd/prices', 404],
    'dot dot' => ['/v1/cards/%2E%2E/prices', 404],
    'nul byte' => ['/v1/cards/ab%00cd/prices', 404],
    '129 characters' => ['/v1/cards/'.str_repeat('a', 129).'/prices', 404],
    'space' => ['/v1/cards/a%20b/prices', 404],
    'unicode' => ['/v1/cards/%C3%A9cole/prices', 404],
    'range list' => ['/v1/cards/base1-4/prices?range=1W,1M', 422],
    'range lower-case ytd' => ['/v1/cards/base1-4/prices?range=ytd', 422],
    'range 1y with a unit' => ['/v1/cards/base1-4/prices?range=1Y%20%20x', 422],
]);

test('the longest valid id (128 characters) answers 200 with data [], and a range wider than the data returns all of it', function (): void {
    $id = 'a'.str_repeat('b', 127);
    $this->getJson("/v1/cards/{$id}/prices")->assertOk()->assertExactJson(['data' => [], 'meta' => [
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
        'range' => '1M', 'from' => null, 'to' => null, 'as_of' => null,
    ]]);

    edgeVariant('zqw-1', ['2026-10-10' => '5.00', '2026-10-11' => '6.00']);
    edgeRun('2026-10-11');
    $response = $this->getJson('/v1/cards/zqw-1/prices?range=1Y')->assertOk();
    expect($response->json('meta.from'))->toBe('2025-10-12')
        ->and(array_column($response->json('data.0.points'), 'day'))->toBe(['2026-10-10', '2026-10-11']);
});

test('ranges cross the year boundary and leap days on the data day: YTD starts at January 1, 1W..1Y count back from to', function (): void {
    foreach ([
        ['2026-01-03', 'YTD', '2026-01-01'], ['2026-01-01', 'YTD', '2026-01-01'], ['2025-12-31', 'YTD', '2025-01-01'],
        ['2024-12-31', 'YTD', '2024-01-01'], ['2026-01-03', '1W', '2025-12-28'], ['2026-01-03', '1M', '2025-12-05'],
        ['2026-01-03', '3M', '2025-10-06'], ['2025-12-31', '1Y', '2025-01-01'], ['2024-12-31', '1Y', '2024-01-02'],
        ['2024-02-29', '1Y', '2023-03-02'], ['2024-03-01', '1W', '2024-02-24'],
    ] as [$to, $range, $from]) {
        expect(PriceHistory::from($to, $range))->toBe($from, "{$range} ending {$to}");
    }

    $days = [];
    foreach (range(0, 14) as $i) {
        $days[date('Y-m-d', strtotime("2025-12-20 +{$i} days"))] = '1.00';
    }
    edgeVariant('zqy-1', $days);
    edgeRun('2026-01-03');
    $pointsOf = fn (string $range): array => array_column($this->getJson("/v1/cards/zqy-1/prices?range={$range}")->assertOk()->json('data.0.points'), 'day');

    expect($pointsOf('YTD'))->toBe(['2026-01-01', '2026-01-02', '2026-01-03'])
        ->and($pointsOf('1W'))->toBe(['2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31', '2026-01-01', '2026-01-02', '2026-01-03'])
        ->and(count($pointsOf('1M')))->toBe(15);
});

test('ETags differ per range and per movers day, and a stale validator after a new day gets a fresh 200', function (): void {
    edgeObservedVariant('zqe-1', ['2026-10-07' => '9.80', '2026-10-08' => '9.90', '2026-10-09' => '10.00', '2026-10-10' => '11.00']);
    edgeRun('2026-10-10');
    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-10');

    $w = $this->getJson('/v1/cards/zqe-1/prices?range=1W')->headers->get('ETag');
    $m = $this->getJson('/v1/cards/zqe-1/prices?range=1M')->headers->get('ETag');
    $up = $this->getJson('/v1/movers')->headers->get('ETag');
    $down = $this->getJson('/v1/movers?direction=down')->headers->get('ETag');
    expect($w)->not->toBe($m)->and($up)->not->toBe($down)
        ->and($this->getJson('/v1/cards/zqe-1/prices?range=1W', ['If-None-Match' => $m])->getStatusCode())->toBe(200)
        ->and($this->getJson('/v1/movers', ['If-None-Match' => $up])->getStatusCode())->toBe(304);

    edgeObservedVariant('zqe-1', ['2026-10-11' => '12.10']);
    edgeRun('2026-10-11');
    Movers::rebuild(DB::connection(), '2026-10-11', '2026-10-11');
    $freshPrices = $this->getJson('/v1/cards/zqe-1/prices?range=1W', ['If-None-Match' => $w]);
    $freshMovers = $this->getJson('/v1/movers', ['If-None-Match' => $up]);

    expect($freshPrices->getStatusCode())->toBe(200)->and($freshPrices->headers->get('ETag'))->not->toBe($w)
        ->and($freshMovers->getStatusCode())->toBe(200)->and($freshMovers->json('meta.day'))->toBe('2026-10-11')
        ->and($freshMovers->headers->get('ETag'))->not->toBe($up);
});

test('the 50 in the movers validation, the OpenAPI maximum and config pricing.movers.limit are one number, and the CHECKs are exact', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $limit = collect($spec['paths']['/movers']['get']['parameters'])->firstWhere('name', 'limit');
    $checks = collect(DB::select(<<<'SQL'
        SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'price_movers'::regclass AND contype = 'c'
        SQL))->pluck('def', 'conname')->all();

    expect($limit['schema']['maximum'])->toBe((int) config('pricing.movers.limit'))
        ->and($checks['price_movers_change_check'])->toBe('CHECK ((change <> (0)::numeric))')
        ->and($checks['price_movers_change_direction_check'])->toBe("CHECK (((direction = 'up'::text) = (change > (0)::numeric)))")
        ->and($checks)->toHaveCount(5);
});
