<?php

declare(strict_types=1);

use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PriceDigest;
use Illuminate\Support\Facades\DB;

// Which moves the movers board ranks. A move of a real close against its previous close is ranked only when the previous
// close is at least 2.00, the move is at least 5.00 % (as stored, two decimals) and at least 1.00, the series shows
// recent price activity (of its five most recent real closes in the epoch, none older than 14 days, at least four are
// distinct values) and both closes are in line with the low and mid listings of their own observation (no lower than
// half the low listing, no higher than 1.5 x the mid listing, both listings present and above zero).
//
// Every case writes its own card_variants, price_points and price_daily rows and calls Movers::rebuild(), so each pins
// the exact input. A close given as a string gets listings in line with it (low 90 %, mid 105 % of the close).

/** The one ingest run the observations of a test hang on. */
function eligibilityRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->where('mode', 'sync')->orderBy('id')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-01',
        'marker' => '2026-10-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-10-01 20:10:00+00',
        'finished_at' => '2026-10-01 20:11:00+00']);
}

/**
 * Adds the variant "<card>:normal:-:-" with one row per day of $days and returns its variant_key. A day maps to:
 *
 * - a close as a string: a real close whose observation lists low = 90 % and mid = 105 % of it;
 * - [close, low, mid]: a real close with exactly these listings (null for a listing the feed did not give);
 * - [close]: a real close whose observation row is absent;
 * - null: a filled row repeating the last real close before it.
 *
 * A day that is not named has no row at all.
 *
 * @param  array<string, string|array{0: string, 1?: string|null, 2?: string|null}|null>  $days
 */
function eligibilitySeries(string $card, array $days, int $epoch = 1): string
{
    $key = "{$card}:normal:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'normal', 'edition' => '-',
        'treatment' => '-', 'catalog_status' => 'unverified']);
    $run = eligibilityRun();
    $last = null;
    foreach ($days as $day => $spec) {
        if ($spec === null) {
            if ($last === null) {
                throw new LogicException("{$card}: a filled row on {$day} needs a real close before it");
            }
            DB::statement(<<<'SQL'
                INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
                VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 0, true, ?::date, ?::date + interval '20 hours 5 minutes')
                SQL, [$key, $day, $epoch, $last[1], $last[1], $last[1], $last[1], $last[0], $last[0]]);

            continue;
        }
        $close = is_array($spec) ? $spec[0] : $spec;
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
            SQL, [$key, $day, $epoch, $close, $close, $close, $close, $day]);
        $last = [$day, $close];
        if (is_array($spec) && count($spec) === 1) {
            continue;
        }
        [$low, $mid] = is_array($spec) ? [$spec[1] ?? null, $spec[2] ?? null] : [null, null];
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, ?, 1, 'Normal', ?::numeric,
                    CASE WHEN ?::boolean THEN trunc(?::numeric * 0.90, 2) ELSE ?::numeric END,
                    CASE WHEN ?::boolean THEN trunc(?::numeric * 1.05, 2) ELSE ?::numeric END,
                    NULL, NULL, ?)
            SQL, [$key, $day, $day, $epoch, $close,
            is_array($spec) ? 'false' : 'true', $close, $low,
            is_array($spec) ? 'false' : 'true', $close, $mid,
            $run]);
    }

    return $key;
}

/** @return list<string> the movers of $day in $direction by rank, as "card previous_close>close change change_pct previous_day" */
function eligibilityMovers(string $day, string $direction): array
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
function eligibilityDump(): array
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

/**
 * The five days 2026-10-09 to 2026-10-13 with $closes in order (each as eligibilitySeries() reads a day); fewer closes
 * end on 2026-10-13.
 */
function eligibilityWeek(string|array|null ...$closes): array
{
    $days = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];

    return array_combine(array_slice($days, count($days) - count($closes)), $closes);
}

test('a series flat on every earlier day that steps once is not ranked, beside a series that kept moving', function (): void {
    eligibilitySeries('zea-flat', eligibilityWeek('10.00', '10.00', '10.00', '10.00', '15.00'));
    eligibilitySeries('zea-fall', eligibilityWeek('10.00', '10.00', '10.00', '10.00', '5.00'));
    eligibilitySeries('zea-moving', eligibilityWeek('10.00', '10.10', '10.20', '10.30', '11.40'));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zea-moving 10.30>11.40 1.10 10.68 2026-10-12'])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe([])
        ->and(DB::table('price_movers')->count())->toBe(1);
});

