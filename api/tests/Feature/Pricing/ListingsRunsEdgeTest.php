<?php

declare(strict_types=1);

use App\Domain\Pricing\DailyRollup;
use App\Domain\Pricing\Listings;
use Illuminate\Support\Facades\DB;

// The rows of a failed or superseded run are no observations, for a listing and for a daily close alike. These tests
// hold the rollup's own reading of them where the condition is shared, and the listing's reading of the same rows.

const LRE_KEY = 'base1-4:holo:1st:-';

/** Opens an ingest run of $day whose marker is $day 20:05 UTC plus $minutes and returns its id. */
function lreRun(string $day, string $status, int $minutes = 0): int
{
    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day,
        'marker' => sprintf('%s 20:%02d:00+00', $day, 5 + $minutes), 'mode' => 'queued', 'status' => $status,
        'started_at' => $day.' 20:10:00+00', 'finished_at' => $status === 'running' ? null : $day.' 20:11:00+00']);
}

/** Writes the observation of $key at its run's marker. */
function lrePoint(string $key, int $runId, ?string $market, ?string $low = null): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
    DB::statement(<<<'SQL'
        INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                  market, low, mid, high, direct_low, ingest_run_id)
        SELECT ?, 'tcgplayer', r.marker, r.day, 1, 1, 'Holofoil', ?::numeric, ?::numeric, NULL, NULL, NULL, r.id
        FROM ingest_runs r WHERE r.id = ?
        SQL, [$key, $market, $low, $runId]);
}

/**
 * The real candle of a day as [open, high, low, close, n_obs, as_of], or null without one.
 *
 * @return list<mixed>|null
 */
function lreCandle(string $day): ?array
{
    $row = DB::table('price_daily')->where('variant_key', LRE_KEY)->where('day', $day)->where('is_filled', false)->first();

    return $row === null ? null : [$row->open, $row->high, $row->low, $row->close, $row->n_obs,
        str_replace('+00', '', (string) $row->as_of)];
}

it('closes an uncollected day from every ok run of it and from no failed run', function (): void {
    $first = lreRun('2026-10-11', 'ok');
    $recollected = lreRun('2026-10-11', 'ok', 30);
    $failed = lreRun('2026-10-11', 'failed', 45);
    $today = lreRun('2026-10-12', 'running');
    lrePoint(LRE_KEY, $first, '10.00');
    lrePoint(LRE_KEY, $recollected, '30.00');
    lrePoint(LRE_KEY, $failed, '99.00');
    lrePoint(LRE_KEY, $today, '31.00');

    // The newest ingested day before the run is 10-10, so the window starts at the uncollected 10-11.
    DailyRollup::rollup(DB::connection(), '2026-10-12', '2026-10-10');

    expect(lreCandle('2026-10-11'))->toBe(['10.00', '30.00', '10.00', '30.00', 2, '2026-10-11 20:35:00'])
        ->and(lreCandle('2026-10-12'))->toBe(['31.00', '31.00', '31.00', '31.00', 1, '2026-10-12 20:05:00']);
});

it('closes an uncollected day without the row a superseded run left beside the ok run', function (): void {
    $ok = lreRun('2026-10-11', 'ok');
    $superseded = lreRun('2026-10-11', 'superseded', 30);
    $today = lreRun('2026-10-12', 'running');
    lrePoint(LRE_KEY, $ok, '10.00');
    lrePoint(LRE_KEY, $superseded, '99.00');
    lrePoint(LRE_KEY, $today, '12.00');

    DailyRollup::rollup(DB::connection(), '2026-10-12', '2026-10-10');

    expect(lreCandle('2026-10-11'))->toBe(['10.00', '10.00', '10.00', '10.00', 1, '2026-10-11 20:05:00']);
});

