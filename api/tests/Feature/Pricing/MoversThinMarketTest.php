<?php

declare(strict_types=1);

use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PriceDigest;
use App\Domain\Pricing\PricingConfig;
use Illuminate\Support\Facades\DB;

// The movers leave out a close the price answer flags as a thin market (under half the lowest listing of its own
// observation), on top of the eligibility rule: a real close and a previous close in the epoch within 14 days, a
// previous close of at least 2.00, a move of at least 5.00 % and 1.00, four distinct values among the five most recent
// real closes, and both closes between half the low and twice the mid listing of their own observation. The
// listing lower bound already keeps out a close under half its low listing, so the two conditions agree on every
// row; the cases below hold the rule's earlier cases with the exclusion in place.
//
// A day maps to a close as a string (listings in line: low 90 %, mid 105 % of it), to [close, low, mid], or to null (a
// filled row repeating the last real close).

function t05mRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->orderBy('id')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-01',
        'marker' => '2026-10-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-10-01 20:10:00+00',
        'finished_at' => '2026-10-01 20:11:00+00']);
}

/** @param array<string, string|array{0: string, 1: string|null, 2: string|null}|null> $days */
function t05mSeries(string $card, array $days, int $epoch = 1): string
{
    $key = "{$card}:holo:1st:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo', 'edition' => '1st',
        'treatment' => '-', 'catalog_status' => 'unverified']);
    $last = null;
    foreach ($days as $day => $spec) {
        if ($spec === null) {
            DB::statement(<<<'SQL'
                INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
                VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 0, true, ?::date, ?::date + interval '20 hours 5 minutes')
                SQL, [$key, $day, $epoch, $last[1], $last[1], $last[1], $last[1], $last[0], $last[0]]);

            continue;
        }
        [$close, $low, $mid] = is_array($spec) ? $spec : [$spec, null, null];
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
            SQL, [$key, $day, $epoch, $close, $close, $close, $close, $day]);
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, ?, 1, 'Holofoil', ?::numeric,
                    CASE WHEN ?::boolean THEN trunc(?::numeric * 0.90, 2) ELSE ?::numeric END,
                    CASE WHEN ?::boolean THEN trunc(?::numeric * 1.05, 2) ELSE ?::numeric END, NULL, NULL, ?)
            SQL, [$key, $day, $day, $epoch, $close,
            is_array($spec) ? 'false' : 'true', $close, $low,
            is_array($spec) ? 'false' : 'true', $close, $mid, t05mRun()]);
        $last = [$day, $close];
    }

    return $key;
}

/** @return list<string> "card previous_close>close change_pct" of $day in $direction, by rank */
function t05mMovers(string $day, string $direction): array
{
    return array_map(
        fn (object $r): string => "{$r->card_id} {$r->previous_close}>{$r->close} {$r->change_pct}",
        DB::select('SELECT card_id, previous_close::text AS previous_close, close::text AS close, change_pct::text AS change_pct
                    FROM price_movers WHERE day = ?::date AND direction = ? ORDER BY rank', [$day, $direction]),
    );
}

/** Five days ending 2026-10-13. */
function t05mWeek(string|array|null ...$closes): array
{
    $days = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];

    return array_combine(array_slice($days, count($days) - count($closes)), $closes);
}

test('the published description of GET /v1/movers states the rule as it is coded', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $description = (string) $spec['paths']['/movers']['get']['description'];

    expect($description)->toContain('a previous close of at least meta.min_previous_close ('.config('pricing.movers.min_previous_close').' USD)')
        ->and($description)->toContain('in the same series epoch within '.config('pricing.fill_cap_days').' days')
        ->and($description)->toContain('at least '.PricingConfig::MOVERS_MIN_CHANGE_PCT.' % and at least '.PricingConfig::MOVERS_MIN_CHANGE.' USD')
        ->and($description)->toContain('at least '.PricingConfig::MOVERS_ACTIVITY_MIN_DISTINCT.' of its '.PricingConfig::MOVERS_ACTIVITY_CLOSES.' most recent real closes')
        ->and($description)->toContain(PricingConfig::MOVERS_LISTING_LOW_RATIO.' x the low listing and '.PricingConfig::MOVERS_LISTING_MID_RATIO.' x the mid listing')
        ->and($description)->toContain('thin market')
        ->and($description)->toContain('limit is 1 to '.PricingConfig::MOVERS_CEILING.' (default '.PricingConfig::MOVERS_DEFAULT_LIMIT.')')
        ->and($description)->not->toContain('A variant counts when it has a real close on that day and a previous close of at least meta.min_previous_close (2.00 USD) in the same series epoch within 14 days;');
});

test('the thin-market ratio is one half, a named constant of its own', function (): void {
    expect(PricingConfig::THIN_MARKET_RATIO)->toBe('0.5');
});

