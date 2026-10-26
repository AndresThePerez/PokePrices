<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\PriceDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for the queued ingest (plan P2-i1 T08): redelivery and double finalize, a failure in the middle of
// finalize, the lock a replay holds, a stalled-run resume beside a live batch, a dispatch that could not reach the
// queue, the --wait bound, the fractional-second marker on a forced re-ingest, and a failed run recovered by the scan.

const T08E_WORK = ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--memory' => 4096, '--sleep' => 0];

function t08eFixture(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

/** A writable copy of one fixture day under a scratch root that afterEach removes. */
function t08eCopy(string $day): string
{
    $root = storage_path('framework/testing/pp-t08e-'.bin2hex(random_bytes(6)));
    mkdir("{$root}/{$day}", 0775, true);
    t08eRoots($root);
    $from = t08eFixture().'/'.$day;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
    }

    return $root;
}

function t08eRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** @return array{0: int, 1: string, 2: array<string, mixed>|null} */
function t08eIngest(array $options, ?string $root = null): array
{
    $code = Artisan::call('pokeprices:prices:ingest', $options + ['--snapshots' => $root ?? t08eFixture()]);
    $output = Artisan::output();
    $json = null;
    foreach (array_reverse(explode("\n", trim($output))) as $line) {
        if (str_starts_with(trim($line), '{')) {
            $json = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            break;
        }
    }

    return [$code, $output, $json];
}

function t08eWipe(): void
{
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

function t08eCounts(int $runId): array
{
    $counts = json_decode((string) DB::table('ingest_runs')->where('id', $runId)->value('counts'), true, 512, JSON_THROW_ON_ERROR);
    unset($counts['map_build_id']);
    ksort($counts);

    return $counts;
}

/** The sync ingest of a day on a clean database: [the run's counts, the table digests]. The caller restores its own state. */
function t08eSyncReference(string $day, ?string $root = null): array
{
    t08eWipe();
    [$code, $output, $line] = t08eIngest(['--day' => $day, '--sync' => true], $root);
    if ($code !== 0) {
        throw new RuntimeException('the sync reference failed: '.$output);
    }

    return [t08eCounts($line['run_id']), PriceDigest::compute(DB::connection())];
}

/** Opens a queued run on the database queue and drops its jobs, so the test runs the chunks itself. */
function t08eOpenRun(string $day, string $root): array
{
    $start = (new StartDayIngest($day, $root, false, 'database'))->begin();
    DB::table('jobs')->delete();

    return $start;
}

function t08eRunAllChunks(array $start, string $root): void
{
    $ingest = new DayIngest(DB::connection(), $root);
    foreach ($start['chunks'] as $chunkId) {
        $ingest->chunk($start['run_id'], $chunkId);
    }
}

beforeEach(function (): void {
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
    CarbonImmutable::setTestNow();
    foreach (t08eRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('a chunk job delivered a second time after it was done keeps its counts, so the run sums each chunk once', function (): void {
    $root = t08eFixture();
    $start = t08eOpenRun('2026-10-11', $root);
    t08eRunAllChunks($start, $root);
    $first = DB::table('ingest_run_chunks')->where('id', $start['chunks'][0])->value('counts');

    // At-least-once delivery: the same chunk job arrives again (e.g. a visibility timeout) before finalize.
    (new DayIngest(DB::connection(), $root))->chunk($start['run_id'], $start['chunks'][0]);
    $second = DB::table('ingest_run_chunks')->where('id', $start['chunks'][0])->value('counts');
    (new FinalizeDay($start['run_id'], 'database'))->handle();
    $queued = t08eCounts($start['run_id']);

    [$syncCounts] = t08eSyncReference('2026-10-11');

    expect(json_decode((string) $second, true))->toBe(json_decode((string) $first, true))
        ->and($queued)->toBe($syncCounts);
});

test('finalize delivered twice leaves the run, its counts and the price tables as the first one left them', function (): void {
    $root = t08eFixture();
    $start = t08eOpenRun('2026-10-11', $root);
    t08eRunAllChunks($start, $root);

    (new FinalizeDay($start['run_id'], 'database'))->handle();
    $run = (array) DB::table('ingest_runs')->where('id', $start['run_id'])->first();
    $digest = PriceDigest::compute(DB::connection());
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));
    (new FinalizeDay($start['run_id'], 'database'))->handle();

    expect($run['status'])->toBe('ok')
        ->and((array) DB::table('ingest_runs')->where('id', $start['run_id'])->first())->toBe($run)
        ->and(PriceDigest::compute(DB::connection()))->toBe($digest);
});

test('a finalize that dies on its last write rolls back whole, and its retry sums the chunks once', function (): void {
    $root = t08eFixture();
    $start = t08eOpenRun('2026-10-11', $root);
    t08eRunAllChunks($start, $root);
    $before = PriceDigest::compute(DB::connection())['tables'];

    $armed = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed): void {
        if ($armed && str_starts_with($query->sql, 'update "ingest_runs" set "status"')) {
            $armed = false;
            throw new RuntimeException('transient failure at the end of finalize');
        }
    });
    $failure = null;
    try {
        (new FinalizeDay($start['run_id'], 'database'))->handle();
    } catch (RuntimeException $e) {
        $failure = $e->getMessage();
    }
    $mid = DB::table('ingest_runs')->where('id', $start['run_id'])->value('status');
    $afterFailure = PriceDigest::compute(DB::connection())['tables'];

    (new FinalizeDay($start['run_id'], 'database'))->handle();
    $retried = t08eCounts($start['run_id']);
    $queuedTables = PriceDigest::compute(DB::connection());
    [$syncCounts, $syncDigest] = t08eSyncReference('2026-10-11');

    expect($failure)->toBe('transient failure at the end of finalize')
        ->and($mid)->toBe('running')
        ->and($afterFailure['price_daily'])->toBe($before['price_daily'])
        ->and($afterFailure['price_latest'])->toBe($before['price_latest'])
        ->and($retried)->toBe($syncCounts)
        ->and($queuedTables)->toBe($syncDigest);
});

