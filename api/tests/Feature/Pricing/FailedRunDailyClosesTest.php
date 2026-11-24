<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A queued run commits each chunk on its own, so a run that fails part-way leaves the rows of its finished chunks in
// price_points. Those rows are not a day: until an ok run of their day and marker exists, the rollup of another day
// does not read them, so the failed day stays filled from the close before it and gets no close of its own.

const FRC_FAILED_MARKER = '2026-10-11 20:05:45+00';

/**
 * Runs pokeprices:prices:ingest on the fixture days and returns [exit code, the last JSON line or null].
 *
 * @return array{0: int, 1: array<string, mixed>|null}
 */
function frcIngest(array $options): array
{
    $code = Artisan::call('pokeprices:prices:ingest', $options + ['--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
    $json = null;
    foreach (array_reverse(explode("\n", trim(Artisan::output()))) as $line) {
        if (str_starts_with(trim($line), '{')) {
            $json = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            break;
        }
    }

    return [$code, $json];
}

/** The price_daily rows of a day as [real, filled]. */
function frcDaily(string $day): array
{
    return [
        DB::table('price_daily')->where('day', $day)->where('is_filled', false)->count(),
        DB::table('price_daily')->where('day', $day)->where('is_filled', true)->count(),
    ];
}

beforeEach(function (): void {
    // A map build queues VerifyCatalog; the test queue would run it against the real PokéSearch. The real queue
    // manager is put back afterwards, so the ingest below runs its own jobs.
    $manager = app('queue');
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    if ($code !== 0) {
        throw new RuntimeException('the fixture map build failed: '.Artisan::output());
    }
    Queue::swap($manager);
});

it('gives a day whose queued run failed after committed chunks no close of its own when the next day is ingested', function (): void {
    [$firstCode] = frcIngest(['--day' => '2026-10-10', '--sync' => true]);

    // The queued run of 10-11 crashes in its third chunk: two chunks are committed, the run is failed.
    $seen = 0;
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$seen, &$fired): void {
        if (! $fired && str_starts_with($query->sql, "UPDATE ingest_run_chunks SET status = 'done'") && ++$seen === 3) {
            $fired = true;
            throw new RuntimeException('injected crash in the third chunk');
        }
    });
    [$failedCode, $failedLine] = frcIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync']);
    $failedId = (int) ($failedLine['run_id'] ?? 0);
    $failedRun = DB::table('ingest_runs')->where('id', $failedId)->first(['status', 'mode']);
    $failedPoints = DB::table('price_points')->where('day', '2026-10-11')->whereNotNull('market')->count();
    $doneChunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $failedId)->where('status', 'done')->count();

    // The next day arrives and ingests ok; its rollup window starts at 10-11, the day after the newest ok day.
    [$nextCode] = frcIngest(['--day' => '2026-10-12', '--sync' => true]);
    $nextStatus = DB::table('ingest_runs')->where('day', '2026-10-12')->orderByDesc('id')->value('status');

    $ownCloses = DB::table('price_daily')->where('day', '2026-10-11')->where('as_of', FRC_FAILED_MARKER)->count();
    $daily = frcDaily('2026-10-11');
    $filledFrom = DB::table('price_daily')->where('day', '2026-10-11')->distinct()->orderBy('filled_from')->pluck('filled_from')->all();
    $latestOfFailedDay = DB::table('price_latest')->where('day', '2026-10-11')->count();
    $nextReal = frcDaily('2026-10-12')[0];

    // The failed run is resumed: the day gets its closes, and everything equals an ingest that never failed.
    [$resumeCode] = frcIngest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync', '--wait' => 30]);
    $resumedStatus = DB::table('ingest_runs')->where('id', $failedId)->value('status');
    $resumedReal = frcDaily('2026-10-11')[0];
    $recovered = PriceDigest::compute(DB::connection())['tables'];
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    DB::table('price_events')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
    foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        frcIngest(['--day' => $day, '--sync' => true]);
    }
    $clean = PriceDigest::compute(DB::connection())['tables'];

    expect($fired)->toBeTrue()
        ->and([$firstCode, $failedCode, $nextCode, $resumeCode])->toBe([0, 1, 0, 0])
        ->and([$failedRun?->status, $failedRun?->mode, $doneChunks, $nextStatus])->toBe(['failed', 'queued', 2, 'ok'])
        // The failed run's rows are in price_points, and they are market observations a rollup could read.
        ->and($failedPoints)->toBeGreaterThan(0)
        // No close of 10-11 comes from them: the day has no real candle, only rows filled from 10-10.
        ->and($ownCloses)->toBe(0)
        ->and($daily[0])->toBe(0)
        ->and($daily[1])->toBeGreaterThan(0)
        ->and($filledFrom)->toBe(['2026-10-10'])
        ->and($latestOfFailedDay)->toBe(0)
        ->and($nextReal)->toBeGreaterThan(0)
        ->and($resumedStatus)->toBe('ok')
        ->and($resumedReal)->toBeGreaterThan(0)
        ->and($recovered)->toBe($clean);
});
