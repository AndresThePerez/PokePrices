<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\IngestChunk;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\PriceDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Failure injection for the PokéPrices ingest (plan P2-i1 T09): every case breaks a scratch copy of the synthetic days
// (or the database under it) and asserts the end state, not only the exception: the run's status and error, which
// price tables changed (PriceDigest before and after), that the fixture tree is byte-unchanged, that the
// pricing:ingest lock is free again, and the recovery to the digest of a clean ingest.
//
// Queued cases run for real (review F4): they go on the `database` queue connection and are driven by queue:work with
// --stop-when-empty --tries=3 --backoff=0, stepping the clock past the jobs' own #[Backoff]. The forced `sync`
// connection is used only where a case asks for an inline, non-retried failure. Faults exist only in the test: a
// query listener or a scratch file, never a production flag.
//
// Where an earlier suite already proves the bare refusal (DayIngestTest (c), (h), (l); QueuedIngestTest's lock and
// scan tests; ReplayTest's lock test), the case here adds what that suite leaves out: a prior state that must stay
// untouched, the queued mode, lock freedom and the recovery.

const T09_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

// T08's worker call; --memory lifts the 128 MB default a long Pest process passes, --sleep 0 skips the idle second.
const T09_WORK = ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--memory' => 4096, '--sleep' => 0];

const T09_TABLES = ['price_points', 'price_daily', 'price_latest'];

function t09Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots'.($path === '' ? '' : '/'.$path));
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t09Roots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Copies (or re-copies) one fixture day into a scratch root, file by file. */
function t09Restore(string $root, string $day): void
{
    $from = t09Fixture($day);
    if (! is_dir("{$root}/{$day}")) {
        mkdir("{$root}/{$day}", 0775, true);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        if ($file->isDir()) {
            is_dir($to) || mkdir($to, 0775, true);
        } else {
            copy((string) $file->getPathname(), $to);
        }
    }
}

/** A writable copy of the named fixture days under a scratch root that afterEach removes. */
function t09Copy(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t09-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t09Roots($root);
    foreach ($days as $day) {
        t09Restore($root, $day);
    }

    return $root;
}

/** Every file under $dir as relative path => sha1. */
function t09Tree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/**
 * Runs pokeprices:prices:ingest and returns [exit code, output, the last JSON line or null].
 *
 * @return array{0: int, 1: string, 2: array<string, mixed>|null}
 */