test('finalize does not proceed while another session holds the pricing:ingest lock, as a replay does (D12)', function (): void {
    $root = t08eFixture();
    $start = t08eOpenRun('2026-10-11', $root);
    t08eRunAllChunks($start, $root);

    config(['database.connections.t08e_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t08e_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    DB::statement("SET lock_timeout = '300ms'");
    $blocked = null;
    try {
        (new FinalizeDay($start['run_id'], 'database'))->handle();
    } catch (QueryException $e) {
        $blocked = $e->getMessage();
    } finally {
        DB::statement('SET lock_timeout = 0');
    }
    $during = [DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'), DB::table('price_daily')->count()];
    $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
    DB::purge('t08e_holder');
    (new FinalizeDay($start['run_id'], 'database'))->handle();

    // Q1-i1 T02 (B3): finalize no longer waits on the lock; it tries it, finds it held and writes nothing.
    expect($blocked)->toBeNull()
        ->and($during)->toBe(['running', 0])
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('ok');
});

test('a stalled-run resume beside the first batch still queued ends ok with the sync counts and digest', function (): void {
    $root = t08eCopy('2026-10-11');
    $now = CarbonImmutable::parse('2026-10-15 09:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    (new StartDayIngest('2026-10-11', $root, false, 'database'))->begin();
    $runId = (int) DB::table('ingest_runs')->value('id');
    expect(DB::table('jobs')->count())->toBe(9);

    // The worker is slow, not dead: at 2 h 01 m the scan resumes the run while the first batch's jobs still wait.
    CarbonImmutable::setTestNow($now->addMinutes(121));
    $summary = (new IngestPending($root, 'database'))->scan();
    $queuedJobs = DB::table('jobs')->count();
    Artisan::call('queue:work', T08E_WORK);
    $counts = t08eCounts($runId);
    $status = DB::table('ingest_runs')->where('id', $runId)->value('status');
    $failedJobs = DB::table('failed_jobs')->count();
    $digest = PriceDigest::compute(DB::connection());

    [$syncCounts, $syncDigest] = t08eSyncReference('2026-10-11', $root);

    expect($summary['resumed'])->toBe([$runId])
        ->and($queuedJobs)->toBe(18)
        ->and($status)->toBe('ok')
        ->and($failedJobs)->toBe(0)
        ->and($digest)->toBe($syncDigest)
        ->and($counts)->toBe($syncCounts);
});

test('a dispatch that cannot reach the queue leaves the run running with its error, releases the lock, and --resume completes it', function (): void {
    $root = t08eFixture();
    $start = (new StartDayIngest('2026-10-11', $root, false, 'no_such_connection'))->begin();

    config(['database.connections.t08e_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('t08e_probe');
    $row = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS taken', [IngestLock::KEY], false);
    $lockFree = $row->taken ?? false;
    $probe->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
    DB::purge('t08e_probe');

    $status = DB::table('ingest_runs')->where('id', $start['run_id'])->value('status');
    $pending = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('status', 'pending')->count();
    [$code, $output, $line] = t08eIngest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync', '--wait' => 30]);
    $digest = PriceDigest::compute(DB::connection());
    [, $syncDigest] = t08eSyncReference('2026-10-11');

    expect($start['status'])->toBe('running')
        ->and($start['error'])->not->toBeNull()
        ->and($lockFree)->toBeTrue()
        ->and($status)->toBe('running')
        ->and($pending)->toBe(9)
        ->and($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['run_id' => $start['run_id'], 'status' => 'ok'])
        ->and($digest)->toBe($syncDigest);
});

test('--wait=0 reports a run still on the queue as running with exit 1, and a non-numeric or negative --wait is refused', function (): void {
    [$zeroCode, $zeroOutput, $zeroLine] = t08eIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'database', '--wait' => '0']);
    $jobs = DB::table('jobs')->count();
    [$negCode, $negOutput] = t08eIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'database', '--wait' => '-1']);
    [$wordCode, $wordOutput] = t08eIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'database', '--wait' => 'soon']);

    expect($zeroCode)->toBe(1, $zeroOutput)
        ->and($zeroLine)->toMatchArray(['day' => '2026-10-11', 'status' => 'running'])
        ->and($zeroOutput)->toContain('still running after --wait=0')
        ->and($jobs)->toBe(9)
        ->and($negCode)->toBe(1, $negOutput)
        ->and($negOutput)->toContain('--wait must be a whole number of seconds')
        ->and($wordCode)->toBe(1, $wordOutput)
        ->and(DB::table('ingest_runs')->count())->toBe(1);
});

