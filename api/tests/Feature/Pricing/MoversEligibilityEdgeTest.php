<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PricePartitions;
use App\Http\Problems\ProblemDetails;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Edge cases that try to break the movers eligibility rule: the activity count pinned from both sides, the stored
// (rounded) percent on both sides of the rounding with the 1.00 floor at the same time, each close of a move held to
// its own observation and not to the other's, several observations of one day, month and year boundaries, a stale
// newest day, more eligible rows than the board keeps, and the HTTP answer for an empty direction. Every case writes its
// own card_variants, price_daily and price_points rows and calls Movers::rebuild().

/** The one ingest run the observations of a test hang on. */
function t04xRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->where('day', '2026-09-01')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-09-01',
        'marker' => '2026-09-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-09-01 20:10:00+00',
        'finished_at' => '2026-09-01 20:11:00+00']);
}

/** A money expression evaluated exactly by Postgres, as text with two decimals. */
function t04xMoney(string $expression, array $bindings): string
{
    return (string) DB::selectOne("SELECT ({$expression})::numeric(14,2)::text AS v", $bindings)->v;
}

/** Adds "<card>:normal:-:-" to card_variants unless it is there, and returns its variant_key. */
function t04xVariant(string $card): string
{
    $key = "{$card}:normal:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'normal', 'edition' => '-',
        'treatment' => '-', 'catalog_status' => 'unverified']);

    return $key;
}

/** One real price_daily close of $key on $day with as_of $asOf (default: the day at 20:05 UTC). */
function t04xDaily(string $key, string $day, string $close, ?string $asOf = null, int $epoch = 1): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 1, false, NULL, ?::timestamptz)
        SQL, [$key, $day, $epoch, $close, $close, $close, $close, $asOf ?? "{$day} 20:05:00+00"]);
}

/** One price_points observation of $key at $observedAt (UTC) with the given market, low and mid. */
function t04xPoint(string $key, string $observedAt, ?string $market, ?string $low, ?string $mid, int $epoch = 1): void
{
    PricePartitions::ensureFor(CarbonImmutable::parse($observedAt));
    DB::table('price_points')->insert(['variant_key' => $key, 'source' => 'tcgplayer', 'observed_at' => $observedAt,
        'day' => substr($observedAt, 0, 10), 'series_epoch' => $epoch, 'source_product_id' => 1, 'source_subtype' => 'Normal',
        'market' => $market, 'low' => $low, 'mid' => $mid, 'ingest_run_id' => t04xRun()]);
}

/**
 * Adds a series of real closes. A day maps to a close as a string (its observation lists low = 90 % and mid = 105 %
 * of it, truncated to cents), to [close, low, mid] (exactly these listings), or to null (a filled row repeating the
 * last real close). Returns the variant_key.
 *
 * @param  array<string, string|array{0: string, 1: string|null, 2: string|null}|null>  $days
 */
function t04xSeries(string $card, array $days, int $epoch = 1): string
{
    $key = t04xVariant($card);
    $last = null;
    foreach ($days as $day => $spec) {
        if ($spec === null) {
            DB::statement(<<<'SQL'
                INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
                VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 0, true, ?::date, ?::date + interval '20 hours 5 minutes')
                SQL, [$key, $day, $epoch, $last[1], $last[1], $last[1], $last[1], $last[0], $last[0]]);

            continue;
        }
        $close = is_array($spec) ? $spec[0] : $spec;
        $low = is_array($spec) ? $spec[1] : t04xMoney('trunc(?::numeric * 0.90, 2)', [$close]);
        $mid = is_array($spec) ? $spec[2] : t04xMoney('trunc(?::numeric * 1.05, 2)', [$close]);
        t04xDaily($key, $day, $close, null, $epoch);
        t04xPoint($key, "{$day} 20:05:00+00", $close, $low, $mid, $epoch);
        $last = [$day, $close];
    }

    return $key;
}

/** 2026-10-09 to 2026-10-13 with $closes in order; fewer closes end on 2026-10-13. */
function t04xWeek(string|array|null ...$closes): array
{
    $days = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];

    return array_combine(array_slice($days, count($days) - count($closes)), $closes);
}

