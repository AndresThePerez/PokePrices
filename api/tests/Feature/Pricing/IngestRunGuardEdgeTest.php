<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\Replay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Adversarial edge tests for plan Q1-i1 T02 (reviewer round 1): contention versus real failures, the replay race
// inside FinalizeDay, the over-breadth of the running-run guard, unique-job lock lifetime and the replay interlock.

const Q1E_WORK = ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--memory' => 4096, '--sleep' => 0];

function q1eSnapshots(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

function q1eRun(string $status, string $day = '2026-10-11'): int
{
    return DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day,
        'marker' => $day.' 20:05:45+00', 'mode' => 'queued', 'status' => $status, 'started_at' => $day.' 21:00:00+00']);
}

function q1eWork(): void
{
    Artisan::call('queue:work', Q1E_WORK);
    // The test waits for no delay: the next worker pass sees the released job at once.
    DB::table('jobs')->update(['available_at' => 0]);
}

test('contention releases never spend the exception budget: after five released passes three real finalize failures still fail the run', function (): void {
    $runId = q1eRun('running');
    // A pending chunk makes DayIngest::finalize throw once the lock is free: a real, countable failure.
    DB::table('ingest_run_chunks')->insert(['ingest_run_id' => $runId, 'chunk_key' => '1:0', 'group_id' => 1, 'offset' => 0, 'rows' => 1, 'status' => 'pending']);
    Queue::connection('database')->push(new FinalizeDay($runId, 'database'));

    config(['database.connections.q1e_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('q1e_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        for ($pass = 0; $pass < 5; $pass++) {
            q1eWork();
        }
        $heldJob = DB::table('jobs')->first();
        $heldFailed = DB::table('failed_jobs')->count();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('q1e_holder');
    }

    for ($pass = 0; $pass < 3; $pass++) {
        q1eWork();
    }
    $run = DB::table('ingest_runs')->where('id', $runId)->first();

    expect($heldFailed)->toBe(0)
        ->and($heldJob->attempts)->toBe(5)
        ->and($run->status)->toBe('failed')
        ->and((string) $run->error)->toStartWith('finalize failed:')
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('FinalizeDay does not finalize, throw or release when a replay supersedes the run between its first read and the lock', function (): void {
    $runId = q1eRun('running');
    $fired = false;
    DB::listen(function ($query) use ($runId, &$fired): void {
        if (! $fired && str_contains($query->sql, 'pg_try_advisory_xact_lock')) {
            $fired = true;
            DB::table('ingest_runs')->where('id', $runId)->update(['status' => 'superseded']);
        }
    });
    $finalize = new FinalizeDay($runId, 'database');
    $finalize->withFakeQueueInteractions();

    $thrown = null;
    try {
        $finalize->handle();
    } catch (Throwable $e) {
        $thrown = $e::class.': '.$e->getMessage();
    }

    $finalize->assertNotReleased();
    expect($fired)->toBeTrue()
        ->and($thrown)->toBeNull()
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('status'))->toBe('superseded')
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('finished_at'))->toBeNull();
});

test('the running-run guard blocks only the same day: a running run of another day and a settled run of the same day do not block a start', function (string $status, string $day): void {
    $other = q1eRun($status, $day);
    $result = (new StartDayIngest('2026-10-11', q1eSnapshots(), false, 'database'))->begin();

    expect($result['status'])->toBe('running')
        ->and($result['run_id'])->not->toBe($other)
        ->and($result['chunks'])->not->toBe([])
        ->and(DB::table('ingest_runs')->where('day', '2026-10-11')->where('status', 'running')->count())->toBe(1);
})->with([
    'another day, running' => ['running', '2026-10-10'],
    'same day, failed' => ['failed', '2026-10-11'],
    'same day, superseded' => ['superseded', '2026-10-11'],
    'same day, refused' => ['refused', '2026-10-11'],
]);

test('a replay still takes over a day that has a running queued run: the run is superseded and the replay ends ok', function (): void {
    $first = (new StartDayIngest('2026-10-11', q1eSnapshots(), false, 'database'))->begin();

    $result = (new Replay(DB::connection(), q1eSnapshots()))->run(['2026-10-11'], 'asc');

    expect($first['status'])->toBe('running')
        ->and($result['status'])->toBe('ok', (string) $result['error'])
        ->and($result['identical'])->toBeTrue()
        ->and(DB::table('ingest_runs')->where('id', $first['run_id'])->value('status'))->toBe('superseded')
        ->and(DB::table('ingest_runs')->where('status', 'running')->count())->toBe(0);
});

test('a unique scan or dispatch is one job whatever its constructor arguments, and its lock is gone once the job has run', function (): void {
    IngestPending::dispatch('/nonexistent/q1e-a', 'database')->onConnection('database');
    IngestPending::dispatch('/nonexistent/q1e-b', 'redis', 'pgsql')->onConnection('database');
    $queuedTwice = DB::table('jobs')->count();

    Artisan::call('queue:work', Q1E_WORK);
    $afterRun = DB::table('jobs')->count();
    $failed = DB::table('failed_jobs')->count();

    IngestPending::dispatch('/nonexistent/q1e-c', 'database')->onConnection('database');
    $queuedAgain = DB::table('jobs')->count();

    DispatchEvents::dispatch()->onConnection('database');
    DispatchEvents::dispatch()->onConnection('database');
    $dispatchQueued = DB::table('jobs')->where('payload', 'like', '%DispatchEvents%')->count();

    expect($queuedTwice)->toBe(1)
        ->and($failed)->toBe(0)
        ->and($afterRun)->toBe(0)
        ->and($queuedAgain)->toBe(1)
        ->and($dispatchQueued)->toBe(1);
});

test('every class in app/ that uses the Queueable trait is a ShouldQueue class, so no job silently runs inline without a queue policy', function (): void {
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file->getFilename(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents((string) $file->getPathname());
        if (preg_match('/^\s*use\s+(?:[\w\\\\, ]*\b)?Queueable\b[^;]*;/m', $source) !== 1) {
            continue;
        }
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1 && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls) === 1) {
            $found[] = $ns[1].'\\'.$cls[1];
        }
    }

    expect($found)->not->toBe([]);
    foreach ($found as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue("{$class} uses Queueable but is not ShouldQueue");
    }
});