function t09Ingest(array $options, string $root): array
{
    $code = Artisan::call('pokeprices:prices:ingest', $options + ['--snapshots' => $root]);
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

/** Empties the price and run tables. */
function t09Wipe(): void
{
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    // A fresh start empties the price_events outbox too (T13): only a test ever deletes events, never ingestion.
    DB::table('price_events')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

/** The PriceDigest tables: price_points, price_daily and price_latest, each {rows, sha256}. */
function t09Tables(): array
{
    return PriceDigest::compute(DB::connection())['tables'];
}

/** The ingest_runs rows as id => [day, mode, status]. */
function t09Runs(): array
{
    $out = [];
    foreach (DB::select('SELECT id, day::text AS day, mode, status FROM ingest_runs ORDER BY id') as $r) {
        $out[(int) $r->id] = [$r->day, $r->mode, $r->status];
    }

    return $out;
}

/** The newest ingest_runs row. */
function t09LastRun(): object
{
    $run = DB::table('ingest_runs')->orderByDesc('id')->first();
    if ($run === null) {
        throw new RuntimeException('no ingest run was written');
    }

    return $run;
}

/** A run's counts without the map build id, keys sorted. */
function t09RunCounts(int $runId): array
{
    $counts = json_decode((string) DB::table('ingest_runs')->where('id', $runId)->value('counts'), true, 512, JSON_THROW_ON_ERROR);
    unset($counts['map_build_id']);
    ksort($counts);

    return $counts;
}

/** The per-chunk counts of a run's done chunks, summed, keys sorted. */
function t09ChunkSums(int $runId): array
{
    $sums = ['mapped' => 0, 'null_market' => 0, 'unchanged' => 0, 'unmapped' => 0, 'upserted' => 0];
    foreach (DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', 'done')->pluck('counts') as $json) {
        foreach (json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR) as $key => $value) {
            $sums[$key] += $value;
        }
    }

    return $sums;
}

/** A run's chunks in id order as [status, attempts]. */
function t09Chunks(int $runId): array
{
    return array_map(fn (object $c): array => [$c->status, (int) $c->attempts],
        DB::select('SELECT status, attempts FROM ingest_run_chunks WHERE ingest_run_id = ? ORDER BY id', [$runId]));
}

/**
 * Whether no session-level hold of the pricing:ingest lock is left behind: another session can take the lock (it is
 * given back at once), or the only holder is this test's own session through FinalizeDay's pg_advisory_xact_lock.
 * RefreshDatabase wraps the test in one transaction, so the finalize transaction is a savepoint there and its xact
 * lock lasts until the test's transaction rolls back; on a worker it is a top-level transaction whose end frees it.
 * pg_advisory_unlock() on the own session returns false exactly when it holds no session-level lock. Advisory locks
 * are per database, so only this database's holders count (parallel Pest runs one test database per process).
 */
function t09LockFree(): bool
{
    config(['database.connections.t09_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('t09_probe');
    $taken = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS taken', [IngestLock::KEY])->taken ?? false;
    $probe->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t09_probe');
    if ($taken === true) {
        return true;
    }

    $own = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $holders = array_map(fn (object $r): int => (int) $r->pid, DB::select(
        "SELECT pid FROM pg_locks WHERE locktype = 'advisory' AND granted AND classid::bigint = 0 AND objid::bigint = ? AND objsubid = 1"
        .' AND database = (SELECT oid FROM pg_database WHERE datname = current_database())',
        [IngestLock::KEY],
    ));

    return $holders === [$own] && DB::selectOne('SELECT pg_advisory_unlock(?) AS released', [IngestLock::KEY])->released === false;
}

/** Holds the pricing:ingest lock on a second connection while $during runs. */
function t09WithLockHeld(callable $during): mixed
{
    config(['database.connections.t09_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t09_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        return $during();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('t09_holder');
    }
}

/** Runs the database worker at $at, then again at $at plus each step (seconds), past the jobs' own backoff. */
function t09Work(CarbonImmutable $at, array $steps = []): void
{
    CarbonImmutable::setTestNow($at);
    Artisan::call('queue:work', T09_WORK);
    foreach ($steps as $seconds) {
        CarbonImmutable::setTestNow($at->addSeconds($seconds));
        Artisan::call('queue:work', T09_WORK);
    }
}

/** Calls $hook with the SQL of every query on the default connection; the hook is not re-entered by its own queries. */
function t09OnQuery(Closure $hook): void
{
    $busy = false;
    DB::listen(function (QueryExecuted $query) use ($hook, &$busy): void {
        if ($busy || $query->connectionName !== DB::getDefaultConnection()) {
            return;
        }
        $busy = true;
        try {
            $hook($query->sql);
        } finally {
            $busy = false;
        }
    });
}

/** Records the job classes (and chunk ids) the worker picks up, in order. */
function t09Processed(): ArrayObject
{
    $processed = new ArrayObject;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use ($processed): void {
        $command = unserialize($event->job->payload()['data']['command']);
        $processed[] = $command instanceof IngestChunk ? 'chunk:'.$command->chunkId : class_basename($command);
    });

    return $processed;
}

/**
 * The reference: wipes the price and run tables, ingests $days with --sync in the given order, and returns the full
 * digest and each day's run counts. It wipes, so callers take their own readings first.
 *
 * @return array{digest: array<string, mixed>, counts: array<string, array<string, int>>}
 */
function t09Clean(array $days, string $root): array
{
    t09Wipe();
    $counts = [];
    foreach ($days as $day) {
        [$code, $output, $line] = t09Ingest(['--day' => $day, '--sync' => true], $root);
        if ($code !== 0 || $line === null) {
            throw new RuntimeException("the clean reference ingest of {$day} failed: {$output}");
        }
        $counts[$day] = t09RunCounts((int) $line['run_id']);
    }

    return ['digest' => PriceDigest::compute(DB::connection()), 'counts' => $counts];
}

function t09ReadPrices(string $root, string $day, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/{$day}/prices/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

function t09WritePricesJson(string $root, string $day, int $group, string $json): void
{
    file_put_contents("{$root}/{$day}/prices/{$group}.json.zst", zstd_compress($json));
}

/** Sets $field of product $productId in a scratch prices file to the raw JSON token $token. */
function t09SetRawToken(string $root, string $day, int $group, int $productId, string $field, string $token): void
{
    $document = t09ReadPrices($root, $day, $group);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId) {
            $document['results'][$i][$field] = '__T09_TOKEN__';
        }
    }
    t09WritePricesJson($root, $day, $group, str_replace('"__T09_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR)));
}

/** The variant key the map gives (productId, subTypeName). */
function t09Variant(int $productId, string $subtype): string
{
    return (string) DB::table('source_product_map')->where('source', 'tcgplayer')
        ->where('source_product_id', $productId)->where('source_subtype', $subtype)->value('variant_key');
}

/** The options of one ingest mode: `sync`, or `queued` on the database queue (the worker is run by the test). */
function t09Mode(string $mode): array
{
    return $mode === 'sync' ? ['--sync' => true] : ['--queued' => true, '--queue-connection' => 'database'];
}

beforeEach(function (): void {
    // A map build queues VerifyCatalog (T45); the test queue would run it against the real PokéSearch. The real queue
    // manager is put back afterwards, so the database connection and queue:work below are the real ones.
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
    $this->fixtureTree = t09Tree(t09Fixture());
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    expect(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
    foreach (t09Roots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

// Case 1: a malformed zst. Sync: start validates every file, so the cut file refuses the day (R181(2)). Queued: the
// file breaks after start validated it (a file rewritten mid-run), which gives a real retry-and-fail path.

test('case 1 (sync): a prices file cut mid-frame is refused at start naming the file, nothing changes, and the restored file ingests clean', function (): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    $file = "{$root}/2026-10-11/prices/23821.json.zst";
    $good = (string) file_get_contents($file);
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    file_put_contents($file, substr($good, 0, intdiv(strlen($good), 2)));

    [$code, $output] = t09Ingest(['--day' => '2026-10-11', '--sync' => true], $root);
    $run = t09LastRun();
    $chunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $run->id)->count();
    $after = t09Tables();
    $lockFree = t09LockFree();

    file_put_contents($file, $good);
    [$retryCode, $retryOutput] = t09Ingest(['--day' => '2026-10-11', '--sync' => true], $root);
    $recovered = PriceDigest::compute(DB::connection());
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($code)->toBe(1, $output)
        ->and($output)->toContain('bad_document:prices/23821.json.zst')
        ->and([$run->day, $run->mode, $run->status, $run->error])->toBe(['2026-10-11', 'sync', 'refused', 'bad_document:prices/23821.json.zst'])
        ->and($chunks)->toBe(0)
        ->and($after)->toBe($before)
        ->and($lockFree)->toBeTrue()
        ->and($retryCode)->toBe(0, $retryOutput)
        ->and($recovered)->toBe($clean['digest'])
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
});

test('case 1 (queued): a prices file cut mid-frame after start makes its chunk try 3 times, lands in failed_jobs, fails the run without finalize, and --resume recovers', function (): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    $file = "{$root}/2026-10-11/prices/23821.json.zst";
    $good = (string) file_get_contents($file);
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    $now = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    $start = (new StartDayIngest('2026-10-11', $root, false, 'database'))->begin();
    $broken = (int) DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('group_id', 23821)->value('id');
    // The race: the file is rewritten (cut mid-frame) after start validated the whole day.
    file_put_contents($file, substr($good, 0, intdiv(strlen($good), 2)));
    $processed = t09Processed();
    t09Work($now, [11, 72, 373]);

    $failedJobs = DB::table('failed_jobs')->get();
    $chunk = DB::table('ingest_run_chunks')->where('id', $broken)->first();
    $others = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('id', '<>', $broken)->distinct()->pluck('status')->all();
    $run = DB::table('ingest_runs')->where('id', $start['run_id'])->first();
    $after = t09Tables();
    $pointsOfDay = DB::table('price_points')->where('day', '2026-10-11')->count();
    $lockFree = t09LockFree();
    $attempts = array_count_values($processed->getArrayCopy())['chunk:'.$broken] ?? 0;
    $finalized = in_array('FinalizeDay', $processed->getArrayCopy(), true);

    // Recovery: the file is whole again; --resume re-dispatches only the failed chunk, then finalize runs.
    file_put_contents($file, $good);
    [$resumeCode, $resumeOutput, $resumeLine] = t09Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'database'], $root);
    t09Work($now->addHour());
    $resumedStatus = DB::table('ingest_runs')->where('id', $start['run_id'])->value('status');
    $resumedCounts = t09RunCounts($start['run_id']);
    $resumedDigest = PriceDigest::compute(DB::connection());
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($start['status'])->toBe('running')
        ->and($attempts)->toBe(3)
        ->and($finalized)->toBeFalse()
        ->and($failedJobs)->toHaveCount(1)
        ->and(json_decode((string) $failedJobs[0]->payload, true)['displayName'])->toBe(IngestChunk::class)
        ->and([$chunk->status, $chunk->attempts])->toBe(['failed', 3])
        ->and((string) $chunk->error)->toContain('bad_document:prices/23821.json.zst')
        ->and($others)->toBe(['done'])
        // On a worker the batch's catch callback (MarkRunFailed) records the run first; the chunk's failed() hook then
        // finds the run no longer running. Either way the run is failed and names the file.
        ->and($run->status)->toBe('failed')
        ->and((string) $run->error)->toContain('bad_document:prices/23821.json.zst')
        // Finalize did not run: the other chunks' rows may sit in price_points, but the rollup tables are untouched.
        ->and($pointsOfDay)->toBeGreaterThan(0)
        ->and($after['price_daily'])->toBe($before['price_daily'])
        ->and($after['price_latest'])->toBe($before['price_latest'])
        ->and($lockFree)->toBeTrue()
        ->and($resumeCode)->toBe(0, $resumeOutput)
        ->and($resumeLine)->toBe(['run_id' => $start['run_id'], 'status' => 'running', 'resumed_chunks' => 1])
        ->and($resumedStatus)->toBe('ok')
        ->and($resumedCounts)->toBe($clean['counts']['2026-10-11'])
        ->and($resumedDigest)->toBe($clean['digest'])
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
});

// Cases 2 and 3: a missing group file, a marker on another date and a missing SUCCESS are refused at start.

test('cases 2 and 3: a day refused at start records only a refused run, with no chunk, job or price change, and ingests clean once repaired', function (string $mode, Closure $break, string $error): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    $runsBefore = t09Runs();
    $break("{$root}/2026-10-11");

    [$code, $output] = t09Ingest(['--day' => '2026-10-11'] + t09Mode($mode), $root);
    $run = t09LastRun();
    $chunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $run->id)->count();
    $jobs = DB::table('jobs')->count() + DB::table('job_batches')->count();
    $runsAfter = t09Runs();
    $after = t09Tables();
    $lockFree = t09LockFree();

    t09Restore($root, '2026-10-11');
    [$retryCode, $retryOutput] = t09Ingest(['--day' => '2026-10-11'] + t09Mode($mode), $root);
    if ($mode === 'queued') {
        t09Work(CarbonImmutable::now('UTC'));
    }
    $retried = [t09LastRun()->status, PriceDigest::compute(DB::connection())];
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($code)->toBe(1, $output)
        ->and($output)->toContain($error)
        ->and([$run->day, $run->mode, $run->status, $run->error])->toBe(['2026-10-11', $mode, 'refused', $error])
        ->and(array_diff_key($runsAfter, $runsBefore))->toBe([(int) $run->id => ['2026-10-11', $mode, 'refused']])
        ->and($chunks)->toBe(0)
        ->and($jobs)->toBe(0)
        ->and($after)->toBe($before)
        ->and($lockFree)->toBeTrue()
        ->and($retryCode)->toBe(0, $retryOutput)
        ->and($retried)->toBe(['ok', $clean['digest']])
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
})->with(['sync', 'queued'])->with([
    'a group without its prices file (case 2)' => [function (string $d): void {
        unlink("{$d}/prices/1418.json.zst");
    }, 'missing_file:prices/1418.json.zst'],
    'a marker on another UTC date (case 3)' => [function (string $d): void {
        file_put_contents("{$d}/marker.txt", "2026-10-11T23:30:00-0200\n");
    }, 'marker_day_mismatch:marker.txt'],
    'SUCCESS absent (case 3)' => [function (string $d): void {
        unlink("{$d}/SUCCESS");
    }, 'missing_file:SUCCESS'],
]);

// Case 4: the same day three times.

test('case 4: the same day ingested sync, queued --force and sync --force keeps one row per key, counts every mapped row unchanged on the repeats, and sums each chunk once', function (): void {
    $root = t09Fixture();
    [$c1, $o1, $l1] = t09Ingest(['--day' => '2026-10-11', '--sync' => true], $root);
    $first = PriceDigest::compute(DB::connection());
    [$c2, $o2, $l2] = t09Ingest(['--day' => '2026-10-11', '--queued' => true, '--force' => true, '--queue-connection' => 'database'], $root);
    t09Work(CarbonImmutable::now('UTC'));
    [$c3, $o3, $l3] = t09Ingest(['--day' => '2026-10-11', '--sync' => true, '--force' => true], $root);

    $runIds = [(int) $l1['run_id'], (int) $l2['run_id'], (int) $l3['run_id']];
    $counts = array_map(fn (int $id): array => t09RunCounts($id), $runIds);
    $sums = array_map(fn (int $id): array => t09ChunkSums($id), $runIds);
    $keys = DB::selectOne('SELECT count(*) AS n, count(DISTINCT (variant_key, source, observed_at)) AS k FROM price_points');

    expect([$c1, $c2, $c3])->toBe([0, 0, 0], $o1.$o2.$o3)
        ->and(array_map(fn (int $id): array => array_slice(t09Runs()[$id], 1), $runIds))->toBe([['sync', 'ok'], ['queued', 'ok'], ['sync', 'ok']])
        ->and(array_map(fn (array $c): array => [$c['mapped'], $c['upserted'], $c['unchanged']], $counts))->toBe([[21, 21, 0], [21, 0, 21], [21, 0, 21]])
        ->and(array_map(fn (array $c): array => array_intersect_key($c, $sums[0]), $counts))->toBe($sums)
        ->and([(int) $keys->n, (int) $keys->k])->toBe([21, 21])
        ->and(PriceDigest::compute(DB::connection()))->toBe($first)
        ->and(t09LockFree())->toBeTrue();
});

// Case 5: duplicate rows inside one file, one within a chunk and one whose repeat falls in the raw list's next chunk.

test('case 5: duplicate rows in one file keep the first occurrence, are counted once each, and equal the file without them', function (string $mode): void {
    config(['pricing.chunk_rows' => 4]);
    $root = t09Copy('2026-10-10');
    $document = t09ReadPrices($root, '2026-10-10', 23821);
    $r = $document['results'];
    expect(count($r))->toBe(10);
    // Raw positions with chunks of 4: [r0 r1 d1 r2] [r3 r4 d0 r5] [r6 r7 r8 r9]. d1 repeats r1 inside chunk 1; d0
    // repeats r0 (chunk 1) at a position of raw chunk 2. Both repeats carry another price, so the winner is visible.
    $d1 = ['marketPrice' => 999.99] + $r[1];
    $d0 = ['marketPrice' => 888.88] + $r[0];
    $document['results'] = [$r[0], $r[1], $d1, $r[2], $r[3], $r[4], $d0, ...array_slice($r, 5)];
    t09WritePricesJson($root, '2026-10-10', 23821, json_encode($document, JSON_THROW_ON_ERROR));
    $first = [t09Variant($r[0]['productId'], $r[0]['subTypeName']) => sprintf('%.2f', $r[0]['marketPrice']),
        t09Variant($r[1]['productId'], $r[1]['subTypeName']) => sprintf('%.2f', $r[1]['marketPrice'])];

    $run = function (string $snapshots) use ($mode): array {
        [$code, $output, $line] = t09Ingest(['--day' => '2026-10-10'] + t09Mode($mode), $snapshots);
        if ($mode === 'queued') {
            t09Work(CarbonImmutable::now('UTC'));
        }
        $runId = (int) $line['run_id'];

        return [$code, $output, $runId, DB::table('ingest_runs')->where('id', $runId)->first(), t09RunCounts($runId), PriceDigest::compute(DB::connection())];
    };
    [$code, $output, $runId, $dupRun, $dupCounts, $dupDigest] = $run($root);
    $groupChunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('group_id', 23821)->orderBy('offset')->pluck('rows')->all();
    $markets = [];
    foreach (DB::table('price_points')->whereIn('variant_key', array_keys($first))->orderBy('variant_key')->get(['variant_key', DB::raw('market::text AS market')]) as $point) {
        $markets[$point->variant_key] = $point->market;
    }
    ksort($markets);
    ksort($first);
    t09Wipe();
    [$cleanCode, $cleanOutput, , , $cleanCounts, $cleanDigest] = $run(t09Fixture());

    expect($code)->toBe(0, $output)
        ->and($cleanCode)->toBe(0, $cleanOutput)
        ->and([$dupRun->status, $dupRun->mode, $dupRun->error])->toBe(['ok', $mode, null])
        ->and($groupChunks)->toBe([4, 4, 2])
        ->and($markets)->toBe($first)
        ->and($dupCounts['duplicate_rows'])->toBe($cleanCounts['duplicate_rows'] + 2)
        ->and(array_diff_key($dupCounts, ['duplicate_rows' => 0]))->toBe(array_diff_key($cleanCounts, ['duplicate_rows' => 0]))
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($dupDigest)->toBe($cleanDigest)
        ->and(t09LockFree())->toBeTrue()
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
})->with(['sync', 'queued']);

// Case 6: a crash in the middle of a run, and a stalled run.

test('case 6: a chunk that crashes once on the third chunk, inline and without retry, fails the run, keeps the done chunks done, and --resume completes it clean', function (): void {
    $root = t09Fixture();
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    $seen = 0;
    $fired = false;
    t09OnQuery(function (string $sql) use (&$seen, &$fired): void {
        // The chunk's last statement inside its own transaction, after its upsert: the whole chunk must roll back.
        if (! $fired && str_starts_with($sql, "UPDATE ingest_run_chunks SET status = 'done'") && ++$seen === 3) {
            $fired = true;
            throw new RuntimeException('t09 injected crash in the third chunk');
        }
    });

    [$code, $output, $line] = t09Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync'], $root);
    $runId = (int) $line['run_id'];
    $run = DB::table('ingest_runs')->where('id', $runId)->first();
    $chunks = t09Chunks($runId);
    $doneIds = DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', 'done')->pluck('id')->all();
    $pointsOfDay = DB::table('price_points')->where('day', '2026-10-11')->count();
    $donePoints = array_sum(array_map(fn (int $id): int => json_decode((string) DB::table('ingest_run_chunks')->where('id', $id)->value('counts'), true)['mapped'], $doneIds));
    $after = t09Tables();
    $lockFree = t09LockFree();

    [$resumeCode, $resumeOutput, $resumeLine] = t09Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync', '--wait' => 30], $root);
    $resumedChunks = t09Chunks($runId);
    $resumedCounts = t09RunCounts($runId);
    $resumedDigest = PriceDigest::compute(DB::connection());
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($fired)->toBeTrue()
        ->and($code)->toBe(1, $output)
        ->and([$run->status, $run->mode])->toBe(['failed', 'queued'])
        ->and((string) $run->error)->toContain('t09 injected crash in the third chunk')
        ->and($chunks)->toBe([['done', 1], ['done', 1], ['failed', 1], ...array_fill(0, 6, ['pending', 0])])
        ->and($pointsOfDay)->toBe($donePoints)
        ->and($after['price_daily'])->toBe($before['price_daily'])
        ->and($after['price_latest'])->toBe($before['price_latest'])
        ->and($lockFree)->toBeTrue()
        ->and($resumeCode)->toBe(0, $resumeOutput)
        ->and($resumeLine)->toMatchArray(['run_id' => $runId, 'status' => 'ok'])
        // The done chunks were not re-read; the crashed one ran a second time; the rest once.
        ->and($resumedChunks)->toBe([['done', 1], ['done', 1], ['done', 2], ...array_fill(0, 6, ['done', 1])])
        ->and($resumedCounts)->toBe($clean['counts']['2026-10-11'])
        ->and($resumedDigest)->toBe($clean['digest']);
});

test('case 6: a stalled running run, started 3 h ago with chunks still pending, is resumed by IngestPending and ends clean', function (): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $now = CarbonImmutable::parse('2026-10-15 06:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $start = (new StartDayIngest('2026-10-11', $root, false, 'database'))->begin();
    // The worker died: two chunks got done, the queue lost the rest of the batch.
    DB::table('jobs')->delete();
    $ingest = new DayIngest(DB::connection(), $root);
    $ingest->chunk($start['run_id'], $start['chunks'][0]);
    $ingest->chunk($start['run_id'], $start['chunks'][1]);

    CarbonImmutable::setTestNow($now->addHours(3));
    $summary = (new IngestPending($root, 'database'))->scan();
    t09Work($now->addHours(3));
    $status = DB::table('ingest_runs')->where('id', $start['run_id'])->value('status');
    $chunks = t09Chunks($start['run_id']);
    $counts = t09RunCounts($start['run_id']);
    $digest = PriceDigest::compute(DB::connection());
    $lockFree = t09LockFree();
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($summary)->toBe(['skipped' => false, 'dispatched' => [], 'resumed' => [$start['run_id']]])
        ->and($status)->toBe('ok')
        ->and($chunks)->toBe(array_fill(0, 9, ['done', 1]))
        ->and($counts)->toBe($clean['counts']['2026-10-11'])
        ->and($digest)->toBe($clean['digest'])
        ->and($lockFree)->toBeTrue()
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
});

// Case 7: finalize fails inside its transaction, after the rollup statement.

test('case 7: a finalize that fails after its rollup on every try leaves price_daily and price_latest exactly as before, fails the run, and a retried finalize succeeds', function (): void {
    $root = t09Fixture();
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    $now = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
    $armed = true;
    $rolledUp = 0;
    t09OnQuery(function (string $sql) use (&$armed, &$rolledUp): void {
        if ($armed && str_contains($sql, 'INSERT INTO price_daily')) {
            $rolledUp++;
        }
        if ($armed && str_starts_with($sql, 'DELETE FROM price_latest')) {
            throw new RuntimeException('t09 injected failure after the rollup');
        }
    });
    $processed = t09Processed();

    CarbonImmutable::setTestNow($now);
    [$code, $output, $line] = t09Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'database'], $root);
    $runId = (int) $line['run_id'];
    t09Work($now, [31, 152, 753]);
    $run = DB::table('ingest_runs')->where('id', $runId)->first();
    $chunkStatuses = array_values(array_unique(array_column(t09Chunks($runId), 0)));
    $failedJobs = DB::table('failed_jobs')->get();
    $after = t09Tables();
    $lockFree = t09LockFree();
    $finalizeAttempts = array_count_values($processed->getArrayCopy())['FinalizeDay'] ?? 0;

    // Recovery: the fault is gone; --resume re-opens the failed run, which has no chunk left, so only finalize runs.
    $armed = false;
    [$resumeCode, $resumeOutput, $resumeLine] = t09Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'database'], $root);
    t09Work($now->addHour());
    $resumedStatus = DB::table('ingest_runs')->where('id', $runId)->value('status');
    $resumedCounts = t09RunCounts($runId);
    $resumedDigest = PriceDigest::compute(DB::connection());
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($code)->toBe(0, $output)
        ->and($rolledUp)->toBe(3)
        ->and($finalizeAttempts)->toBe(3)
        ->and($failedJobs)->toHaveCount(1)
        ->and(json_decode((string) $failedJobs[0]->payload, true)['displayName'])->toBe(FinalizeDay::class)
        ->and($run->status)->toBe('failed')
        ->and((string) $run->error)->toBe('finalize failed: t09 injected failure after the rollup')
        ->and($chunkStatuses)->toBe(['done'])
        ->and($after['price_daily'])->toBe($before['price_daily'])
        ->and($after['price_latest'])->toBe($before['price_latest'])
        ->and($lockFree)->toBeTrue()
        ->and($resumeCode)->toBe(0, $resumeOutput)
        ->and($resumeLine)->toBe(['run_id' => $runId, 'status' => 'running', 'resumed_chunks' => 0])
        ->and($resumedStatus)->toBe('ok')
        ->and($resumedCounts)->toBe($clean['counts']['2026-10-11'])
        ->and($resumedDigest)->toBe($clean['digest']);
});