test('a spike that comes back the next day is ranked on neither day', function (): void {
    eligibilitySeries('zeb-spike', eligibilityWeek('10.00', '10.00', '10.00', '15.00', '10.00'));
    eligibilitySeries('zeb-dip', eligibilityWeek('10.00', '10.00', '10.00', '5.00', '10.00'));
    // The control moves on both days: +9.52 % then +10.87 %.
    eligibilitySeries('zeb-moving', eligibilityWeek('20.00', '20.50', '21.00', '23.00', '25.50'));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-12', 'up'))->toBe(['zeb-moving 21.00>23.00 2.00 9.52 2026-10-11'])
        ->and(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeb-moving 23.00>25.50 2.50 10.87 2026-10-12'])
        ->and(eligibilityMovers('2026-10-12', 'down'))->toBe([])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe([]);
});

test('an odd sale inside a busy series is ranked neither on the day it lands nor on the way back', function (): void {
    // 30.00 against listings of 9.00 low and 10.80 mid is more than twice the mid: out of line as the close of 10-13
    // and as the previous close of 10-14, while the series itself has five distinct closes on both days.
    eligibilitySeries('zeb-odd', ['2026-10-09' => '10.00', '2026-10-10' => '10.10', '2026-10-11' => '10.20', '2026-10-12' => '10.30',
        '2026-10-13' => ['30.00', '9.00', '10.80'], '2026-10-14' => '10.40']);
    eligibilitySeries('zeb-busy', ['2026-10-09' => '20.00', '2026-10-10' => '20.50', '2026-10-11' => '21.00', '2026-10-12' => '23.00',
        '2026-10-13' => '25.50', '2026-10-14' => '23.00']);

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-14');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeb-busy 23.00>25.50 2.50 10.87 2026-10-12'])
        ->and(eligibilityMovers('2026-10-14', 'down'))->toBe(['zeb-busy 25.50>23.00 -2.50 -9.80 2026-10-13'])
        ->and(eligibilityMovers('2026-10-14', 'up'))->toBe([])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe([]);
});

test('a day without a real close is no observation: it makes no quiet series active and hides no mover whose activity sits either side of it', function (): void {
    // Quiet either side of the gap: two distinct closes, whether the day is missing or filled.
    eligibilitySeries('zec-quiet-missing', ['2026-10-08' => '10.00', '2026-10-09' => '10.00', '2026-10-11' => '10.00', '2026-10-12' => '10.00', '2026-10-13' => '12.00']);
    eligibilitySeries('zec-quiet-filled', ['2026-10-08' => '10.00', '2026-10-09' => '10.00', '2026-10-10' => null, '2026-10-11' => '10.00', '2026-10-12' => '10.00', '2026-10-13' => '12.00']);
    // One distinct close short (9, 10, 10, 12): the two filled days add neither a close nor a change.
    eligibilitySeries('zec-short-filled', ['2026-10-08' => '9.00', '2026-10-09' => '10.00', '2026-10-10' => null, '2026-10-11' => null, '2026-10-12' => '10.00', '2026-10-13' => '12.00']);
    // Moving either side of a two-day gap (8, 9, 10, gap, 11, 12): ranked on 10-12 against the close before the gap, and on 10-13.
    eligibilitySeries('zec-real-missing', ['2026-10-07' => '8.00', '2026-10-08' => '9.00', '2026-10-09' => '10.00', '2026-10-12' => '11.00', '2026-10-13' => '12.00']);
    eligibilitySeries('zec-real-filled', ['2026-10-07' => '8.00', '2026-10-08' => '9.00', '2026-10-09' => '10.00', '2026-10-10' => null, '2026-10-11' => null, '2026-10-12' => '11.00', '2026-10-13' => '12.00']);
    // The move itself spans the gap: 10.00 on 10-10, filled twice, 12.00 on 10-13.
    eligibilitySeries('zec-span', ['2026-10-08' => '8.00', '2026-10-09' => '9.00', '2026-10-10' => '10.00', '2026-10-11' => null, '2026-10-12' => null, '2026-10-13' => '12.00']);

    Movers::rebuild(DB::connection(), '2026-10-07', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zec-span 10.00>12.00 2.00 20.00 2026-10-10',
        'zec-real-filled 11.00>12.00 1.00 9.09 2026-10-12',
        'zec-real-missing 11.00>12.00 1.00 9.09 2026-10-12',
    ])
        ->and(eligibilityMovers('2026-10-12', 'up'))->toBe([
            'zec-real-filled 10.00>11.00 1.00 10.00 2026-10-09',
            'zec-real-missing 10.00>11.00 1.00 10.00 2026-10-09',
        ])
        // No filled day ranks, and nothing else does.
        ->and(DB::table('price_movers')->count())->toBe(5);
});

