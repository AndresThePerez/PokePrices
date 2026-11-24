<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\PriceDigest;
use App\Domain\Pricing\Replay;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The rows a queued run committed before it failed are not a day. Whatever rollup window later covers them, the daily
// closes, the latest prices, the movers and the movement events are those of a history in which that run never wrote
// a row, and no close of a day with an ok run is lost on the way.

const FRX_DAYS = 'tests/Fixtures/tcgcsv-days/snapshots';

/**
 * Runs pokeprices:prices:ingest and returns the exit code.
 *
 * @param  array<string, mixed>  $options
 */
function frxIngest(array $options): int
{
    return Artisan::call('pokeprices:prices:ingest', $options + ['--snapshots' => base_path(FRX_DAYS)]);
}

/** Makes the $nth statement that contains $needle, counted from now, throw once. */
function frxCrashOn(string $needle, int $nth): void
{
    $seen = 0;
    $fired = false;
    DB::listen(function (QueryExecuted $query) use ($needle, $nth, &$seen, &$fired): void {
        if (! $fired && str_contains($query->sql, $needle) && ++$seen === $nth) {
            $fired = true;
            throw new RuntimeException('injected crash');
        }
    });
}

/** Empties every pricing table, so a second history can be ingested in the same test. */
function frxWipe(): void
{
    DB::statement('TRUNCATE '.implode(', ', Replay::WIPED_TABLES));
    DB::table('price_events')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

/**
 * What the screens and the alerts read: the digests of price_daily and price_latest, the movers, and the movement
 * events without their run.
 *
 * @return array<string, mixed>
 */
function frxDerived(): array
{
    $tables = PriceDigest::compute(DB::connection())['tables'];

    return [
        'price_daily' => $tables['price_daily'],
        'price_latest' => $tables['price_latest'],
        'price_movers' => PriceDigest::movers(DB::connection()),
        'price_events' => DB::table('price_events')->orderBy('day')->orderBy('variant_key')->orderBy('rule')
            ->get(['type', 'rule', 'variant_key', 'day', 'direction', 'close', 'previous_close', 'previous_day', 'change', 'change_pct'])
            ->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * The real closes of the days, every column, in one order.
 *
 * @param  list<string>  $days
 * @return list<array<string, mixed>>
 */
function frxRealCloses(array $days): array
{
    return DB::table('price_daily')->whereIn('day', $days)->where('is_filled', false)->orderBy('day')->orderBy('variant_key')
        ->get()->map(fn (object $row): array => (array) $row)->all();
}

/** How many variants have a market observation on the day: the real closes an ok day must have. */
function frxObserved(string $day): int
{
    return DB::table('price_points')->where('day', $day)->whereNotNull('market')->distinct()->count('variant_key');
}

/** A writable copy of the fixture day under a new marker on the same date; afterEach removes it. */
function frxRecollected(string $day, string $marker): string
{
    $root = storage_path('framework/testing/pp-frx-'.bin2hex(random_bytes(6)));
    $from = base_path(FRX_DAYS."/{$day}");
    mkdir("{$root}/{$day}", 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
    }
    $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $manifest['marker'] = $marker;
    file_put_contents("{$root}/{$day}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$root}/{$day}/marker.txt", $marker."\n");
    frxRoots($root);

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function frxRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

beforeEach(function (): void {
    // A map build queues VerifyCatalog; the test queue would run it against the real PokéSearch. The real queue
    // manager is put back afterwards, so the ingests below run their own jobs.
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

afterEach(function (): void {
    foreach (frxRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

it('reads nothing of a failed run when a late day recomputes the window around it, and loses no close of a day with an ok run', function (): void {
    // 10-10, 10-12 and 10-14 are ingested ok; with $failed, the queued run of 10-11 crashes in its third chunk between
    // them. Then 10-09 arrives late: its window runs from 10-09 to 10-14, across the failed day and every ok day.
    $history = function (bool $failed): array {
        frxIngest(['--day' => '2026-10-10', '--sync' => true]);
        if ($failed) {
            frxCrashOn("UPDATE ingest_run_chunks SET status = 'done'", 3);
            frxIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync']);
        }
        frxIngest(['--day' => '2026-10-12', '--sync' => true]);
        frxIngest(['--day' => '2026-10-14', '--sync' => true]);
        $before = frxRealCloses(['2026-10-10', '2026-10-12', '2026-10-14']);
        $late = frxIngest(['--day' => '2026-10-09', '--sync' => true]);

        return [
            'late' => $late,
            'before' => $before,
            'after' => frxRealCloses(['2026-10-10', '2026-10-12', '2026-10-14']),
            'failed_points' => frxObserved('2026-10-11'),
            'failed_status' => DB::table('ingest_runs')->where('day', '2026-10-11')->orderByDesc('id')->value('status'),
            'real_of_failed_day' => DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', false)->count(),
            'real' => array_map(fn (string $day): int => DB::table('price_daily')->where('day', $day)->where('is_filled', false)->count(), ['2026-10-09', '2026-10-10', '2026-10-12', '2026-10-14']),
            'observed' => array_map(frxObserved(...), ['2026-10-09', '2026-10-10', '2026-10-12', '2026-10-14']),
            'derived' => frxDerived(),
        ];
    };

    $withFailure = $history(true);
    frxWipe();
    $neverCollected = $history(false);

    expect([$withFailure['late'], $withFailure['failed_status']])->toBe([0, 'failed'])
        ->and($withFailure['failed_points'])->toBeGreaterThan(0)
        ->and($neverCollected['failed_points'])->toBe(0)
        ->and($withFailure['real_of_failed_day'])->toBe(0)
        // Every day with an ok run keeps one real close per observed variant, and the late day's window rewrites none.
        ->and($withFailure['real'])->toBe($withFailure['observed'])
        ->and(min($withFailure['real']))->toBeGreaterThan(0)
        ->and($withFailure['after'])->toBe($withFailure['before'])
        ->and($withFailure['derived'])->toBe($neverCollected['derived']);
});

it('gives a day collected again under a new marker after its run failed exactly the closes of the new folder', function (): void {
    $older = '2026-10-11 20:05:45+00';
    $newer = '2026-10-11 22:30:00+00';
    $root = frxRecollected('2026-10-11', '2026-10-11T22:30:00+0000');

    frxIngest(['--day' => '2026-10-10', '--sync' => true]);
    frxCrashOn("UPDATE ingest_run_chunks SET status = 'done'", 3);
    frxIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync']);
    frxIngest(['--day' => '2026-10-12', '--sync' => true]);
    $olderPoints = DB::table('price_points')->where('observed_at', $older)->count();
    $nextBefore = frxRealCloses(['2026-10-12']);

    // The collector wrote the folder again; the start of the new marker runs through the queued path.
    $code = frxIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync', '--snapshots' => $root]);
    $status = DB::table('ingest_runs')->where('day', '2026-10-11')->orderByDesc('id')->value('status');
    $markers = DB::table('price_points')->where('day', '2026-10-11')->distinct()->pluck('observed_at')->all();
    $asOf = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', false)->distinct()->pluck('as_of')->all();
    $real = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', false)->count();
    $observed = frxObserved('2026-10-11');
    $nextAfter = frxRealCloses(['2026-10-12']);
    $recovered = frxDerived();

    frxWipe();
    frxIngest(['--day' => '2026-10-10', '--sync' => true]);
    frxIngest(['--day' => '2026-10-11', '--sync' => true, '--snapshots' => $root]);
    frxIngest(['--day' => '2026-10-12', '--sync' => true]);
    $clean = frxDerived();

    expect($olderPoints)->toBeGreaterThan(0)
        ->and([$code, $status])->toBe([0, 'ok'])
        ->and($markers)->toBe([$newer])
        ->and($asOf)->toBe([$newer])
        ->and($real)->toBe($observed)
        ->and($real)->toBeGreaterThan(0)
        ->and($nextAfter)->toBe($nextBefore)
        ->and($recovered['price_daily'])->toBe($clean['price_daily'])
        ->and($recovered['price_latest'])->toBe($clean['price_latest'])
        ->and($recovered['price_movers'])->toBe($clean['price_movers']);
});

it('gives a day no close while its run has every chunk done but finalize failed, and all of them once the run is resumed', function (): void {
    frxIngest(['--day' => '2026-10-10', '--sync' => true]);
    // Every chunk of 10-11 commits; the rollup inside finalize crashes, so the run never goes ok.
    frxCrashOn('INSERT INTO price_daily', 1);
    frxIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync']);
    $runId = (int) DB::table('ingest_runs')->where('day', '2026-10-11')->orderByDesc('id')->value('id');
    $status = DB::table('ingest_runs')->where('id', $runId)->value('status');
    $open = DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', '<>', 'done')->count();
    $observed = frxObserved('2026-10-11');

    frxIngest(['--day' => '2026-10-12', '--sync' => true]);
    $realWhileUnfinished = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', false)->count();
    $filledWhileUnfinished = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', true)->count();
    $nextReal = DB::table('price_daily')->where('day', '2026-10-12')->where('is_filled', false)->count();

    $resume = frxIngest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync', '--wait' => 30]);
    $resumed = DB::table('ingest_runs')->where('id', $runId)->value('status');
    $realResumed = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', false)->count();
    $recovered = PriceDigest::compute(DB::connection())['tables'];

    frxWipe();
    foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        frxIngest(['--day' => $day, '--sync' => true]);
    }

    expect([$status, $open])->toBe(['failed', 0])
        ->and($observed)->toBeGreaterThan(0)
        ->and($realWhileUnfinished)->toBe(0)
        ->and($filledWhileUnfinished)->toBeGreaterThan(0)
        ->and($nextReal)->toBe(frxObserved('2026-10-12'))
        ->and([$resume, $resumed])->toBe([0, 'ok'])
        ->and($realResumed)->toBe($observed)
        ->and($recovered)->toBe(PriceDigest::compute(DB::connection())['tables']);
});