// Case 8: every row of a day unmapped (a map regression must not blank prices).

test('case 8: a day whose every row is unmapped completes ok with mapped 0, writes no price row and leaves price_latest unchanged', function (string $mode): void {
    $root = t09Copy('2026-10-10', '2026-10-11', '2026-10-12');
    foreach (glob("{$root}/2026-10-12/prices/*.json.zst") ?: [] as $file) {
        $document = json_decode((string) zstd_uncompress((string) file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
        foreach ($document['results'] as $i => $row) {
            $document['results'][$i]['productId'] = $row['productId'] + 90000000;
        }
        file_put_contents($file, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
    }
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    t09Ingest(['--day' => '2026-10-11', '--sync' => true], $root);
    $before = t09Tables();

    [$code, $output, $line] = t09Ingest(['--day' => '2026-10-12'] + t09Mode($mode), $root);
    if ($mode === 'queued') {
        t09Work(CarbonImmutable::now('UTC'));
    }
    $run = t09LastRun();
    $counts = t09RunCounts((int) $run->id);
    $after = t09Tables();
    $daily12 = DB::select("SELECT count(*) AS n, count(*) FILTER (WHERE is_filled AND n_obs = 0 AND filled_from = '2026-10-11') AS filled FROM price_daily WHERE day = '2026-10-12'")[0];

    expect($code)->toBe(0, $output)
        ->and($line['run_id'] ?? null)->toBe((int) $run->id)
        ->and([$run->mode, $run->status, $run->error])->toBe([$mode, 'ok', null])
        ->and([$counts['mapped'], $counts['upserted'], $counts['unchanged']])->toBe([0, 0, 0])
        ->and($counts['unmapped'])->toBe($counts['rows'])
        ->and($counts['rows'])->toBeGreaterThan(0)
        ->and(DB::table('price_points')->where('day', '2026-10-12')->count())->toBe(0)
        ->and($after['price_points'])->toBe($before['price_points'])
        ->and($after['price_latest'])->toBe($before['price_latest'])
        // D3: the day is the newest ingested, so every variant is carried forward onto it; none is a real close.
        ->and((int) $daily12->n)->toBeGreaterThan(0)
        ->and((int) $daily12->filled)->toBe((int) $daily12->n)
        ->and(t09LockFree())->toBeTrue()
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
})->with(['sync', 'queued']);

// Case 9: a late day.

test('case 9: 10-11 ingested queued after 10-14 gives the in-order digests of price_points, price_daily and price_latest (price_events never compared)', function (): void {
    $root = t09Fixture();
    $inOrder = t09Clean(T09_DAYS, $root)['digest'];

    t09Wipe();
    foreach (['2026-10-09', '2026-10-10', '2026-10-12', '2026-10-14'] as $day) {
        t09Ingest(['--day' => $day, '--sync' => true], $root);
    }
    [$code, $output] = t09Ingest(['--day' => '2026-10-11'] + t09Mode('queued'), $root);
    t09Work(CarbonImmutable::now('UTC'));
    $late = PriceDigest::compute(DB::connection());

    expect($code)->toBe(0, $output)
        ->and(array_column(t09Runs(), 2))->toBe(['ok', 'ok', 'ok', 'ok', 'ok'])
        ->and(array_keys($late['tables']))->toBe(T09_TABLES)
        ->and($late)->toBe($inOrder)
        ->and($late['tables']['price_daily']['rows'])->toBeGreaterThan(0)
        ->and(t09LockFree())->toBeTrue();
});

// Case 10: a replay that crashes after its wipe (review F6; the T07 carry-forward).

test('case 10: a replay crashing in pass 1 after the wipe leaves the former runs superseded, and the next pending scan re-ingests the days to the clean digest', function (): void {
    $root = t09Fixture();
    $clean = t09Clean(T09_DAYS, $root);
    $formerRuns = array_keys(t09Runs());
    $truncated = false;
    $starts = 0;
    $fired = false;
    t09OnQuery(function (string $sql) use (&$truncated, &$starts, &$fired): void {
        if (str_starts_with($sql, 'TRUNCATE ')) {
            $truncated = true;
        }
        if ($truncated && str_starts_with($sql, 'insert into "ingest_runs"')) {
            $starts++;
        }
        // The crash: the third day of pass 1, at its first price_points write, after two days were re-ingested.
        if (! $fired && $starts === 3 && str_starts_with($sql, 'INSERT INTO price_points')) {
            $fired = true;
            throw new RuntimeException('t09 injected replay crash');
        }
    });

    $code = Artisan::call('pokeprices:prices:replay', ['--snapshots' => $root, '--fresh' => true]);
    $output = Artisan::output();
    $former = array_map(fn (int $id): string => t09Runs()[$id][2], $formerRuns);
    $replayRuns = array_values(array_diff_key(t09Runs(), array_flip($formerRuns)));
    $lockFree = t09LockFree();
    $afterCrash = t09Tables();

    $summary = (new IngestPending($root, 'database'))->scan();
    t09Work(CarbonImmutable::now('UTC'));
    $repaired = PriceDigest::compute(DB::connection());

    expect($fired)->toBeTrue()
        ->and($code)->toBe(1, $output)
        ->and($output)->toContain('t09 injected replay crash')
        ->and($former)->toBe(array_fill(0, 5, 'superseded'))
        ->and($replayRuns)->toBe([['2026-10-09', 'replay', 'ok'], ['2026-10-10', 'replay', 'ok'], ['2026-10-11', 'replay', 'failed']])
        ->and($afterCrash['price_points']['rows'])->toBeLessThan($clean['digest']['tables']['price_points']['rows'])
        ->and($lockFree)->toBeTrue()
        ->and($summary)->toBe(['skipped' => false, 'dispatched' => ['2026-10-11', '2026-10-12', '2026-10-14'], 'resumed' => []])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($repaired)->toBe($clean['digest']);
});

// Case 11: concurrent writers (D12).

test('case 11: with the lock held by a second session, sync exits 1, StartDayIngest releases itself, the scan skips and replay exits 1, all untouched; the released start then ingests clean', function (): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    $runsBefore = t09Runs();
    $now = CarbonImmutable::parse('2026-10-15 09:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    Queue::connection('database')->push(new StartDayIngest('2026-10-11', $root, false, 'database'));

    $held = t09WithLockHeld(function () use ($root, $now): array {
        [$syncCode, $syncOutput] = t09Ingest(['--day' => '2026-10-11', '--sync' => true], $root);
        t09Work($now);
        $job = DB::table('jobs')->first();
        $scan = (new IngestPending($root, 'database'))->scan();
        $replayCode = Artisan::call('pokeprices:prices:replay', ['--snapshots' => $root, '--fresh' => true]);
        $replayOutput = Artisan::output();

        return [$syncCode, $syncOutput, $job, $scan, $replayCode, $replayOutput, t09Tables(), t09Runs(), DB::table('jobs')->count(), t09LockFree()];
    });
    [$syncCode, $syncOutput, $job, $scan, $replayCode, $replayOutput, $during, $runsDuring, $jobsDuring, $freeDuring] = $held;
    $freeAfter = t09LockFree();

    t09Work($now->addSeconds(61));
    $recovered = [t09LastRun()->day, t09LastRun()->status, PriceDigest::compute(DB::connection())];
    $clean = t09Clean(['2026-10-10', '2026-10-11'], $root);

    expect($syncCode)->toBe(1, $syncOutput)
        ->and($syncOutput)->toContain('pricing ingest lock held')
        ->and([$job->attempts, $job->available_at])->toBe([1, $now->addSeconds(60)->getTimestamp()])
        ->and($jobsDuring)->toBe(1)
        ->and($scan)->toBe(['skipped' => true, 'dispatched' => [], 'resumed' => []])
        ->and($replayCode)->toBe(1, $replayOutput)
        ->and($replayOutput)->toContain('pricing ingest lock held')
        ->and($during)->toBe($before)
        ->and($runsDuring)->toBe($runsBefore)
        ->and($freeDuring)->toBeFalse()
        ->and($freeAfter)->toBeTrue()
        ->and($recovered)->toBe(['2026-10-11', 'ok', $clean['digest']])
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
});

// Case 12: decimal boundaries (D5, review F8), in sync and queued mode.

test('case 12: a bad value refuses the day at start and writes nothing, in sync and queued mode', function (string $mode, string $token): void {
    $root = t09Copy('2026-10-10', '2026-10-11');
    t09Ingest(['--day' => '2026-10-10', '--sync' => true], $root);
    $before = t09Tables();
    t09SetRawToken($root, '2026-10-11', 1543, 83694, 'marketPrice', $token);

    [$code, $output] = t09Ingest(['--day' => '2026-10-11'] + t09Mode($mode), $root);
    if ($mode === 'queued') {
        t09Work(CarbonImmutable::now('UTC'));
    }
    $run = t09LastRun();

    expect($code)->toBe(1, $output)
        ->and([$run->day, $run->mode, $run->status, $run->error])->toBe(['2026-10-11', $mode, 'refused', 'bad_value:prices/1543.json.zst:83694:marketPrice'])
        ->and(DB::table('ingest_run_chunks')->where('ingest_run_id', $run->id)->count())->toBe(0)
        ->and(DB::table('jobs')->count() + DB::table('job_batches')->count())->toBe(0)
        ->and(t09Tables())->toBe($before)
        ->and(t09LockFree())->toBeTrue()
        ->and(t09Tree(t09Fixture()))->toBe($this->fixtureTree);
})->with(['sync', 'queued'])->with([
    'minus one cent' => ['-0.01'],
    'negative zero' => ['-0.0'],
    'three decimals' => ['1.005'],
    'ten billion' => ['10000000000.00'],
    'an exponent' => ['1e2'],
    'a JSON string' => ['"1.00"'],
]);

test('case 12: the accepted boundaries are stored as exact two-decimal text by the queued path too', function (string $token, string $stored): void {
    $root = t09Copy('2026-10-11');
    t09SetRawToken($root, '2026-10-11', 1543, 83694, 'marketPrice', $token);

    [$code, $output] = t09Ingest(['--day' => '2026-10-11'] + t09Mode('queued'), $root);
    t09Work(CarbonImmutable::now('UTC'));
    $variant = t09Variant(83694, 'Normal');

    expect($code)->toBe(0, $output)
        ->and(t09LastRun()->status)->toBe('ok')
        ->and(DB::table('price_points')->where('variant_key', $variant)->value(DB::raw('market::text')))->toBe($stored)
        ->and(DB::table('price_daily')->where('variant_key', $variant)->value(DB::raw('close::text')))->toBe($stored)
        ->and(DB::table('price_latest')->where('variant_key', $variant)->value(DB::raw('close::text')))->toBe($stored);
})->with([
    'the largest numeric(12,2)' => ['9999999999.99', '9999999999.99'],
    'zero' => ['0', '0.00'],
    'one cent' => ['0.01', '0.01'],
]);