test('a filled row is never ranked, even one whose candle differs from the close it repeats', function (): void {
    // The rollup always repeats the real close in a filled row; the table's checks alone would let one differ. Here
    // the filled candle of 10-14 holds 12.50 after four moving days, in line with the observation it points at.
    $key = eligibilitySeries('zec-filled-odd', eligibilityWeek('8.00', '9.00', '10.00', '11.00'));
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', '2026-10-14', 1, 12.50, 12.50, 12.50, 12.50, 0, true, '2026-10-13', '2026-10-13'::date + interval '20 hours 5 minutes')
        SQL, [$key]);
    // The same step as a real close is ranked; one step after a flat line is not.
    eligibilitySeries('zec-filled-real', ['2026-10-10' => '8.00', '2026-10-11' => '9.00', '2026-10-12' => '10.00', '2026-10-13' => '11.00', '2026-10-14' => '12.50']);
    eligibilitySeries('zec-filled-flat', ['2026-10-10' => '11.00', '2026-10-11' => '11.00', '2026-10-12' => '11.00', '2026-10-13' => '11.00', '2026-10-14' => '12.50']);

    Movers::rebuild(DB::connection(), '2026-10-14', '2026-10-14');

    expect(eligibilityMovers('2026-10-14', 'up'))->toBe(['zec-filled-real 11.00>12.50 1.50 13.64 2026-10-13'])
        ->and(DB::table('price_movers')->count())->toBe(1);
});

test('activity before a series-epoch change does not count after it, and no move crosses the change', function (): void {
    // Epoch 1 moved for three days; epoch 2 has two closes: 11.00 > 12.00 is +9.09 % and +1.00 but its epoch shows two closes.
    eligibilitySeries('zed-split', ['2026-10-09' => '8.00', '2026-10-10' => '9.00', '2026-10-11' => '10.00'], 1);
    eligibilitySeries('zed-split', ['2026-10-12' => '11.00', '2026-10-13' => '12.00'], 2);
    // The same closes in one epoch rank on both days.
    eligibilitySeries('zed-whole', eligibilityWeek('8.00', '9.00', '10.00', '11.00', '12.00'));
    // Four days of activity, then a new epoch: the first close of the epoch has no previous close.
    eligibilitySeries('zed-first', ['2026-10-09' => '8.00', '2026-10-10' => '9.00', '2026-10-11' => '10.00', '2026-10-12' => '11.00'], 1);
    eligibilitySeries('zed-first', ['2026-10-13' => '12.10'], 2);
    // An epoch that has shown its own activity ranks, whatever the older epoch held.
    eligibilitySeries('zed-own', ['2026-10-05' => '50.00'], 1);
    eligibilitySeries('zed-own', eligibilityWeek('8.00', '9.00', '10.00', '11.00', '12.00'), 2);
    // One day of another epoch in between: 10-13 is compared with 10-11, its own epoch, never with the 50.00 of 10-12.
    eligibilitySeries('zed-between', ['2026-10-08' => '8.00', '2026-10-09' => '9.00', '2026-10-10' => '10.00', '2026-10-11' => '11.00', '2026-10-13' => '12.00'], 1);
    eligibilitySeries('zed-between', ['2026-10-12' => '50.00'], 2);

    Movers::rebuild(DB::connection(), '2026-10-05', '2026-10-13');

    expect(eligibilityMovers('2026-10-11', 'up'))->toBe(['zed-between 10.00>11.00 1.00 10.00 2026-10-10'])
        ->and(eligibilityMovers('2026-10-12', 'up'))->toBe([
            'zed-first 10.00>11.00 1.00 10.00 2026-10-11',
            'zed-own 10.00>11.00 1.00 10.00 2026-10-11',
            'zed-whole 10.00>11.00 1.00 10.00 2026-10-11',
        ])
        ->and(eligibilityMovers('2026-10-13', 'up'))->toBe([
            'zed-between 11.00>12.00 1.00 9.09 2026-10-11',
            'zed-own 11.00>12.00 1.00 9.09 2026-10-12',
            'zed-whole 11.00>12.00 1.00 9.09 2026-10-12',
        ])
        ->and(DB::table('price_movers')->where('card_id', 'zed-split')->count())->toBe(0)
        ->and(DB::table('price_movers')->where('direction', 'down')->count())->toBe(0)
        ->and(DB::table('price_movers')->where('variant_key', 'zed-own:normal:-:-')->pluck('series_epoch')->unique()->all())->toBe([2]);
});