/** @return list<string> the movers of $day in $direction by rank, as "card previous>close change pct previous_day" */
function t04xMovers(string $day, string $direction): array
{
    return array_map(
        fn (object $r): string => "{$r->card_id} {$r->previous_close}>{$r->close} {$r->change} {$r->change_pct} {$r->previous_day}",
        DB::select(<<<'SQL'
            SELECT card_id, close::text AS close, previous_close::text AS previous_close, previous_day::text AS previous_day,
                   change::text AS change, change_pct::text AS change_pct
            FROM price_movers WHERE day = ?::date AND direction = ? ORDER BY rank
            SQL, [$day, $direction]),
    );
}

/** @return list<string> every price_movers row with every column as text, in primary-key order */
function t04xDump(): array
{
    return array_map(
        fn (object $r): string => implode('|', (array) $r),
        DB::select(<<<'SQL'
            SELECT day::text, source, direction, rank::text, variant_key, card_id, series_epoch::text, close::text,
                   previous_close::text, previous_day::text, change::text, change_pct::text,
                   to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM price_movers ORDER BY day, source, direction, rank
            SQL),
    );
}

beforeEach(function (): void {
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
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

test('activity is four distinct of the five most recent real closes from both sides: three out, four in, a sixth close never makes the fourth', function (): void {
    // Changed on every day but shows three values (10, 11, 10, 11, 12.50): out, although it is never flat.
    t04xSeries('zxa-3of5-alt', t04xWeek('10.00', '11.00', '10.00', '11.00', '12.50'));
    // Four values among five closes that went back and forth once: in.
    t04xSeries('zxa-4of5-alt', t04xWeek('10.00', '11.00', '10.00', '11.50', '12.80'));
    // Four values among five closes with one unchanged day: in.
    t04xSeries('zxa-4of5-flat', t04xWeek('10.00', '10.00', '10.50', '11.50', '12.80'));
    // Six closes: only the oldest, outside the five, holds the fourth value. Out.
    t04xSeries('zxa-sixth', ['2026-10-08' => '9.00'] + t04xWeek('10.00', '10.00', '10.00', '11.50', '12.80'));
    // The close of the day returns to a price the series showed before: still four values, ranked as a fall.
    t04xSeries('zxa-return', t04xWeek('10.00', '11.00', '12.00', '13.00', '11.00'));
    // Four sparse closes, all distinct, the oldest exactly 14 days back: in.
    t04xSeries('zxa-sparse-edge', ['2026-09-29' => '10.00', '2026-10-01' => '10.50', '2026-10-09' => '11.00', '2026-10-13' => '12.10']);
    // The same with the oldest 15 days back: three closes in reach, out.
    t04xSeries('zxa-sparse-out', ['2026-09-28' => '10.00', '2026-10-01' => '10.50', '2026-10-09' => '11.00', '2026-10-13' => '12.10']);

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(t04xMovers('2026-10-13', 'up'))->toBe([
        'zxa-4of5-alt 11.50>12.80 1.30 11.30 2026-10-12',
        'zxa-4of5-flat 11.50>12.80 1.30 11.30 2026-10-12',
        'zxa-sparse-edge 11.00>12.10 1.10 10.00 2026-10-09',
    ])
        ->and(t04xMovers('2026-10-13', 'down'))->toBe(['zxa-return 13.00>11.00 -2.00 -15.38 2026-10-12']);
});

test('the material move tests the stored, rounded percent on both sides of the rounding and the 1.00 floor at the same time, up and down', function (): void {
    $series = function (string $card, string $previous, string $close): void {
        // Three earlier closes 0.30, 0.20 and 0.10 under the previous close: four distinct values, all in line.
        t04xSeries($card, t04xWeek(t04xMoney('?::numeric - 0.30', [$previous]), t04xMoney('?::numeric - 0.20', [$previous]),
            t04xMoney('?::numeric - 0.10', [$previous]), $previous, $close));
    };
    $series('zxr-up-at', '20.00', '21.00');      // +1.00, +5.00 % exactly
    $series('zxr-up-r1', '20.01', '21.01');      // +1.00, +4.9975 % stored as 5.00
    $series('zxr-up-r2', '20.02', '21.02');      // +1.00, +4.995005 % stored as 5.00
    $series('zxr-up-r3', '20.03', '21.03');      // +1.00, +4.9925 % stored as 4.99
    $series('zxr-up-cent', '20.00', '20.99');    // +0.99, +4.95 %
    $series('zxr-up-small', '19.80', '20.79');   // +0.99, +5.00 % exactly: the 1.00 floor alone refuses it
    $series('zxr-dn-big-in', '2000.00', '1900.10');   // -99.90, -4.995 % stored as -5.00
    $series('zxr-dn-big-out', '20000.00', '19001.01'); // -998.99, -4.99495 % stored as -4.99
    $series('zxr-dn-at', '20.00', '19.00');      // -1.00, -5.00 % exactly
    $series('zxr-dn-r1', '20.01', '19.01');      // -1.00, -4.9975 % stored as -5.00
    $series('zxr-dn-r3', '20.03', '19.03');      // -1.00, -4.9925 % stored as -4.99
    $series('zxr-dn-small', '19.80', '18.81');   // -0.99, -5.00 % exactly

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(t04xMovers('2026-10-13', 'up'))->toBe([
        'zxr-up-at 20.00>21.00 1.00 5.00 2026-10-12',
        'zxr-up-r1 20.01>21.01 1.00 5.00 2026-10-12',
        'zxr-up-r2 20.02>21.02 1.00 5.00 2026-10-12',
    ])
        ->and(t04xMovers('2026-10-13', 'down'))->toBe([
            'zxr-dn-big-in 2000.00>1900.10 -99.90 -5.00 2026-10-12',
            'zxr-dn-at 20.00>19.00 -1.00 -5.00 2026-10-12',
            'zxr-dn-r1 20.01>19.01 -1.00 -5.00 2026-10-12',
        ]);
});

test('each close of a move is held to its own observation, never to the other close\'s, on a fall as on a rise', function (): void {
    // A fall whose previous close is over twice its own mid: out.
    t04xSeries('zxl-fall-prev-out', t04xWeek('30.00', '30.50', '31.00', ['31.50', '28.35', '15.00'], '28.00'));
    // A fall whose close of the day is under half its own low: out.
    t04xSeries('zxl-fall-day-out', t04xWeek('30.00', '30.50', '31.00', '31.50', ['28.00', '60.00', '70.00']));
    // A rise whose previous close is under half its own low, the close of the day in line: out.
    t04xSeries('zxl-rise-prev-out', t04xWeek('10.00', '10.10', '10.20', ['10.30', '25.00', '30.00'], '11.40'));
    // A rise whose close of the day is under half its own low, the previous close in line: out.
    t04xSeries('zxl-rise-day-out', t04xWeek('10.00', '10.10', '10.20', '10.30', ['11.40', '30.00', '40.00']));
    // The previous close 31.50 is in line with its own listings (30.00, 33.00) but would be over twice the mid of the
    // day's observation (14.50); the day's 28.00 is exactly half its own low (56.00), a low above its mid. Ranked.
    t04xSeries('zxl-own-prev', t04xWeek('30.00', '30.50', '31.00', ['31.50', '30.00', '33.00'], ['28.00', '56.00', '14.50']));
    // The day's 28.00 is in line with its own listings but under half the low of the previous observation (63.00);
    // the previous close is exactly half that low. Ranked.
    t04xSeries('zxl-own-day', t04xWeek('30.00', '30.50', '31.00', ['31.50', '63.00', '20.00'], '28.00'));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(t04xMovers('2026-10-13', 'down'))->toBe([
        'zxl-own-day 31.50>28.00 -3.50 -11.11 2026-10-12',
        'zxl-own-prev 31.50>28.00 -3.50 -11.11 2026-10-12',
    ])
        ->and(t04xMovers('2026-10-13', 'up'))->toBe([]);
});

test('of several observations on the day the close is held to the one it came from, and a later observation with no market is none', function (): void {
    $history = ['2026-10-09' => '10.00', '2026-10-10' => '10.10', '2026-10-11' => '10.20', '2026-10-12' => '10.30'];
    // An earlier observation of the day lists far above the close; the close's own, at its as_of, is in line.
    $a = t04xSeries('zxm-own-in', $history);
    t04xPoint($a, '2026-10-13 08:00:00+00', '11.00', '40.00', '50.00');
    t04xPoint($a, '2026-10-13 20:05:00+00', '11.40', '10.26', '11.97');
    t04xDaily($a, '2026-10-13', '11.40', '2026-10-13 20:05:00+00');
    // The reverse: the earlier observation is in line, the close's own lists far above it.
    $b = t04xSeries('zxm-own-out', $history);
    t04xPoint($b, '2026-10-13 08:00:00+00', '11.40', '10.26', '11.97');
    t04xPoint($b, '2026-10-13 20:05:00+00', '11.40', '40.00', '50.00');
    t04xDaily($b, '2026-10-13', '11.40', '2026-10-13 20:05:00+00');
    // A later observation with no market (the feed gave listings only) is no observation: the close keeps its own.
    $c = t04xSeries('zxm-null-later', $history);
    t04xPoint($c, '2026-10-13 20:05:00+00', '11.40', '10.26', '11.97');
    t04xPoint($c, '2026-10-13 23:00:00+00', null, '40.00', '50.00');
    t04xDaily($c, '2026-10-13', '11.40', '2026-10-13 20:05:00+00');

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(t04xMovers('2026-10-13', 'up'))->toBe([
        'zxm-null-later 10.30>11.40 1.10 10.68 2026-10-12',
        'zxm-own-in 10.30>11.40 1.10 10.68 2026-10-12',
    ]);
});

test('the same close observed again later on its day is held to the later observation, and the day marker follows it', function (): void {
    $key = t04xSeries('zxq-again', ['2026-10-09' => '10.00', '2026-10-10' => '10.10', '2026-10-11' => '10.20', '2026-10-12' => '10.30', '2026-10-13' => '11.40']);
    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');
    $first = t04xDump();

    // A re-collection at 23:10 gives the same close with listings that put it far under half the low. The rollup then
    // stores the later as_of on the candle, and the rebuild holds the close to that observation.
    t04xPoint($key, '2026-10-13 23:10:00+00', '11.40', '40.00', '50.00');
    DB::table('price_daily')->where('variant_key', $key)->where('day', '2026-10-13')->update(['as_of' => '2026-10-13 23:10:00+00']);
    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');
    $outOfLine = t04xDump();

    // A third collection at 23:50 lists in line again.
    t04xPoint($key, '2026-10-13 23:50:00+00', '11.40', '10.26', '11.97');
    DB::table('price_daily')->where('variant_key', $key)->where('day', '2026-10-13')->update(['as_of' => '2026-10-13 23:50:00+00']);
    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect($first)->toBe(['2026-10-13|tcgplayer|up|1|zxq-again:normal:-:-|zxq-again|1|11.40|10.30|2026-10-12|1.10|10.68|2026-10-13T20:05:00Z'])
        ->and($outOfLine)->toBe([])
        ->and(t04xDump())->toBe(['2026-10-13|tcgplayer|up|1|zxq-again:normal:-:-|zxq-again|1|11.40|10.30|2026-10-12|1.10|10.68|2026-10-13T23:50:00Z']);
});

test('month and year boundaries rank like any other day, and one window, day by day backwards or split at the year give the same rows', function (): void {
    // Across the year, with 2026-12-31 missing: ranked on 2026-12-30 (its fourth value) and on 2027-01-01 against 12-30.
    t04xSeries('zxy-year', ['2026-12-27' => '50.00', '2026-12-28' => '53.00', '2026-12-29' => '56.50', '2026-12-30' => '60.00', '2027-01-01' => '64.00']);
    // Across a month: up on 2026-12-01, down on 2026-12-02.
    t04xSeries('zxy-month', ['2026-11-28' => '20.00', '2026-11-29' => '21.50', '2026-11-30' => '23.00', '2026-12-01' => '25.00', '2026-12-02' => '23.00']);
    // A flat series stepping on the first day of the year is not ranked.
    t04xSeries('zxy-flat', ['2026-12-28' => '40.00', '2026-12-29' => '40.00', '2026-12-30' => '40.00', '2026-12-31' => '40.00', '2027-01-01' => '48.00']);

    Movers::rebuild(DB::connection(), '2026-11-27', '2027-01-02');
    $one = t04xDump();

    DB::table('price_movers')->delete();
    $day = CarbonImmutable::parse('2027-01-02');
    while ($day->greaterThanOrEqualTo(CarbonImmutable::parse('2026-11-27'))) {
        Movers::rebuild(DB::connection(), $day->format('Y-m-d'), $day->format('Y-m-d'));
        $day = $day->subDay();
    }
    $backwards = t04xDump();

    DB::table('price_movers')->delete();
    Movers::rebuild(DB::connection(), '2027-01-01', '2027-01-02');
    Movers::rebuild(DB::connection(), '2026-11-27', '2026-12-31');
    $split = t04xDump();

    expect($one)->toBe([
        '2026-12-01|tcgplayer|up|1|zxy-month:normal:-:-|zxy-month|1|25.00|23.00|2026-11-30|2.00|8.70|2026-12-01T20:05:00Z',
        '2026-12-02|tcgplayer|down|1|zxy-month:normal:-:-|zxy-month|1|23.00|25.00|2026-12-01|-2.00|-8.00|2026-12-02T20:05:00Z',
        '2026-12-30|tcgplayer|up|1|zxy-year:normal:-:-|zxy-year|1|60.00|56.50|2026-12-29|3.50|6.19|2026-12-30T20:05:00Z',
        '2027-01-01|tcgplayer|up|1|zxy-year:normal:-:-|zxy-year|1|64.00|60.00|2026-12-30|4.00|6.67|2027-01-01T20:05:00Z',
    ])
        ->and($backwards)->toBe($one)
        ->and($split)->toBe($one);
});

test('a stale newest day with a handful of closes after a day of fills ranks the one real mover and none of the noise', function (): void {
    // Three active series; every one is filled on 10-12; only zxs-a has a real close on 10-13.
    foreach (['zxs-a', 'zxs-b', 'zxs-c'] as $card) {
        $days = ['2026-10-08' => '10.00', '2026-10-09' => '10.50', '2026-10-10' => '11.00', '2026-10-11' => '11.50', '2026-10-12' => null];
        if ($card === 'zxs-a') {
            $days['2026-10-13'] = '12.80';
        }
        t04xSeries($card, $days);
    }
    // Thirty flat series with one large step on 10-13: noise, never ranked.
    for ($i = 1; $i <= 30; $i++) {
        t04xSeries(sprintf('zxs-n%02d', $i), ['2026-10-09' => '10.00', '2026-10-10' => '10.00', '2026-10-11' => '10.00', '2026-10-12' => '10.00', '2026-10-13' => '15.00']);
    }

    Movers::rebuild(DB::connection(), '2026-10-12', '2026-10-13');

    expect(t04xDump())->toBe(['2026-10-13|tcgplayer|up|1|zxs-a:normal:-:-|zxs-a|1|12.80|11.50|2026-10-11|1.30|11.30|2026-10-13T20:05:00Z']);
});

test('more eligible rises than the board keeps leave the top 50 ranked 1 to 50, and an empty fall side is an empty list with its day, not an error', function (): void {
    // 53 active series, 20.00 to 21.00 + 0.10 i for i = 1..53: +5.50 % up to +31.50 %, every one eligible.
    for ($i = 1; $i <= 53; $i++) {
        t04xSeries(sprintf('zxk-%02d', $i), t04xWeek('19.70', '19.80', '19.90', '20.00', t04xMoney('21.00 + ?::integer * 0.10', [$i])));
    }

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    $up = $this->getJson('/v1/movers?direction=up&limit=50');
    $down = $this->getJson('/v1/movers?direction=down&limit=50');
    $bad = $this->getJson('/v1/movers?direction=sideways');

    $expected = [];
    for ($i = 53; $i >= 4; $i--) {
        $expected[] = sprintf('zxk-%02d', $i);
    }
    $up->assertOk();
    $down->assertOk();
    expect(DB::table('price_movers')->where('direction', 'up')->orderBy('rank')->pluck('card_id')->all())->toBe($expected)
        ->and(DB::table('price_movers')->where('direction', 'up')->orderBy('rank')->pluck('rank')->all())->toBe(range(1, 50))
        ->and(DB::table('price_movers')->where('direction', 'down')->count())->toBe(0)
        ->and(array_column($up->json('data'), 'card_id'))->toBe($expected)
        ->and($up->json('data.0'))->toMatchArray(['rank' => 1, 'close' => '26.30', 'previous_close' => '20.00', 'change' => '6.30', 'change_pct' => '31.50'])
        ->and($up->json('data.49'))->toMatchArray(['rank' => 50, 'close' => '21.40', 'change' => '1.40', 'change_pct' => '7.00'])
        ->and($down->json('data'))->toBe([])
        ->and($down->json('meta'))->toMatchArray(['day' => '2026-10-13', 'as_of' => '2026-10-13T20:05:00Z', 'direction' => 'down', 'source' => 'tcgplayer'])
        ->and($bad->getStatusCode())->toBe(422)
        ->and($bad->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($bad->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($bad->json('errors'))->toHaveKey('direction');
});