test('the Gyarados move to 111.61 against a lowest listing of 2380.00 is left out, and the same series against 150.00 is ranked', function (): void {
    // Four distinct closes, a previous close in line, and +11.61 % and +11.61 on 10-13: only the listing of 10-13 differs.
    t05mSeries('base1-6', t05mWeek('97.00', '98.00', '99.00', '100.00', ['111.61', '2380.00', '2500.00']));
    t05mSeries('base2-6', t05mWeek('97.00', '98.00', '99.00', '100.00', ['111.61', '150.00', '160.00']));
    // Exactly half the lowest listing is no thin market: 111.61 against 223.22.
    t05mSeries('base3-6', t05mWeek('97.00', '98.00', '99.00', '100.00', ['111.61', '223.22', '230.00']));
    // One cent under half: 111.61 against 223.24 (half is 111.62).
    t05mSeries('base4-6', t05mWeek('97.00', '98.00', '99.00', '100.00', ['111.61', '223.24', '230.00']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(t05mMovers('2026-10-13', 'up'))->toBe(['base2-6 100.00>111.61 11.61', 'base3-6 100.00>111.61 11.61'])
        ->and(DB::table('price_movers')->where('card_id', 'base1-6')->count())->toBe(0)
        ->and(DB::table('price_movers')->where('card_id', 'base4-6')->count())->toBe(0);
});

test('a thin-market fall is left out too, beside the same fall in line with its listings', function (): void {
    t05mSeries('zt1-1', t05mWeek('210.00', '205.00', '200.00', '199.00', ['150.00', '400.00', '420.00']));
    t05mSeries('zt1-2', t05mWeek('210.00', '205.00', '200.00', '199.00', ['150.00', '140.00', '160.00']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(t05mMovers('2026-10-13', 'down'))->toBe(['zt1-2 199.00>150.00 -24.62'])
        ->and(t05mMovers('2026-10-13', 'up'))->toBe([]);
});

test('the rule\'s earlier cases still hold with the exclusion: flat then a jump, a spike that reverts, a missing day, an epoch change, far outside the listings', function (): void {
    // Flat then a jump: two distinct values.
    t05mSeries('zt2-flat', t05mWeek('10.00', '10.00', '10.00', '10.00', '15.00'));
    // A spike that comes back on 10-13.
    t05mSeries('zt2-spike', t05mWeek('10.00', '10.00', '10.00', '15.00', '10.00'));
    // A missing day (10-11) and a filled one (10-10) are no observation: 8, 9, (filled), (missing), 10, 11 is ranked on 10-13.
    t05mSeries('zt2-gap', ['2026-10-07' => '8.00', '2026-10-08' => '9.00', '2026-10-09' => '9.50', '2026-10-10' => null,
        '2026-10-12' => '10.00', '2026-10-13' => '11.00']);
    // An epoch change on 10-12: the new epoch has two closes.
    t05mSeries('zt2-epoch', ['2026-10-09' => '8.00', '2026-10-10' => '9.00', '2026-10-11' => '10.00'], 1);
    t05mSeries('zt2-epoch', ['2026-10-12' => '11.00', '2026-10-13' => '12.00'], 2);
    // Far outside the listings: 752.00 against a mid of 81.22.
    t05mSeries('zt2-far', t05mWeek('99.00', '99.50', '99.99', '100.50', ['752.00', '48.11', '81.22']));
    // The control that keeps moving, in line with its listings.
    t05mSeries('zt2-moving', t05mWeek('20.00', '20.50', '21.00', '23.00', '25.50'));

    Movers::rebuild(DB::connection(), '2026-10-07', '2026-10-13');

    expect(t05mMovers('2026-10-13', 'up'))->toBe(['zt2-moving 23.00>25.50 10.87', 'zt2-gap 10.00>11.00 10.00'])
        ->and(t05mMovers('2026-10-12', 'up'))->toBe(['zt2-moving 21.00>23.00 9.52'])
        ->and(t05mMovers('2026-10-13', 'down'))->toBe([])
        ->and(DB::table('price_movers')->count())->toBe(3);
});

test('identical rows whatever the order of days, with thin-market closes among them', function (): void {
    t05mSeries('zt3-thin', ['2026-10-08' => '40.00', '2026-10-09' => '41.00', '2026-10-10' => '42.00', '2026-10-11' => '43.00',
        '2026-10-12' => ['48.00', '100.00', '110.00'], '2026-10-13' => '52.00']);
    t05mSeries('zt3-steady', ['2026-10-08' => '40.00', '2026-10-09' => '41.00', '2026-10-10' => '42.00', '2026-10-11' => '43.00',
        '2026-10-12' => '48.00', '2026-10-13' => '52.00']);
    $dump = fn (): array => array_map(fn (object $r): string => implode('|', (array) $r), DB::select(
        'SELECT day::text, direction, rank::text, variant_key, close::text, previous_close::text, change_pct::text FROM price_movers ORDER BY day, direction, rank'));
    $days = ['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'];
    $passes = [];

    Movers::rebuild(DB::connection(), '2026-10-08', '2026-10-13');
    $passes['one window'] = [$dump(), PriceDigest::movers(DB::connection())];
    foreach (['ascending' => $days, 'descending' => array_reverse($days), 'scrambled' => ['2026-10-12', '2026-10-08', '2026-10-13', '2026-10-10', '2026-10-09', '2026-10-11']] as $name => $order) {
        DB::table('price_movers')->delete();
        foreach ($order as $day) {
            Movers::rebuild(DB::connection(), $day, $day);
        }
        $passes[$name] = [$dump(), PriceDigest::movers(DB::connection())];
    }

    // zt3-thin's 48.00 on 10-12 is under half its lowest listing of 100.00: no row on 10-12, and none on 10-13 either,
    // whose previous close it is (the previous close is held to its own listings).
    expect($passes['one window'][0])->toBe([
        '2026-10-12|up|1|zt3-steady:holo:1st:-|48.00|43.00|11.63',
        '2026-10-13|up|1|zt3-steady:holo:1st:-|52.00|48.00|8.33',
    ]);
    foreach ($passes as $name => $pass) {
        expect($pass)->toBe($passes['one window'], $name);
    }
});