test('a close is in line from half its low listing to 1.5 x its mid listing, both bounds included, one cent outside is not', function (): void {
    // Above: 15.00 against a mid of 10.00 is exactly 1.5 x it; 15.01 is one cent over.
    eligibilitySeries('zee-mid-at', eligibilityWeek('10.00', '10.10', '10.20', '10.30', ['15.00', '9.00', '10.00']));
    eligibilitySeries('zee-mid-over', eligibilityWeek('10.00', '10.10', '10.20', '10.30', ['15.01', '9.00', '10.00']));
    // Below: 20000.00 against a low of 40000.00 is exactly half of it; 19999.99 is one cent under.
    eligibilitySeries('zee-low-at', eligibilityWeek('40000.00', '40400.00', '40800.00', '41200.00', ['20000.00', '40000.00', '60000.00']));
    eligibilitySeries('zee-low-under', eligibilityWeek('40000.00', '40400.00', '40800.00', '41200.00', ['19999.99', '40000.00', '60000.00']));
    // Far out, as a single odd sale looks: 752.00 against listings of 48.11 and 81.22.
    eligibilitySeries('zee-far', eligibilityWeek('99.00', '99.50', '99.99', '100.50', ['752.00', '48.11', '81.22']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zee-mid-at 10.30>15.00 4.70 45.63 2026-10-12'])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe(['zee-low-at 41200.00>20000.00 -21200.00 -51.46 2026-10-12']);
});

test('the previous close is held to its own listings too: a move away from an odd price is not ranked', function (): void {
    // 10.30 on 10-12 against a low of 20.61 is under half of it (10.305); against 20.60 it is exactly half.
    eligibilitySeries('zee-prev-under', eligibilityWeek('10.00', '10.10', '10.20', ['10.30', '20.61', '30.00'], '11.40'));
    eligibilitySeries('zee-prev-at', eligibilityWeek('10.00', '10.10', '10.20', ['10.30', '20.60', '30.00'], '11.40'));
    // 10.30 against a mid of 6.86 is over 1.5 x it (10.29); against 6.87 it is under 1.5 x (10.305).
    eligibilitySeries('zee-prev-over', eligibilityWeek('10.00', '10.10', '10.20', ['10.30', '1.00', '6.86'], '11.40'));
    eligibilitySeries('zee-prev-top', eligibilityWeek('10.00', '10.10', '10.20', ['10.30', '1.00', '6.87'], '11.40'));
    // The stored shape of a correction: 5.00 while the cheapest listing asked 549.99, then 752.00 in line with it.
    eligibilitySeries('zee-correction', eligibilityWeek('4.70', '4.80', '4.90', ['5.00', '549.99', '1498.99'], ['752.00', '549.99', '1498.99']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zee-prev-at 10.30>11.40 1.10 10.68 2026-10-12',
        'zee-prev-top 10.30>11.40 1.10 10.68 2026-10-12',
    ])
        ->and(DB::table('price_movers')->count())->toBe(2);
});

test('a close whose listings are missing, null or zero cannot be shown to be in line and is not ranked', function (array $day, array $previous): void {
    eligibilitySeries('zee-unknown', eligibilityWeek('10.00', '10.10', '10.20', $previous, $day));
    eligibilitySeries('zee-known', eligibilityWeek('10.00', '10.10', '10.20', ['10.30', '9.27', '10.81'], ['11.40', '10.26', '11.97']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zee-known 10.30>11.40 1.10 10.68 2026-10-12'])
        ->and(DB::table('price_movers')->count())->toBe(1);
})->with([
    'the low of the day is null' => [['11.40', null, '11.97'], ['10.30', '9.27', '10.81']],
    'the mid of the day is null' => [['11.40', '10.26', null], ['10.30', '9.27', '10.81']],
    'both listings of the day are null' => [['11.40', null, null], ['10.30', '9.27', '10.81']],
    'the low of the day is 0.00' => [['11.40', '0.00', '11.97'], ['10.30', '9.27', '10.81']],
    'the mid of the day is 0.00' => [['11.40', '10.26', '0.00'], ['10.30', '9.27', '10.81']],
    'the day has no observation row' => [['11.40'], ['10.30', '9.27', '10.81']],
    'the low of the previous close is null' => [['11.40', '10.26', '11.97'], ['10.30', null, '10.81']],
    'the mid of the previous close is null' => [['11.40', '10.26', '11.97'], ['10.30', '9.27', null]],
    'the low of the previous close is 0.00' => [['11.40', '10.26', '11.97'], ['10.30', '0.00', '10.81']],
    'the mid of the previous close is 0.00' => [['11.40', '10.26', '11.97'], ['10.30', '9.27', '0.00']],
    'the previous close has no observation row' => [['11.40', '10.26', '11.97'], ['10.30']],
]);

test('real movers are ranked by percent, then by the change, then by variant_key, and nothing else is on the board', function (): void {
    eligibilitySeries('zef-d', eligibilityWeek('27.00', '28.00', '29.00', '30.00', '36.00'));   // +20.00 %
    eligibilitySeries('zef-a', eligibilityWeek('19.00', '19.50', '19.75', '20.00', '22.00'));   // +10.00 %, +2.00
    eligibilitySeries('zef-c', eligibilityWeek('9.00', '9.50', '9.75', '10.00', '11.00'));      // +10.00 %, +1.00
    eligibilitySeries('zef-b', eligibilityWeek('9.00', '9.50', '9.75', '10.00', '11.00'));      // +10.00 %, +1.00
    eligibilitySeries('zef-g', eligibilityWeek('9.00', '9.50', '9.75', '10.00', '8.00'));       // -20.00 %
    eligibilitySeries('zef-f', eligibilityWeek('100.00', '101.00', '102.00', '103.00', '92.70')); // -10.00 %, -10.30
    eligibilitySeries('zef-e', eligibilityWeek('50.00', '51.00', '52.00', '53.00', '47.70'));   // -10.00 %, -5.30
    // Not ranked: one step after a flat line (+200 %), a move under 5 % (+0.97 %), a close far over its mid listing
    // (+288 %), and a previous close under 2.00 (+66.67 %).
    eligibilitySeries('zef-flat', eligibilityWeek('10.00', '10.00', '10.00', '10.00', '30.00'));
    eligibilitySeries('zef-small', eligibilityWeek('100.00', '101.00', '102.00', '103.00', '104.00'));
    eligibilitySeries('zef-odd', eligibilityWeek('10.00', '10.10', '10.20', '10.30', ['40.00', '9.00', '10.00']));
    eligibilitySeries('zef-cheap', eligibilityWeek('1.50', '1.60', '1.70', '1.80', '3.00'));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zef-d 30.00>36.00 6.00 20.00 2026-10-12',
        'zef-a 20.00>22.00 2.00 10.00 2026-10-12',
        'zef-b 10.00>11.00 1.00 10.00 2026-10-12',
        'zef-c 10.00>11.00 1.00 10.00 2026-10-12',
    ])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe([
            'zef-g 10.00>8.00 -2.00 -20.00 2026-10-12',
            'zef-f 103.00>92.70 -10.30 -10.00 2026-10-12',
            'zef-e 53.00>47.70 -5.30 -10.00 2026-10-12',
        ])
        ->and(DB::table('price_movers')->where('day', '2026-10-13')->where('direction', 'up')->orderBy('rank')->pluck('rank')->all())->toBe([1, 2, 3, 4])
        ->and(DB::table('price_movers')->where('day', '2026-10-13')->where('direction', 'down')->orderBy('rank')->pluck('rank')->all())->toBe([1, 2, 3]);
});

test('a move of exactly 1.00 counts and one of 0.99 does not, up and down', function (): void {
    eligibilitySeries('zeg-abs-at', eligibilityWeek('9.70', '9.80', '9.90', '10.00', '11.00'));       // +1.00, +10.00 %
    eligibilitySeries('zeg-abs-under', eligibilityWeek('9.70', '9.80', '9.90', '10.00', '10.99'));    // +0.99, +9.90 %
    eligibilitySeries('zeg-abs-at-down', eligibilityWeek('10.30', '10.20', '10.10', '10.00', '9.00')); // -1.00, -10.00 %
    eligibilitySeries('zeg-abs-under-down', eligibilityWeek('10.30', '10.20', '10.10', '10.00', '9.01')); // -0.99, -9.90 %

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeg-abs-at 10.00>11.00 1.00 10.00 2026-10-12'])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe(['zeg-abs-at-down 10.00>9.00 -1.00 -10.00 2026-10-12']);
});

test('a move of exactly 5.00 % as stored counts and one of 4.99 % does not, up and down, a half cent of percent rounding away from zero', function (): void {
    eligibilitySeries('zeg-pct-at', eligibilityWeek('9700.00', '9800.00', '9900.00', '10000.00', '10500.00'));     // +5.00 %
    eligibilitySeries('zeg-pct-under', eligibilityWeek('9700.00', '9800.00', '9900.00', '10000.00', '10499.00'));  // +4.99 %
    eligibilitySeries('zeg-pct-at-down', eligibilityWeek('9700.00', '9800.00', '9900.00', '10000.00', '9500.00')); // -5.00 %
    eligibilitySeries('zeg-pct-under-down', eligibilityWeek('9700.00', '9800.00', '9900.00', '10000.00', '9501.00')); // -4.99 %
    // 4.995 % is stored as 5.00 and counts; 4.99495 % is stored as 4.99 and does not.
    eligibilitySeries('zeg-pct-round-up', eligibilityWeek('1700.00', '1800.00', '1900.00', '2000.00', '2099.90'));
    eligibilitySeries('zeg-pct-round-down', eligibilityWeek('17000.00', '18000.00', '19000.00', '20000.00', '20998.99'));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zeg-pct-at 10000.00>10500.00 500.00 5.00 2026-10-12',
        'zeg-pct-round-up 2000.00>2099.90 99.90 5.00 2026-10-12',
    ])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe(['zeg-pct-at-down 10000.00>9500.00 -500.00 -5.00 2026-10-12']);
});

