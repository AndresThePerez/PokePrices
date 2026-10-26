<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\StartDayIngest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// One running run per (source, day) and bounded lock waits (plan Q1-i1 T02; review b B3, B6, b2). The fixture tree
// is only ever read.

const Q1G_WORK = ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--memory' => 4096, '--sleep' => 0];

function q1gSnapshots(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

/** Holds the pricing:ingest session lock on a second connection while $during runs. */
function q1gWithLockHeld(callable $during): mixed
{
    config(['database.connections.q1g_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('q1g_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        return $during();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('q1g_holder');
    }
}

/** A queued run row of 2026-10-11 in $status, returning its id. */
function q1gRun(string $status): int
{
    return DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-11',
        'marker' => '2026-10-11 20:05:45+00', 'mode' => 'queued', 'status' => $status, 'started_at' => '2026-10-11 21:00:00+00']);
}

/** Runs the schedule event named $name's callback, as the scheduler does on a tick. */
function q1gTick(string $name): void
{
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === $name);
    $callback = (fn () => $this->callback)->call($event);
    app()->call($callback);
}

// B3: the run status is read before the lock, and the lock wait is bounded.

test('FinalizeDay of a run that is no longer running returns at once while another session holds the ingest lock', function (string $status): void {
    $runId = q1gRun($status);
    // Only so the test cannot hang on the old lock-first order; production sets no lock_timeout.
    DB::statement("SET lock_timeout = '1500ms'");

    [$ms, $thrown] = q1gWithLockHeld(function () use ($runId): array {
        $started = hrtime(true);
        $thrown = null;
        DB::statement('SAVEPOINT q1g');
        try {
            (new FinalizeDay($runId, 'sync'))->handle();
        } catch (Throwable $e) {
            $thrown = $e::class.': '.$e->getMessage();
            DB::statement('ROLLBACK TO SAVEPOINT q1g');
        }

        return [intdiv(hrtime(true) - $started, 1_000_000), $thrown];
    });

    expect($thrown)->toBeNull()
        ->and($ms)->toBeLessThan(1000)
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('status'))->toBe($status);
})->with(['superseded', 'ok', 'failed']);

test('FinalizeDay of a running run releases itself without waiting or spending a real-failure try when the lock is held', function (): void {
    $runId = q1gRun('running');
    $now = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    DB::statement("SET lock_timeout = '1500ms'");
    Queue::connection('database')->push(new FinalizeDay($runId, 'database'));

    [$ms, $job] = q1gWithLockHeld(function (): array {
        $started = hrtime(true);
        Artisan::call('queue:work', Q1G_WORK);

        return [intdiv(hrtime(true) - $started, 1_000_000), DB::table('jobs')->first()];
    });
    $payload = json_decode((string) $job->payload, true, 512, JSON_THROW_ON_ERROR);
    CarbonImmutable::setTestNow();

    expect($ms)->toBeLessThan(1000)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('status'))->toBe('running')
        ->and([$job->attempts, $job->available_at])->toBe([1, $now->addSeconds(60)->getTimestamp()])
        // Contention retries until a deadline hours away; three real exceptions still fail the run.
        ->and($payload['maxExceptions'])->toBe(3)
        ->and($payload['retryUntil'])->toBeGreaterThanOrEqual($now->addHours(6)->getTimestamp());
});

// B6: a second start of a day with a running run creates nothing.

test('two StartDayIngest begins of one day leave exactly one running run, one chunk plan and one batch', function (): void {
    $first = (new StartDayIngest('2026-10-11', q1gSnapshots(), false, 'database'))->begin();
    $chunks = DB::table('ingest_run_chunks')->count();
    $jobs = DB::table('jobs')->count();
    $second = (new StartDayIngest('2026-10-11', q1gSnapshots(), false, 'database'))->begin();

    expect($first['status'])->toBe('running')
        ->and($chunks)->toBeGreaterThan(0)
        ->and($second['status'])->toBe('locked')
        ->and($second['run_id'])->toBe($first['run_id'])
        ->and($second['chunks'])->toBe([])
        ->and(DB::table('ingest_runs')->pluck('id')->all())->toBe([$first['run_id']])
        ->and(DB::table('ingest_run_chunks')->count())->toBe($chunks)
        ->and(DB::table('ingest_run_chunks')->distinct()->pluck('ingest_run_id')->all())->toBe([$first['run_id']])
        ->and(DB::table('job_batches')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe($jobs);
});

test('a sync ingest or a queued --force start of a day with a running run exits 1 and writes no run', function (array $options): void {
    $first = (new StartDayIngest('2026-10-11', q1gSnapshots(), false, 'database'))->begin();

    $code = Artisan::call('pokeprices:prices:ingest', $options + ['--day' => '2026-10-11', '--snapshots' => q1gSnapshots(), '--queue-connection' => 'database']);
    $output = Artisan::output();

    expect($code)->toBe(1, $output)
        ->and($output)->toContain("run {$first['run_id']}")
        ->and(DB::table('ingest_runs')->pluck('id')->all())->toBe([$first['run_id']])
        ->and(DB::table('job_batches')->count())->toBe(1);
})->with([
    'sync' => [['--sync' => true]],
    'sync --force' => [['--sync' => true, '--force' => true]],
    'queued --force' => [['--queued' => true, '--force' => true]],
]);

test('a queued StartDayIngest of a day with a running run is done without a release or a second run', function (): void {
    $first = (new StartDayIngest('2026-10-11', q1gSnapshots(), false, 'database'))->begin();
    DB::table('jobs')->delete();
    Queue::connection('database')->push(new StartDayIngest('2026-10-11', q1gSnapshots(), false, 'database'));

    Artisan::call('queue:work', Q1G_WORK);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('ingest_runs')->pluck('id')->all())->toBe([$first['run_id']]);
});

// b2: two scheduler ticks queue one scan and one dispatch, not two.

test('two ticks of the pending scan and of the alerts dispatch each queue one job', function (): void {
    Queue::fake();

    q1gTick('pokeprices-ingest-pending');
    q1gTick('pokeprices-ingest-pending');
    q1gTick('pokeprices-alerts-dispatch');
    q1gTick('pokeprices-alerts-dispatch');

    Queue::assertPushed(IngestPending::class, 1);
    Queue::assertPushed(DispatchEvents::class, 1);
    expect((new IngestPending)->uniqueFor)->toBeGreaterThan(0)->toBeLessThan(3600)
        ->and((new DispatchEvents)->uniqueFor)->toBeGreaterThan(0)->toBeLessThan(300);
});
