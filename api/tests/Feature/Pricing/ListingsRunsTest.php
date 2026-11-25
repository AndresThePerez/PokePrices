<?php

declare(strict_types=1);

use App\Domain\Pricing\Listings;
use Illuminate\Support\Facades\DB;

// A lowest listing is read only from price_points rows that DailyRollup counts as observations: rows of a day and
// marker that an `ok` run backs, or rows of the day whose run is still running. Rows a failed or superseded run left
// behind are skipped, so the listing falls back to the newest observation a good run backs.

/** Opens an ingest run of $day with the marker $day 20:05 UTC plus $minutes and returns its id. */
function lrrRun(string $day, string $status, int $minutes = 0): int
{
    $marker = sprintf('%s 20:%02d:00+00', $day, 5 + $minutes);

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day,
        'marker' => $marker, 'mode' => 'queued', 'status' => $status, 'started_at' => $day.' 20:10:00+00',
        'finished_at' => $status === 'running' ? null : $day.' 20:11:00+00']);
}

/** Writes the observation of $key at its run's marker with the low $low. */
function lrrPoint(string $key, int $runId, ?string $low): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
    DB::statement(<<<'SQL'
        INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                  market, low, mid, high, direct_low, ingest_run_id)
        SELECT ?, 'tcgplayer', r.marker, r.day, 1, 1, 'Holofoil', NULL, ?::numeric, NULL, NULL, NULL, r.id
        FROM ingest_runs r WHERE r.id = ?
        SQL, [$key, $low, $runId]);
}

it('reads the ok run\'s listing of a day, not a lower one a superseded run left behind', function (): void {
    $ok = lrrRun('2026-10-12', 'ok');
    $superseded = lrrRun('2026-10-12', 'superseded', 30);
    lrrPoint('base1-4:holo:1st:-', $ok, '50.00');
    lrrPoint('base1-4:holo:1st:-', $superseded, '10.00');

    expect(Listings::forVariants(['base1-4:holo:1st:-' => null]))->toBe([
        'base1-4:holo:1st:-' => ['lowest' => '50.00', 'thin' => false, 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:00Z'],
    ]);
});

it('skips a day whose only rows come from a failed run and falls back to the day before', function (): void {
    $before = lrrRun('2026-10-11', 'ok');
    $failed = lrrRun('2026-10-12', 'failed');
    lrrPoint('base1-4:holo:1st:-', $before, '40.00');
    lrrPoint('base1-4:holo:1st:-', $failed, '10.00');
    lrrPoint('base1-6:holo:1st:-', $failed, '12.00');

    expect(Listings::forVariants(['base1-4:holo:1st:-' => null, 'base1-6:holo:1st:-' => null]))->toBe([
        'base1-4:holo:1st:-' => ['lowest' => '40.00', 'thin' => false, 'day' => '2026-10-11', 'as_of' => '2026-10-11T20:05:00Z'],
    ]);
});

it('reads the rows of the day\'s current run while it is still running, and not a failed run\'s newer ones', function (): void {
    $before = lrrRun('2026-10-11', 'ok');
    $running = lrrRun('2026-10-12', 'running');
    $failed = lrrRun('2026-10-13', 'failed');
    lrrPoint('base1-4:holo:1st:-', $before, '40.00');
    lrrPoint('base1-4:holo:1st:-', $running, '30.00');
    lrrPoint('base1-4:holo:1st:-', $failed, '5.00');

    expect(Listings::forVariants(['base1-4:holo:1st:-' => null]))->toBe([
        'base1-4:holo:1st:-' => ['lowest' => '30.00', 'thin' => false, 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:00Z'],
    ]);
});

it('keeps a good run\'s null listing rather than falling through to a bad run\'s row', function (): void {
    $ok = lrrRun('2026-10-11', 'ok');
    $failed = lrrRun('2026-10-12', 'failed');
    lrrPoint('base1-4:holo:1st:-', $ok, null);
    lrrPoint('base1-4:holo:1st:-', $failed, '10.00');

    expect(Listings::forVariants(['base1-4:holo:1st:-' => null]))->toBe([
        'base1-4:holo:1st:-' => ['lowest' => null, 'thin' => false, 'day' => '2026-10-11', 'as_of' => '2026-10-11T20:05:00Z'],
    ]);
});