test('activity is four distinct values among the five most recent real closes: four counts, three does not', function (): void {
    // Exactly four distinct of five, and exactly three.
    eligibilitySeries('zeg-four-of-five', eligibilityWeek('10.00', '10.00', '10.10', '10.20', '11.30'));
    eligibilitySeries('zeg-three-of-five', eligibilityWeek('10.00', '10.00', '10.00', '10.20', '11.30'));
    // Only four real closes exist and all differ; only three exist and all differ.
    eligibilitySeries('zeg-four-of-four', eligibilityWeek('10.00', '10.10', '10.20', '11.30'));
    eligibilitySeries('zeg-three-of-three', eligibilityWeek('10.10', '10.20', '11.30'));
    // A sixth close back holds a fourth value, but only the five most recent are looked at.
    eligibilitySeries('zeg-sixth-back', ['2026-10-08' => '9.00'] + eligibilityWeek('10.00', '10.00', '10.00', '10.20', '11.30'));
    // Of six closes the five most recent hold four values, though the five oldest hold three.
    eligibilitySeries('zeg-recent-five', ['2026-10-08' => '10.00'] + eligibilityWeek('10.00', '10.00', '10.10', '10.20', '11.30'));
    // A price the series was at two days earlier is no new value: it changed every day and shows three values.
    eligibilitySeries('zeg-back-and-forth', eligibilityWeek('10.00', '10.50', '10.00', '10.50', '11.60'));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zeg-four-of-five 10.20>11.30 1.10 10.78 2026-10-12',
        'zeg-four-of-four 10.20>11.30 1.10 10.78 2026-10-12',
        'zeg-recent-five 10.20>11.30 1.10 10.78 2026-10-12',
    ]);
});