it('leaves the days outside its window alone, whatever ok runs back their rows', function (): void {
    DB::table('card_variants')->insertOrIgnore(['variant_key' => LRE_KEY, 'card_id' => 'base1-4', 'finish' => 'holo',
        'edition' => '1st', 'treatment' => '-', 'catalog_status' => 'unverified']);
    DB::table('price_daily')->insert(['variant_key' => LRE_KEY, 'source' => 'tcgplayer', 'day' => '2026-10-10',
        'series_epoch' => 1, 'open' => '5.00', 'high' => '5.00', 'low' => '5.00', 'close' => '5.00', 'n_obs' => 1,
        'is_filled' => false, 'filled_from' => null, 'as_of' => '2026-10-10 20:05:00+00']);
    $before = lreRun('2026-10-10', 'ok');
    $current = lreRun('2026-10-11', 'running');
    $after = lreRun('2026-10-12', 'ok');
    lrePoint(LRE_KEY, $before, '77.00');
    lrePoint(LRE_KEY, $current, '20.00');
    lrePoint(LRE_KEY, $after, '88.00');

    // The newest ingested day is 10-11 itself: the window is 10-11 alone.
    DailyRollup::rollup(DB::connection(), '2026-10-11', '2026-10-11');

    expect(lreCandle('2026-10-10'))->toBe(['5.00', '5.00', '5.00', '5.00', 1, '2026-10-10 20:05:00'])
        ->and(lreCandle('2026-10-11'))->toBe(['20.00', '20.00', '20.00', '20.00', 1, '2026-10-11 20:05:00'])
        ->and(lreCandle('2026-10-12'))->toBeNull();
});

it('prefers the newest of two ok runs of one day for the listing', function (): void {
    $first = lreRun('2026-10-12', 'ok');
    $recollected = lreRun('2026-10-12', 'ok', 30);
    lrePoint(LRE_KEY, $first, null, '10.00');
    lrePoint(LRE_KEY, $recollected, null, '50.00');

    expect(Listings::forVariants([LRE_KEY => null]))->toBe([
        LRE_KEY => ['lowest' => '50.00', 'thin' => false, 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:35:00Z'],
    ]);
});

it('keeps an ok run\'s null listing when a superseded run of the same day left a newer value', function (): void {
    $ok = lreRun('2026-10-12', 'ok');
    $superseded = lreRun('2026-10-12', 'superseded', 30);
    lrePoint(LRE_KEY, $ok, null, null);
    lrePoint(LRE_KEY, $superseded, null, '10.00');

    expect(Listings::forVariants([LRE_KEY => null]))->toBe([
        LRE_KEY => ['lowest' => null, 'thin' => false, 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:00Z'],
    ]);
});

it('reads a bad run\'s rows for no variant of a card when only one variant has them', function (): void {
    $ok = lreRun('2026-10-11', 'ok');
    $failed = lreRun('2026-10-12', 'failed');
    $other = 'base1-4:reverse:1st:-';
    foreach ([LRE_KEY, $other] as $key) {
        lrePoint($key, $ok, null, '40.00');
    }
    lrePoint($other, $failed, null, '10.00');

    $listings = Listings::forVariants([LRE_KEY => null, $other => null]);

    expect($listings[LRE_KEY]['lowest'])->toBe('40.00')
        ->and($listings[$other]['lowest'])->toBe('40.00')
        ->and($listings[$other]['day'])->toBe('2026-10-11');
});

it('answers a list of twenty cards with one query, as it answers one card', function (): void {
    $ok = lreRun('2026-10-12', 'ok');
    $failed = lreRun('2026-10-13', 'failed');
    $keys = [];
    for ($i = 1; $i <= 20; $i++) {
        $keys[] = "base1-{$i}:holo:1st:-";
        lrePoint($keys[$i - 1], $ok, null, '40.00');
        lrePoint($keys[$i - 1], $failed, null, '10.00');
    }
    $counts = [];
    foreach ([array_slice($keys, 0, 1), $keys] as $subset) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $listings = Listings::forVariants(array_fill_keys($subset, null));
        DB::disableQueryLog();
        $counts[] = [count(DB::getQueryLog()), count($listings), array_values(array_unique(array_column($listings, 'lowest')))];
    }

    expect($counts)->toBe([[1, 1, ['40.00']], [1, 20, ['40.00']]]);
});
