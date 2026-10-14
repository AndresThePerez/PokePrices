<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\FinalizeDayCallback;
use App\Domain\Pricing\Jobs\IngestChunk;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\MarkRunFailed;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\PriceDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// The queued ingest path and the hourly pending scan (plan P2-i1 T08). The same jobs run on three queue connections:
// the forced `sync` connection (batches run inline), the `database` connection driven by `queue:work` for the real
// retries and failed_jobs (review F4; SyncQueue never retries), and `redis` in production (the default of every job
// and of the command). The fixture tree is only ever read: tests that change a day work on a scratch copy.

const T08_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

// The plan's worker call (review F4). --memory lifts the worker's 128 MB default, which a long-lived parallel Pest
// process passes, so the worker would stop after every job; --sleep 0 skips the idle second before it stops when empty.
const T08_WORK = ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 3, '--backoff' => 0, '--memory' => 4096, '--sleep' => 0];

function t08Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/**
 * Runs pokeprices:prices:ingest and returns [exit code, output, the last JSON line or null].
 *
 * @return array{0: int, 1: string, 2: array<string, mixed>|null}
 */
function t08Ingest(array $options): array
{
    $code = Artisan::call('pokeprices:prices:ingest', $options + ['--snapshots' => t08Fixture('snapshots')]);
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

/** Empties the price and run tables, so a second ingest of the same days starts from the same state. */
function t08Wipe(): void
{
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    // A fresh start empties the price_events outbox too (T13): only a test ever deletes events, never ingestion.
    DB::table('price_events')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

/** The run's counts that both modes write, without the map build id. */
function t08RunCounts(int $runId): array
{
    $counts = json_decode((string) DB::table('ingest_runs')->where('id', $runId)->value('counts'), true, 512, JSON_THROW_ON_ERROR);
    unset($counts['map_build_id']);
    ksort($counts);

    return $counts;
}

/** The ingest_runs rows as [day, mode, status], by id. */
function t08Runs(): array
{
    return array_map(fn (object $r): array => [$r->day, $r->mode, $r->status],
        DB::select('SELECT day::text AS day, mode, status FROM ingest_runs ORDER BY id'));
}

/** The chunk keys of the queued IngestChunk jobs waiting in the database queue, sorted. */
function t08QueuedChunkKeys(): array
{
    $ids = [];
    foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
        $command = unserialize(json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
        if ($command instanceof IngestChunk) {
            $ids[] = $command->chunkId;
        }
    }
    $keys = DB::table('ingest_run_chunks')->whereIn('id', $ids)->pluck('chunk_key')->all();
    sort($keys);

    return $keys;
}

/** A writable copy of the named fixture days under a scratch root that afterEach removes. */
function t08CopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t08-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t08Roots($root);
    foreach ($days as $day) {
        $from = t08Fixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
            $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
        }
    }

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t08Roots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Every file under $dir as relative path => sha1. */
function t08Tree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** Decodes one prices file of a scratch day. */
function t08ReadPrices(string $root, string $day, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/{$day}/prices/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

function t08WritePrices(string $root, string $day, int $group, array $document): void
{
    file_put_contents("{$root}/{$day}/prices/{$group}.json.zst", zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Drops the last row of a scratch group's prices file, so a chunk planned before finds the file changed. */
function t08ShrinkGroup(string $root, string $day, int $group): void
{
    $document = t08ReadPrices($root, $day, $group);
    array_pop($document['results']);
    t08WritePrices($root, $day, $group, $document);
}

/** Holds the pricing:ingest lock on a second connection while $during runs. */
function t08WithLockHeld(callable $during): mixed
{
    config(['database.connections.t08_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t08_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        return $during();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('t08_holder');
    }
}

/** Opens a queued run whose chunk jobs wait in the database queue, without running any of them. */
function t08StartOnDatabase(string $day, string $root): array
{
    $start = (new StartDayIngest($day, $root, false, 'database'))->begin();
    if ($start['status'] !== 'running') {
        throw new RuntimeException('the queued start did not open a run: '.json_encode($start));
    }

    return $start;
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
    $this->fixtureTree = t08Tree(t08Fixture('snapshots'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    expect(t08Tree(t08Fixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t08Roots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

// Item 1 and 6: one path for both modes.

test('a queued ingest of the five synthetic days equals the sync digest, day by day counts included', function (): void {
    $queued = [];
    foreach (T08_DAYS as $day) {
        [$code, $output, $line] = t08Ingest(['--day' => $day, '--queued' => true, '--queue-connection' => 'sync', '--wait' => 30]);
        expect($code)->toBe(0, $output)
            ->and($line)->toMatchArray(['day' => $day, 'status' => 'ok'])
            ->and($line)->toHaveKeys(['run_id', 'marker', 'rows', 'mapped', 'unmapped', 'null_market', 'upserted', 'unchanged', 'daily_rows', 'digests']);
        $queued[$day] = t08RunCounts($line['run_id']);
        expect(DB::table('job_batches')->where('name', 'ingest:tcgplayer:'.$line['run_id'])->value('total_jobs'))
            ->toBe(DB::table('ingest_run_chunks')->where('ingest_run_id', $line['run_id'])->count());
    }
    $queuedDigest = PriceDigest::compute(DB::connection());
    expect(array_unique(array_column(t08Runs(), 1)))->toBe(['queued'])
        ->and(array_unique(array_column(t08Runs(), 2)))->toBe(['ok'])
        ->and($line['digests'])->toBe($queuedDigest);

    t08Wipe();
    $sync = [];
    foreach (T08_DAYS as $day) {
        [$code, $output, $line] = t08Ingest(['--day' => $day, '--sync' => true]);
        expect($code)->toBe(0, $output);
        $sync[$day] = t08RunCounts($line['run_id']);
    }

    expect(PriceDigest::compute(DB::connection()))->toBe($queuedDigest)
        ->and($queuedDigest['tables']['price_points']['rows'])->toBeGreaterThan(0)
        ->and($queuedDigest['tables']['price_daily']['rows'])->toBeGreaterThan(0)
        ->and($queuedDigest['tables']['price_latest']['rows'])->toBeGreaterThan(0)
        ->and($queued)->toBe($sync);
});

test('a 450-row de-duplicated group splits into chunks of 200, 200 and 50, and the summed counts equal the sync run', function (): void {
    $root = t08CopyDays('2026-10-11');
    $document = t08ReadPrices($root, '2026-10-11', 23821);
    $raw = count($document['results']);
    for ($i = 0; count($document['results']) < 450; $i++) {
        $document['results'][] = ['productId' => 7000000 + $i, 'lowPrice' => 1.5, 'midPrice' => 2, 'highPrice' => 3.25,
            'marketPrice' => $i % 7 === 0 ? null : 2.1, 'directLowPrice' => null, 'subTypeName' => 'Normal'];
    }
    $document['results'][] = $document['results'][400];
    t08WritePrices($root, '2026-10-11', 23821, $document);

    [$code, $output, $line] = t08Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync', '--wait' => 30, '--snapshots' => $root]);
    $queuedRun = $line['run_id'] ?? null;
    $chunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $queuedRun)->where('group_id', 23821)->orderBy('offset')
        ->get(['chunk_key', 'rows', 'status'])->map(fn (object $c): array => (array) $c)->all();
    $queuedCounts = t08RunCounts((int) $queuedRun);
    $queuedDigest = PriceDigest::compute(DB::connection());

    t08Wipe();
    [$syncCode, $syncOutput, $syncLine] = t08Ingest(['--day' => '2026-10-11', '--sync' => true, '--snapshots' => $root]);

    expect($raw)->toBe(10)
        ->and($code)->toBe(0, $output)
        ->and($chunks)->toBe([
            ['chunk_key' => '23821:0', 'rows' => 200, 'status' => 'done'],
            ['chunk_key' => '23821:200', 'rows' => 200, 'status' => 'done'],
            ['chunk_key' => '23821:400', 'rows' => 50, 'status' => 'done'],
        ])
        ->and($syncCode)->toBe(0, $syncOutput)
        ->and($queuedCounts)->toBe(t08RunCounts($syncLine['run_id']))
        ->and($queuedCounts['rows'])->toBe(29 - 10 + 450)
        ->and($queuedCounts['duplicate_rows'])->toBe(2)
        ->and(PriceDigest::compute(DB::connection()))->toBe($queuedDigest);
});

// Item 2: the jobs.

test('the chunk and finalize jobs carry #[Tries] and #[Backoff], and the batch callbacks are invokable serializable classes', function (): void {
    $attribute = fn (string $class, string $name): array => array_map(fn (ReflectionAttribute $a): array => $a->getArguments(),
        (new ReflectionClass($class))->getAttributes($name));

    expect($attribute(IngestChunk::class, Tries::class))->toBe([[3]])
        ->and($attribute(IngestChunk::class, Backoff::class))->toBe([[[10, 60, 300]]])
        ->and($attribute(FinalizeDay::class, Tries::class))->toBe([[3]])
        ->and($attribute(FinalizeDay::class, Backoff::class))->toBe([[[30, 120, 600]]]);

    Bus::fake();
    $start = (new StartDayIngest('2026-10-11', t08Fixture('snapshots'), false, 'redis'))->begin();
    $chunkIds = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->orderBy('id')->pluck('id')->all();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function (PendingBatch $batch) use ($start, $chunkIds): bool {
        [$then] = $batch->thenCallbacks();
        [$catch] = $batch->catchCallbacks();

        expect($batch->name)->toBe('ingest:tcgplayer:'.$start['run_id'])
            ->and($batch->connection())->toBe('redis')
            ->and($batch->queue())->toBe('default')
            ->and($batch->allowsFailures())->toBeFalse()
            ->and($batch->jobs->map(fn (IngestChunk $job): int => $job->chunkId)->all())->toBe($chunkIds)
            ->and($batch->jobs->every(fn (IngestChunk $job): bool => $job->runId === $start['run_id']))->toBeTrue()
            ->and($then)->toBeInstanceOf(FinalizeDayCallback::class)
            ->and($catch)->toBeInstanceOf(MarkRunFailed::class)
            ->and(is_callable($then) && is_callable($catch))->toBeTrue()
            ->and(unserialize(serialize($then)))->toEqual($then)
            ->and(unserialize(serialize($catch)))->toEqual($catch);

        return true;
    });
    expect(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('running')
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('mode'))->toBe('queued');
});

test('a chunk made to throw every time makes exactly 3 attempts on a real worker, lands in failed_jobs and fails its run without finalize', function (): void {
    $root = t08CopyDays('2026-10-10', '2026-10-11');
    t08Ingest(['--day' => '2026-10-10', '--sync' => true, '--snapshots' => $root]);
    $before = PriceDigest::compute(DB::connection())['tables'];

    $start = t08StartOnDatabase('2026-10-11', $root);
    $broken = (int) DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('group_id', 23821)->value('id');
    t08ShrinkGroup($root, '2026-10-11', 23821);

    $processed = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$processed): void {
        $command = unserialize($event->job->payload()['data']['command']);
        $processed[] = $command instanceof IngestChunk ? 'chunk:'.$command->chunkId : class_basename($command);
    });

    $now = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    Artisan::call('queue:work', T08_WORK);
    // The job's own #[Backoff] (10 s) holds the retry back: the worker found the queue empty and stopped.
    $afterFirst = [DB::table('jobs')->count(), (int) DB::table('jobs')->value('attempts')];
    foreach ([11, 72, 373] as $seconds) {
        CarbonImmutable::setTestNow($now->addSeconds($seconds));
        Artisan::call('queue:work', T08_WORK);
    }

    $failed = DB::table('failed_jobs')->get();
    $chunk = DB::table('ingest_run_chunks')->where('id', $broken)->first();
    $tables = PriceDigest::compute(DB::connection())['tables'];

    expect($afterFirst)->toBe([1, 1])
        ->and(array_count_values($processed)['chunk:'.$broken])->toBe(3)
        ->and($processed)->not->toContain('FinalizeDay')
        ->and($failed)->toHaveCount(1)
        ->and(json_decode((string) $failed[0]->payload, true)['displayName'])->toBe(IngestChunk::class)
        ->and($chunk->status)->toBe('failed')
        ->and($chunk->attempts)->toBe(3)
        ->and((string) $chunk->error)->toContain('prices/23821.json.zst changed since ingest run')
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('failed')
        ->and((string) DB::table('ingest_runs')->where('id', $start['run_id'])->value('error'))->toContain('23821')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($tables['price_daily'])->toBe($before['price_daily'])
        ->and($tables['price_latest'])->toBe($before['price_latest']);
});

test('a chunk of a cancelled batch returns without writing', function (): void {
    $start = t08StartOnDatabase('2026-10-11', t08Fixture('snapshots'));
    $batchId = (string) DB::table('job_batches')->where('name', 'ingest:tcgplayer:'.$start['run_id'])->value('id');
    Bus::findBatch($batchId)?->cancel();

    Artisan::call('queue:work', T08_WORK);

    expect(DB::table('price_points')->count())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->distinct()->pluck('status')->all())->toBe(['pending'])
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('running')
        ->and(DB::table('price_daily')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('a chunk of a superseded run returns without writing, and finalize never runs (D12)', function (): void {
    $start = t08StartOnDatabase('2026-10-11', t08Fixture('snapshots'));
    DB::table('ingest_runs')->where('id', $start['run_id'])->update(['status' => 'superseded']);

    Artisan::call('queue:work', T08_WORK);

    expect(DB::table('price_points')->count())->toBe(0)
        ->and(DB::table('price_daily')->count())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->distinct()->pluck('status')->all())->toBe(['pending'])
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('superseded')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('on the sync queue a failing chunk fails once, and its failed() hook marks the chunk and the run failed', function (): void {
    $root = t08CopyDays('2026-10-11');
    $start = t08StartOnDatabase('2026-10-11', $root);
    DB::table('jobs')->delete();
    t08ShrinkGroup($root, '2026-10-11', 23821);

    [$code, $output] = t08Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync', '--snapshots' => $root, '--wait' => 5]);
    $chunk = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('group_id', 23821)->first();

    expect($code)->toBe(1, $output)
        ->and($chunk->status)->toBe('failed')
        ->and($chunk->attempts)->toBe(1)
        ->and((string) $chunk->error)->toContain('changed since ingest run')
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('failed')
        ->and(DB::table('price_daily')->count())->toBe(0);
});

// Item 3: the command's --queued, --wait and --resume.

test('--queued without --wait prints the run id and running, and --wait returns 1 when its bound is reached first', function (): void {
    [$code, $output, $line] = t08Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'database']);
    $runId = $line['run_id'] ?? null;

    expect($code)->toBe(0, $output)
        ->and($line)->toBe(['run_id' => $runId, 'status' => 'running'])
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('status'))->toBe('running');

    [$code, $output, $line] = t08Ingest(['--day' => '2026-10-12', '--queued' => true, '--queue-connection' => 'database', '--wait' => 1]);

    expect($code)->toBe(1, $output)
        ->and($line)->toMatchArray(['day' => '2026-10-12', 'status' => 'running'])
        ->and($output)->toContain('--wait');
});

test('--queued refuses like --sync: the lock held exits 1 with no run, and a refused day exits 1 with a refused queued run', function (): void {
    [$code, $output] = t08WithLockHeld(fn (): array => t08Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync']));

    expect($code)->toBe(1)
        ->and($output)->toContain('pricing ingest lock held')
        ->and(DB::table('ingest_runs')->count())->toBe(0);

    $root = t08CopyDays('2026-10-11');
    unlink("{$root}/2026-10-11/prices/604.json.zst");
    [$code, $output] = t08Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync', '--snapshots' => $root]);

    expect($code)->toBe(1)
        ->and($output)->toContain('missing_file:prices/604.json.zst')
        ->and(t08Runs())->toBe([['2026-10-11', 'queued', 'refused']])
        ->and(DB::table('price_points')->count())->toBe(0);
});

test('resume after two chunks are done re-dispatches only the rest, and the run counts are not double counted', function (): void {
    $start = t08StartOnDatabase('2026-10-11', t08Fixture('snapshots'));
    DB::table('jobs')->delete();
    $chunks = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->orderBy('id')->get(['id', 'chunk_key']);
    $ingest = new DayIngest(DB::connection(), t08Fixture('snapshots'));
    $ingest->chunk($start['run_id'], (int) $chunks[0]->id);
    $ingest->chunk($start['run_id'], (int) $chunks[1]->id);

    [$code, $output, $line] = t08Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'database']);
    $expectedKeys = $chunks->slice(2)->pluck('chunk_key')->all();
    sort($expectedKeys);
    $queuedKeys = t08QueuedChunkKeys();
    Artisan::call('queue:work', T08_WORK);
    $resumedCounts = t08RunCounts($start['run_id']);
    $attempts = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->orderBy('id')->pluck('attempts')->all();

    t08Wipe();
    [, , $syncLine] = t08Ingest(['--day' => '2026-10-11', '--sync' => true]);

    expect($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['run_id' => $start['run_id'], 'status' => 'running', 'resumed_chunks' => 7])
        ->and($queuedKeys)->toBe($expectedKeys)
        ->and($attempts)->toBe(array_fill(0, 9, 1))
        ->and($resumedCounts)->toBe(t08RunCounts($syncLine['run_id']));
});

test('--resume exits 1 when nothing is resumable', function (): void {
    [$none] = t08Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync']);
    t08Ingest(['--day' => '2026-10-11', '--sync' => true]);
    [$code, $output] = t08Ingest(['--day' => '2026-10-11', '--resume' => true, '--queue-connection' => 'sync']);

    expect($none)->toBe(1)
        ->and($code)->toBe(1)
        ->and($output)->toContain('nothing to resume');
});

// Item 4: the pending scan.

test('the pending scan dispatches exactly the not-yet-ingested days, oldest first, and a second scan dispatches nothing', function (): void {
    t08Ingest(['--day' => '2026-10-10', '--sync' => true]);
    t08Ingest(['--day' => '2026-10-12', '--sync' => true]);

    Bus::fake();
    (new IngestPending(t08Fixture('snapshots'), 'sync'))->handle();
    $dispatched = Bus::dispatched(StartDayIngest::class);

    expect($dispatched->map(fn (StartDayIngest $job): string => $job->day)->all())->toBe(['2026-10-09', '2026-10-11', '2026-10-14'])
        ->and($dispatched->every(fn (StartDayIngest $job): bool => $job->connection === 'sync' && $job->queue === 'default'))->toBeTrue();
});

test('a real scan ingests every new day queued, and the scan right after it dispatches nothing', function (): void {
    $summary = (new IngestPending(t08Fixture('snapshots'), 'sync'))->scan();
    $digest = PriceDigest::compute(DB::connection());

    Bus::fake();
    $again = (new IngestPending(t08Fixture('snapshots'), 'sync'))->scan();

    expect($summary['dispatched'])->toBe(T08_DAYS)
        ->and(t08Runs())->toBe(array_map(fn (string $d): array => [$d, 'queued', 'ok'], T08_DAYS))
        ->and($again)->toMatchArray(['skipped' => false, 'dispatched' => [], 'resumed' => []])
        ->and(PriceDigest::compute(DB::connection()))->toBe($digest);
    Bus::assertNothingDispatched();
    Bus::assertBatchCount(0);
});

test('a superseded marker is re-dispatched', function (): void {
    $root = t08CopyDays('2026-10-10', '2026-10-11');
    t08Ingest(['--day' => '2026-10-10', '--sync' => true, '--snapshots' => $root]);
    [, , $line] = t08Ingest(['--day' => '2026-10-11', '--sync' => true, '--snapshots' => $root]);
    DB::table('ingest_runs')->where('id', $line['run_id'])->update(['status' => 'superseded']);

    Bus::fake();
    $summary = (new IngestPending($root, 'sync'))->scan();

    expect($summary['dispatched'])->toBe(['2026-10-11'])
        ->and(Bus::dispatched(StartDayIngest::class)->map(fn (StartDayIngest $job): string => $job->day)->all())->toBe(['2026-10-11']);
});

test('a refused day with the same error is not re-dispatched within 24 h, and is after 24 h', function (): void {
    $root = t08CopyDays('2026-10-11', '2026-10-12');
    unlink("{$root}/2026-10-12/prices/604.json.zst");
    $now = CarbonImmutable::parse('2026-10-15 09:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    (new IngestPending($root, 'sync'))->scan();

    Bus::fake();
    CarbonImmutable::setTestNow($now->addHour());
    $nextHour = (new IngestPending($root, 'sync'))->scan();
    CarbonImmutable::setTestNow($now->addHours(23)->addMinutes(59));
    $almostDay = (new IngestPending($root, 'sync'))->scan();
    CarbonImmutable::setTestNow($now->addHours(24)->addMinute());
    $afterDay = (new IngestPending($root, 'sync'))->scan();

    expect(t08Runs())->toBe([['2026-10-11', 'queued', 'ok'], ['2026-10-12', 'queued', 'refused']])
        ->and(DB::table('ingest_runs')->where('status', 'refused')->value('error'))->toBe('missing_file:prices/604.json.zst')
        ->and($nextHour['dispatched'])->toBe([])
        ->and($almostDay['dispatched'])->toBe([])
        ->and($afterDay['dispatched'])->toBe(['2026-10-12']);
});

test('a refused day whose folder changed is retried at the next pass', function (): void {
    $root = t08CopyDays('2026-10-12');
    $file = "{$root}/2026-10-12/prices/604.json.zst";
    $bytes = (string) file_get_contents($file);
    unlink($file);
    (new IngestPending($root, 'sync'))->scan();

    Bus::fake();
    file_put_contents($file, $bytes);
    $summary = (new IngestPending($root, 'sync'))->scan();

    expect(t08Runs())->toBe([['2026-10-12', 'queued', 'refused']])
        ->and($summary['dispatched'])->toBe(['2026-10-12']);
});

test('the scan does nothing while the pricing:ingest lock is held', function (): void {
    $summary = t08WithLockHeld(fn (): array => (new IngestPending(t08Fixture('snapshots'), 'sync'))->scan());

    expect($summary)->toBe(['skipped' => true, 'dispatched' => [], 'resumed' => []])
        ->and(DB::table('ingest_runs')->count())->toBe(0)
        ->and(DB::table('price_points')->count())->toBe(0);
});

test('a running run younger than 2 h is skipped, and one older than 2 h is resumed', function (): void {
    $root = t08CopyDays('2026-10-11');
    $now = CarbonImmutable::parse('2026-10-15 09:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $start = t08StartOnDatabase('2026-10-11', $root);
    DB::table('jobs')->delete();

    Bus::fake();
    CarbonImmutable::setTestNow($now->addMinutes(119));
    $young = (new IngestPending($root, 'sync'))->scan();
    Bus::assertBatchCount(0);
    CarbonImmutable::setTestNow($now->addMinutes(121));
    $stale = (new IngestPending($root, 'sync'))->scan();

    expect($young)->toBe(['skipped' => false, 'dispatched' => [], 'resumed' => []])
        ->and($stale)->toBe(['skipped' => false, 'dispatched' => [], 'resumed' => [$start['run_id']]]);
    Bus::assertNotDispatched(StartDayIngest::class);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === 'ingest:tcgplayer:'.$start['run_id'] && $batch->jobs->count() === 9);
});

test('StartDayIngest on a worker releases itself while the lock is held', function (): void {
    Queue::connection('database')->push(new StartDayIngest('2026-10-11', t08Fixture('snapshots'), false, 'database'));
    $now = CarbonImmutable::parse('2026-10-15 09:40:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    t08WithLockHeld(fn (): int => Artisan::call('queue:work', T08_WORK));
    $job = DB::table('jobs')->first();

    expect(DB::table('ingest_runs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($job->attempts)->toBe(1)
        ->and($job->available_at)->toBe($now->addSeconds(60)->getTimestamp());
});

// Carry-forwards: a half-replayed state repaired by the scan, and markers with fractional seconds.

test('the scan repairs a replay that crashed after its wipe: the superseded days are re-ingested to a clean digest', function (): void {
    foreach (T08_DAYS as $day) {
        t08Ingest(['--day' => $day, '--sync' => true]);
    }
    $clean = PriceDigest::compute(DB::connection());

    // The replay's wipe (D12): every ok run superseded and the derived tables truncated, then two days re-ingested
    // before the crash.
    DB::table('ingest_runs')->whereIn('status', ['ok', 'running'])->update(['status' => 'superseded']);
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    $ingest = new DayIngest(DB::connection(), t08Fixture('snapshots'));
    $ingest->sync('2026-10-12', true, 'replay');
    $ingest->sync('2026-10-10', true, 'replay');

    $summary = (new IngestPending(t08Fixture('snapshots'), 'sync'))->scan();

    expect($summary['dispatched'])->toBe(['2026-10-09', '2026-10-11', '2026-10-14'])
        ->and(PriceDigest::compute(DB::connection()))->toBe($clean);
});

test('a marker with fractional seconds ingests queued: the chunk marker check survives the second truncation in ingest_runs', function (): void {
    $root = t08CopyDays('2026-10-11');
    file_put_contents("{$root}/2026-10-11/marker.txt", "2026-10-11T20:05:45.678+0000\n");
    $manifest = json_decode((string) file_get_contents("{$root}/2026-10-11/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $manifest['marker'] = '2026-10-11T20:05:45.678+0000';
    file_put_contents("{$root}/2026-10-11/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR));

    [$code, $output, $line] = t08Ingest(['--day' => '2026-10-11', '--queued' => true, '--queue-connection' => 'sync', '--wait' => 30, '--snapshots' => $root]);
    $queued = PriceDigest::compute(DB::connection());
    t08Wipe();
    [$syncCode] = t08Ingest(['--day' => '2026-10-11', '--sync' => true, '--snapshots' => $root]);

    expect($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['status' => 'ok', 'mapped' => 21])
        ->and($syncCode)->toBe(0)
        ->and(PriceDigest::compute(DB::connection()))->toBe($queued);
});

// Item 5: the schedule.

test('the scheduler runs the pending scan hourly at :40 without overlapping, beside the unchanged probe', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($event): string => (string) $event->description);
    $scan = $events->get('pokeprices-ingest-pending');

    expect($scan)->not->toBeNull()
        ->and($scan->expression)->toBe('40 * * * *')
        ->and($scan->withoutOverlapping)->toBeTrue()
        ->and($events->get('pokeprices-probe')?->expression)->toBe('* * * * *')
        // T10 adds the hourly freshness log line (same-commit edit, R186).
        // P5-i1 T01 adds the alerts dispatch (same-commit edit, R186 class).
        ->and($events->keys()->sort()->values()->all())->toBe(['pokeprices-alerts-dispatch', 'pokeprices-freshness', 'pokeprices-ingest-pending', 'pokeprices-probe']);
});