test('activity reaches 14 days back and no further: a close 14 days before the day counts, one 15 days before does not', function (): void {
    // 2026-10-20 looks back to 2026-10-06. Four real closes inside the reach, the previous close 12 days back.
    eligibilitySeries('zeg-reach-in', ['2026-10-06' => '9.80', '2026-10-07' => '9.90', '2026-10-08' => '10.00', '2026-10-20' => '11.10']);
    // The fourth value sits 15 days back: three closes inside the reach.
    eligibilitySeries('zeg-reach-out', ['2026-10-05' => '9.80', '2026-10-07' => '9.90', '2026-10-08' => '10.00', '2026-10-20' => '11.10']);

    Movers::rebuild(DB::connection(), '2026-10-20', '2026-10-20');

    expect(eligibilityMovers('2026-10-20', 'up'))->toBe(['zeg-reach-in 10.00>11.10 1.10 11.00 2026-10-08']);
});

test('the previous-close floor stays at 2.00 beside the other tests: 2.00 counts, 1.99 does not, and 2.00 alone is not enough', function (): void {
    // The floor still applies on its own: an active, in-line series is refused for its 1.99 alone.
    eligibilitySeries('zeg-floor-at', eligibilityWeek('1.70', '1.80', '1.90', '2.00', '3.00'));
    eligibilitySeries('zeg-floor-under', eligibilityWeek('1.69', '1.79', '1.89', '1.99', '3.00'));
    // And it is not sufficient: the same step from 2.00 after a flat line is not ranked.
    eligibilitySeries('zeg-floor-flat', eligibilityWeek('2.00', '2.00', '2.00', '2.00', '3.00'));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeg-floor-at 2.00>3.00 1.00 50.00 2026-10-12'])
        ->and(DB::table('price_movers')->count())->toBe(1);
});

test('a close of 0.00 is never ranked, as the close of the day or as the previous close', function (): void {
    // A fall to 0.00: under half of any low listing above zero, and listings of 0.00 are no listings.
    eligibilitySeries('zeh-to-zero', eligibilityWeek('10.00', '10.10', '10.20', '10.30', ['0.00', '9.00', '10.50']));
    eligibilitySeries('zeh-to-zero-unlisted', eligibilityWeek('10.00', '10.10', '10.20', '10.30', ['0.00', '0.00', '0.00']));
    // A rise from 0.00: no percent exists, and the floor refuses it without an error.
    eligibilitySeries('zeh-from-zero', eligibilityWeek('3.00', '2.00', '1.00', '0.00', '5.00'));
    eligibilitySeries('zeh-moving', eligibilityWeek('10.00', '10.10', '10.20', '10.30', '9.20'));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'down'))->toBe(['zeh-moving 10.30>9.20 -1.10 -10.68 2026-10-12'])
        ->and(eligibilityMovers('2026-10-13', 'up'))->toBe([])
        ->and(DB::table('price_movers')->where('close', 0)->orWhere('previous_close', 0)->count())->toBe(0);
});

test('the largest close the column holds is ranked with exact figures', function (): void {
    eligibilitySeries('zeh-large', eligibilityWeek('8999999997.00', '8999999998.00', '8999999999.00', '9000000000.00',
        ['9999999999.99', '9000000000.00', '9999999999.99']));
    eligibilitySeries('zeh-large-fall', eligibilityWeek('9000000003.00', '9000000002.00', '9000000001.00',
        ['9999999999.99', '9000000000.00', '9999999999.99'], '9000000000.00'));
    // The same step after a flat line is not ranked at any size.
    eligibilitySeries('zeh-large-flat', eligibilityWeek('9000000000.00', '9000000000.00', '9000000000.00', '9000000000.00',
        ['9999999999.99', '9000000000.00', '9999999999.99']));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeh-large 9000000000.00>9999999999.99 999999999.99 11.11 2026-10-12'])
        ->and(eligibilityMovers('2026-10-13', 'down'))->toBe(['zeh-large-fall 9999999999.99>9000000000.00 -999999999.99 -10.00 2026-10-12']);
});