test('a forced queued re-ingest of a fractional-second marker rewrites nothing: upserted 0, no older-marker delete, same digest', function (): void {
    $root = t08eCopy('2026-10-11');
    file_put_contents("{$root}/2026-10-11/marker.txt", "2026-10-11T20:05:45.678+0000\n");
    $manifest = json_decode((string) file_get_contents("{$root}/2026-10-11/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $manifest['marker'] = '2026-10-11T20:05:45.678+0000';
    file_put_contents("{$root}/2026-10-11/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));

    [$firstCode, $firstOutput, $first] = t08eIngest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync', '--wait' => 30], $root);
    $digest = PriceDigest::compute(DB::connection());
    [$secondCode, $secondOutput, $second] = t08eIngest(['--day' => '2026-10-11', '--queued' => true, '--force' => true, '--queue-connection' => 'sync', '--wait' => 30], $root);
    $counts = t08eCounts($second['run_id']);

    expect($firstCode)->toBe(0, $firstOutput)
        ->and($first['status'])->toBe('ok')
        ->and($secondCode)->toBe(0, $secondOutput)
        ->and($second)->toMatchArray(['status' => 'ok', 'upserted' => 0, 'mapped' => $first['mapped'], 'unchanged' => $first['mapped']])
        ->and($counts['deleted_older_marker'])->toBe(0)
        ->and(PriceDigest::compute(DB::connection()))->toBe($digest)
        ->and(DB::table('price_points')->where('observed_at', '2026-10-11 20:05:45.678+00')->count())->toBe(0);
});

test('a queued run that failed on a chunk is recovered by the next scan as a fresh run, and the failed run stays on record', function (): void {
    $root = t08eCopy('2026-10-11');
    $original = (string) file_get_contents("{$root}/2026-10-11/prices/23821.json.zst");
    $start = (new StartDayIngest('2026-10-11', $root, false, 'database'))->begin();
    DB::table('jobs')->delete();
    $document = json_decode((string) zstd_uncompress($original), true, 512, JSON_THROW_ON_ERROR);
    array_pop($document['results']);
    file_put_contents("{$root}/2026-10-11/prices/23821.json.zst", zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
    [$resumeCode] = t08eIngest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync'], $root);
    $failedStatus = DB::table('ingest_runs')->where('id', $start['run_id'])->value('status');

    // The folder is healthy again (the file is back); the next hourly scan picks the failed marker up.
    file_put_contents("{$root}/2026-10-11/prices/23821.json.zst", $original);
    $summary = (new IngestPending($root, 'sync'))->scan();
    $digest = PriceDigest::compute(DB::connection());
    $runs = array_map(fn (object $r): array => [$r->mode, $r->status], DB::select('SELECT mode, status FROM ingest_runs ORDER BY id'));
    [, $syncDigest] = t08eSyncReference('2026-10-11', $root);

    expect($resumeCode)->toBe(1)
        ->and($failedStatus)->toBe('failed')
        ->and($summary['dispatched'])->toBe(['2026-10-11'])
        ->and($runs)->toBe([['queued', 'failed'], ['queued', 'ok']])
        ->and($digest)->toBe($syncDigest);
});

test('Q1-i1 T03: a map build that lands while a queued run is open fails the run through its chunk, and the next scan ingests the day on the new build', function (): void {
    $root = t08eCopy('2026-10-11');
    $start = (new StartDayIngest('2026-10-11', $root, false, 'database'))->begin();
    DB::table('jobs')->delete();
    $newer = (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
    [$resumeCode] = t08eIngest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync'], $root);
    $failed = DB::table('ingest_runs')->where('id', $start['run_id'])->first(['status', 'error']);
    $pointsOfFailedRun = DB::table('price_points')->where('ingest_run_id', $start['run_id'])->count();

    $summary = (new IngestPending($root, 'sync'))->scan();
    $fresh = DB::table('ingest_runs')->where('id', '>', $start['run_id'])->orderBy('id')->get(['mode', 'status', 'counts']);

    expect($resumeCode)->toBe(1)
        ->and($failed->status)->toBe('failed')
        ->and((string) $failed->error)->toContain("the product map changed since ingest run {$start['run_id']} started")
        ->and($pointsOfFailedRun)->toBe(0)
        ->and($summary['dispatched'])->toBe(['2026-10-11'])
        ->and($fresh->map(fn (object $r): array => [$r->mode, $r->status])->all())->toBe([['queued', 'ok']])
        ->and(json_decode((string) $fresh[0]->counts, true, 512, JSON_THROW_ON_ERROR)['map_build_id'])->toBe($newer);
});

test('the :40 scan is registered for the scheduler, and the scan job serializes for the queue without a captured closure', function (): void {
    $code = Artisan::call('schedule:list');
    $output = Artisan::output();
    $scan = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'pokeprices-ingest-pending');
    $job = new IngestPending(t08eFixture(), 'sync');
    $restored = unserialize(serialize($job));

    expect($code)->toBe(0)
        // P5-i1 T01's */5 entry widens schedule:list's columns, so the expression is read from the event (R233).
        ->and($scan->expression)->toBe('40 * * * *')
        ->and($output)->toContain('pokeprices-ingest-pending')
        ->and($output)->toContain('pokeprices-probe')
        ->and($scan->withoutOverlapping)->toBeTrue()
        ->and($restored)->toEqual($job)
        ->and($restored->scan())->toBe(['skipped' => false, 'dispatched' => ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'], 'resumed' => []]);
});