test('a move whose percent does not fit the column is left out instead of failing the rebuild', function (): void {
    // 2.00 > 9999999999.99 is +499999999899.50 %, past numeric(12,2); the series is active and both closes are in line.
    eligibilitySeries('zeh-too-far', eligibilityWeek('2.03', '2.02', '2.01', '2.00', ['9999999999.99', '9999999999.99', '9999999999.99']));
    // 2.00 > 200000002.00 is +10000000000.00 %, one cent of percent past the column.
    eligibilitySeries('zeh-just-over', eligibilityWeek('2.03', '2.02', '2.01', '2.00', ['200000002.00', '200000002.00', '200000002.00']));
    // 2.00 > 200000001.99 is +9999999999.50 %, the largest percent a 2.00 previous close can store.
    eligibilitySeries('zeh-just-fits', eligibilityWeek('2.03', '2.02', '2.01', '2.00', ['200000001.99', '200000001.99', '200000001.99']));

    Movers::rebuild(DB::connection(), '2026-10-13', '2026-10-13');

    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeh-just-fits 2.00>200000001.99 199999999.99 9999999999.50 2026-10-12']);
});

test('the first days of a history rank nothing, and a previous close older than 14 days is no previous close', function (): void {
    // One close ever; then a series that moves every day: nothing on its first three days, ranked from the fourth.
    eligibilitySeries('zeh-single', ['2026-10-13' => '50.00']);
    eligibilitySeries('zeh-young', eligibilityWeek('20.00', '22.00', '24.50', '27.00', '30.00'));
    // Four moving days, then nothing for 15 days: 10-19 has no previous close. One day earlier it has one, 14 days
    // back, but then only two closes sit inside the reach: not ranked either.
    eligibilitySeries('zeh-stale-15', ['2026-10-01' => '9.70', '2026-10-02' => '9.80', '2026-10-03' => '9.90', '2026-10-04' => '10.00', '2026-10-19' => '11.10']);
    eligibilitySeries('zeh-stale-14', ['2026-10-02' => '9.70', '2026-10-03' => '9.80', '2026-10-04' => '9.90', '2026-10-05' => '10.00', '2026-10-19' => '11.10']);

    Movers::rebuild(DB::connection(), '2026-10-01', '2026-10-19');

    expect(DB::table('price_movers')->orderBy('day')->pluck('card_id')->all())->toBe(['zeh-young', 'zeh-young'])
        ->and(eligibilityMovers('2026-10-12', 'up'))->toBe(['zeh-young 24.50>27.00 2.50 10.20 2026-10-11'])
        ->and(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeh-young 27.00>30.00 3.00 11.11 2026-10-12'])
        ->and(DB::table('price_movers')->whereIn('day', ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-19'])->count())->toBe(0);
});

/**
 * Four series over 2026-10-08 to 2026-10-13, card => day => close. With every day present: zeo-late needs its 10-09
 * close to show four values on 10-13; zeo-held shows four only while 10-11 is missing (19, 20, 21, 21, 23.50) and three
 * once it is there; zeo-prev moves on 10-12 once that day exists, and on 10-13 (against 10-11) only while it does not;
 * zeo-steady moves every day.
 *
 * @return array<string, array<string, string>>
 */
function eligibilityArrivals(): array
{
    return [
        'zeo-late' => ['2026-10-09' => '9.00', '2026-10-10' => '10.00', '2026-10-11' => '10.00', '2026-10-12' => '10.20', '2026-10-13' => '11.30'],
        'zeo-held' => ['2026-10-08' => '19.00', '2026-10-09' => '20.00', '2026-10-10' => '21.00', '2026-10-11' => '21.00', '2026-10-12' => '21.00', '2026-10-13' => '23.50'],
        'zeo-prev' => ['2026-10-09' => '30.00', '2026-10-10' => '31.00', '2026-10-11' => '33.00', '2026-10-12' => '36.00', '2026-10-13' => '36.50'],
        'zeo-steady' => ['2026-10-08' => '40.00', '2026-10-09' => '42.00', '2026-10-10' => '44.50', '2026-10-11' => '47.00', '2026-10-12' => '50.00', '2026-10-13' => '53.00'],
    ];
}

/** The rows eligibilityArrivals() gives once every day is present, as eligibilityDump() prints them. */
const ELIGIBILITY_ARRIVED = [
    '2026-10-11|tcgplayer|up|1|zeo-steady:normal:-:-|zeo-steady|1|47.00|44.50|2026-10-10|2.50|5.62|2026-10-11T20:05:00Z',
    '2026-10-12|tcgplayer|up|1|zeo-prev:normal:-:-|zeo-prev|1|36.00|33.00|2026-10-11|3.00|9.09|2026-10-12T20:05:00Z',
    '2026-10-12|tcgplayer|up|2|zeo-steady:normal:-:-|zeo-steady|1|50.00|47.00|2026-10-11|3.00|6.38|2026-10-12T20:05:00Z',
    '2026-10-13|tcgplayer|up|1|zeo-late:normal:-:-|zeo-late|1|11.30|10.20|2026-10-12|1.10|10.78|2026-10-13T20:05:00Z',
    '2026-10-13|tcgplayer|up|2|zeo-steady:normal:-:-|zeo-steady|1|53.00|50.00|2026-10-12|3.00|6.00|2026-10-13T20:05:00Z',
];

test('a day that arrives after later days leaves every day as one ordered pass gives it', function (): void {
    // Days arrive as 10-08, 10-10, 10-13, 10-12, 10-09, 10-11. Each arrival writes the day's rows and rebuilds the window
    // its ingest recomputes: from the day (or the day after the newest earlier arrival, when the day is the newest) to
    // 14 days after it, capped at the newest day present.
    $present = [];
    $arrive = function (string $day) use (&$present): void {
        foreach (eligibilityArrivals() as $card => $days) {
            eligibilitySeries($card, array_intersect_key($days, [$day => true]));
        }
        $previousNewest = $present === [] ? null : max($present);
        $present[] = $day;
        $from = $previousNewest === null ? $day : min($day, date('Y-m-d', strtotime("{$previousNewest} +1 day")));
        $to = min(date('Y-m-d', strtotime("{$day} +14 days")), max($present));
        Movers::rebuild(DB::connection(), $from, $to);
    };

    foreach (['2026-10-08', '2026-10-10', '2026-10-13'] as $day) {
        $arrive($day);
    }
    // Three closes at most per series: nothing can be ranked yet.
    expect(DB::table('price_movers')->count())->toBe(0);

    $arrive('2026-10-12');
    expect(eligibilityMovers('2026-10-13', 'up'))->toBe(['zeo-steady 50.00>53.00 3.00 6.00 2026-10-12'])
        ->and(DB::table('price_movers')->count())->toBe(1);

    $arrive('2026-10-09');
    // 10-09 gives zeo-late and zeo-held their fourth value on 10-13, and zeo-steady its fourth on 10-12.
    expect(eligibilityMovers('2026-10-13', 'up'))->toBe([
        'zeo-held 21.00>23.50 2.50 11.90 2026-10-12',
        'zeo-late 10.20>11.30 1.10 10.78 2026-10-12',
        'zeo-steady 50.00>53.00 3.00 6.00 2026-10-12',
    ])
        ->and(eligibilityMovers('2026-10-12', 'up'))->toBe(['zeo-steady 44.50>50.00 5.50 12.36 2026-10-10']);

    $arrive('2026-10-11');
    // 10-11 pushes zeo-held's 19.00 out of its five most recent closes, becomes the previous close of 10-12, and
    // gives 10-11 its own movers.
    expect(eligibilityDump())->toBe(ELIGIBILITY_ARRIVED);
});

test('rebuilding a window twice, as one window, day by day or in any order of days gives byte-identical rows', function (): void {
    foreach (eligibilityArrivals() as $card => $days) {
        eligibilitySeries($card, $days);
    }
    $days = ['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];
    $passes = [];

    Movers::rebuild(DB::connection(), '2026-10-08', '2026-10-13');
    $passes['one window'] = [eligibilityDump(), PriceDigest::movers(DB::connection())];
    Movers::rebuild(DB::connection(), '2026-10-08', '2026-10-13');
    $passes['the same window again'] = [eligibilityDump(), PriceDigest::movers(DB::connection())];
    foreach ([
        'day by day, ascending' => $days,
        'day by day, descending' => array_reverse($days),
        'day by day, scrambled' => ['2026-10-12', '2026-10-08', '2026-10-13', '2026-10-10', '2026-10-09', '2026-10-11'],
    ] as $name => $order) {
        DB::table('price_movers')->delete();
        foreach ($order as $day) {
            Movers::rebuild(DB::connection(), $day, $day);
        }
        $passes[$name] = [eligibilityDump(), PriceDigest::movers(DB::connection())];
    }
    DB::table('price_movers')->delete();
    Movers::rebuild(DB::connection(), '2026-10-11', '2026-10-13');
    Movers::rebuild(DB::connection(), '2026-10-08', '2026-10-12');
    $passes['two overlapping windows, the later first'] = [eligibilityDump(), PriceDigest::movers(DB::connection())];

    expect($passes['one window'][0])->toBe(ELIGIBILITY_ARRIVED)
        ->and($passes['one window'][1]['rows'])->toBe(5)
        ->and($passes['one window'][1]['sha256'])->toMatch('/\A[0-9a-f]{64}\z/');
    foreach ($passes as $name => $pass) {
        expect($pass)->toBe($passes['one window'], $name);
    }
});
