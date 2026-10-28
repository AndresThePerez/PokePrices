<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\FinalizeDayCallback;
use App\Domain\Pricing\Jobs\IngestChunk;
use App\Domain\Pricing\Jobs\MarkRunFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Ingests one collected TCGCSV day into price_points, price_daily and price_latest (plan P2-i1 T04, T05; System Design §5
 * FetchSnapshot → Normalize → Upsert → Rollup). It is built from three seams that T08 wires to queued jobs unchanged
 * (review F10):
 *
 * - start(): validates every file of the day before any write (a refusal records a `refused` run and nothing else),
 *   skips a marker that already has an `ok` run unless forced, opens the run, prepares the month's partition, deletes
 *   the day's rows of any other marker (D1: the latest marker per UTC date wins; review F7) and plans the chunks;
 * - chunk(): normalizes and upserts one chunk of at most config('pricing.chunk_rows') de-duplicated rows of a group;
 * - finalize(): rolls the D3 window up with its carry-forward fills (T07), rebuilds price_latest (T05), the window's
 *   price_movers (T12) and movement_volume (T13), then, for a live run only, writes the newest day's movement events
 *   into the price_events outbox (DetectMovement, T13; never in a replay, R180a), sums the counts and marks the run `ok`.
 *
 * sync() holds the `pricing:ingest` lock (D12) and runs all three inside one transaction, so a failure anywhere
 * leaves no price row; the `failed` run is written after the rollback. The product map is read, never written: each
 * chunk loads only its own products' rows, and only while the map build its run started on is current (plan Q1-i1 T03,
 * review b B1). Prices never leave Postgres for Elasticsearch.
 *
 * Queued mode (T08) wires the same seams to jobs: StartDayIngest runs start() under the lock and dispatchChunks()
 * sends one IngestChunk per chunk as a batch whose `then` dispatches FinalizeDay; resume() re-opens a queued run and
 * re-dispatches only its chunks that are not done.
 */
final class DayIngest
{
    /** Counts each chunk writes and finalize sums. */
    private const array CHUNK_COUNTS = ['mapped', 'unmapped', 'null_market', 'upserted', 'unchanged'];

    /** @var array<int, PriceSnapshotReader> run id => the reader of its day */
    private array $readers = [];

    private ?CarbonImmutable $marker = null;

    public function __construct(private readonly ConnectionInterface $db, private readonly string $snapshotsRoot) {}

    /**
     * The sync ingest: lock, then start, every chunk and finalize in one transaction. The replay (T07) runs each day
     * through here with mode `replay` while it already holds the lock; the advisory lock is re-entrant per session.
     *
     * @param  'sync'|'replay'  $mode
     * @return array{status: 'ok'|'refused'|'skipped_unchanged'|'failed'|'locked', run_id: int|null, marker: CarbonImmutable|null, error: string|null, counts: array<string, int>}
     */
    public function sync(string $day, bool $force = false, string $mode = 'sync'): array
    {
        if (! IngestLock::tryAcquire($this->db)) {
            return ['status' => 'locked', 'run_id' => null, 'marker' => null, 'error' => IngestLock::HELD_MESSAGE, 'counts' => []];
        }

        $this->marker = null;
        $startedAt = CarbonImmutable::now('UTC');
        try {
            return $this->db->transaction(function () use ($day, $force, $mode): array {
                $start = $this->start($day, $mode, $force);
                if ($start['status'] !== 'running') {
                    return ['status' => $start['status'], 'run_id' => $start['run_id'], 'marker' => $start['marker'], 'error' => $start['error'], 'counts' => []];
                }
                foreach ($start['chunks'] as $chunkId) {
                    $this->chunk($start['run_id'], $chunkId);
                }
                $counts = $this->finalize($start['run_id']);

                return ['status' => 'ok', 'run_id' => $start['run_id'], 'marker' => $start['marker'], 'error' => null, 'counts' => $counts];
            });
        } catch (Throwable $e) {
            // The transaction rolled back: no price row, chunk or running run of this attempt is left.
            $error = mb_substr($e->getMessage(), 0, 2000);
            $runId = $this->insertRun($day, $this->marker, $mode, 'failed', $startedAt, $error);

            return ['status' => 'failed', 'run_id' => $runId, 'marker' => $this->marker, 'error' => $error, 'counts' => []];
        } finally {
            IngestLock::release($this->db);
        }
    }

    /**
     * Validates the day and opens its run (status `running`) with its chunks planned, or records why it did not.
     *
     * One running run per (source, day) (plan Q1-i1 T02, review b B6): while the day has a `running` run, start()
     * writes nothing (no run, no chunk, so no batch) and answers `locked` with that run's id, which every caller
     * already treats as "another ingest holds this day"; the running run finishes, or the pending scan resumes it.
     *
     * @param  'sync'|'queued'|'replay'  $mode
     * @return array{status: 'running'|'refused'|'skipped_unchanged'|'locked', run_id: int, marker: CarbonImmutable|null, error: string|null, chunks: list<int>}
     */
    public function start(string $day, string $mode, bool $force = false): array
    {
        $source = (string) config('pricing.source');
        $running = $this->db->table('ingest_runs')->where('source', $source)->where('day', $day)->where('status', 'running')->value('id');
        if (is_numeric($running)) {
            return ['status' => 'locked', 'run_id' => (int) $running, 'marker' => null,
                'error' => "a running run of {$day} exists (run {$running})", 'chunks' => []];
        }

        $startedAt = CarbonImmutable::now('UTC');
        $reader = PriceSnapshotReader::at($this->snapshotsRoot, $day);
        try {
            $validated = $reader->validate();
        } catch (DayRefused $e) {
            $this->marker = $e->marker;
            $runId = $this->insertRun($day, $e->marker, $mode, 'refused', $startedAt, $e->error);

            return ['status' => 'refused', 'run_id' => $runId, 'marker' => $e->marker, 'error' => $e->error, 'chunks' => []];
        }
        $marker = $validated['marker'];
        $this->marker = $marker;

        if (! $force && $this->db->table('ingest_runs')->where('source', $source)->where('status', 'ok')
            ->where('marker', $marker->format('Y-m-d H:i:sP'))->exists()) {
            $runId = $this->insertRun($day, $marker, $mode, 'skipped_unchanged', $startedAt, null);

            return ['status' => 'skipped_unchanged', 'run_id' => $runId, 'marker' => $marker, 'error' => null, 'chunks' => []];
        }

        $runId = $this->insertRun($day, $marker, $mode, 'running', $startedAt, null);
        $this->readers = [$runId => $reader];
        PricePartitions::ensureFor($marker, $this->db);

        // D1, review F7: price_points always equals what the folder on disk holds for the day.
        $deleted = $this->db->delete(
            'DELETE FROM price_points WHERE source = ? AND day = ?::date'
            .' AND observed_at >= ?::timestamptz AND observed_at < ?::timestamptz AND observed_at <> ?::timestamptz',
            [$source, $day, $day.' 00:00:00+00:00', $marker->startOfDay()->addDay()->format('Y-m-d H:i:sP'), $marker->format('Y-m-d H:i:sP')],
        );

        $size = max(1, (int) config('pricing.chunk_rows'));
        $chunks = [];
        foreach ($validated['groups'] as $groupId => $rows) {
            for ($offset = 0; $offset < $rows; $offset += $size) {
                $chunks[] = ['ingest_run_id' => $runId, 'chunk_key' => "{$groupId}:{$offset}", 'group_id' => $groupId,
                    'offset' => $offset, 'rows' => min($size, $rows - $offset), 'status' => 'pending'];
            }
        }
        foreach (array_chunk($chunks, 1000) as $batch) {
            $this->db->table('ingest_run_chunks')->insert($batch);
        }

        $build = $this->db->table('map_builds')->max('id');
        $this->db->table('ingest_runs')->where('id', $runId)->update(['counts' => json_encode([
            'rows' => $validated['rows'],
            'duplicate_rows' => $validated['duplicate_rows'],
            'deleted_older_marker' => $deleted,
            'map_build_id' => is_numeric($build) ? (int) $build : null,
        ], JSON_THROW_ON_ERROR)]);

        /** @var list<int> $chunkIds */
        $chunkIds = $this->db->table('ingest_run_chunks')->where('ingest_run_id', $runId)->orderBy('id')->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)->all();

        return ['status' => 'running', 'run_id' => $runId, 'marker' => $marker, 'error' => null, 'chunks' => $chunkIds];
    }

    /**
     * Normalizes and upserts one chunk in its own transaction, reading the run FOR SHARE (D12). Returns the chunk's
     * counts, or null without writing when the run is no longer running (a replay superseded it).
     *
     * @return array<string, int>|null
     */
    public function chunk(int $runId, int $chunkId): ?array
    {
        return $this->db->transaction(function () use ($runId, $chunkId): ?array {
            $run = $this->db->selectOne('SELECT id, day::text AS day, marker, status FROM ingest_runs WHERE id = ? FOR SHARE', [$runId], false);
            if (! $run instanceof stdClass) {
                throw new RuntimeException("ingest run {$runId} does not exist");
            }
            if ($run->status !== 'running') {
                return null;
            }
            $chunk = $this->db->selectOne('SELECT group_id, "offset", "rows", status, counts FROM ingest_run_chunks WHERE id = ? AND ingest_run_id = ? FOR UPDATE', [$chunkId, $runId], false);
            if (! $chunk instanceof stdClass) {
                throw new RuntimeException("chunk {$chunkId} is not a chunk of ingest run {$runId}");
            }
            if ($chunk->status === 'done') {
                return json_decode((string) $chunk->counts, true, 512, JSON_THROW_ON_ERROR);
            }

            $marker = CarbonImmutable::parse((string) $run->marker)->utc();
            $reader = $this->readerFor($runId, (string) $run->day, $marker);
            $rows = array_slice($reader->rows((int) $chunk->group_id), (int) $chunk->offset, (int) $chunk->rows);
            if (count($rows) !== (int) $chunk->rows) {
                throw new RuntimeException("prices/{$chunk->group_id}.json.zst changed since ingest run {$runId} started");
            }

            $normalized = PriceNormalizer::normalize($rows, $this->mapFor($runId, $rows));
            $upserted = PricePointWriter::upsert($this->db, $normalized['points'], $marker, $runId);
            $counts = [
                'mapped' => $normalized['mapped'],
                'unmapped' => $normalized['unmapped'],
                'null_market' => $normalized['null_market'],
                'upserted' => $upserted,
                'unchanged' => $normalized['mapped'] - $upserted,
            ];
            $this->db->update(
                "UPDATE ingest_run_chunks SET status = 'done', error = NULL, attempts = attempts + 1, counts = ?::jsonb WHERE id = ?",
                [json_encode($counts, JSON_THROW_ON_ERROR), $chunkId],
            );

            return $counts;
        });
    }

    /**
     * Rolls up the D3 window, rebuilds the price_latest rows it can change (T05, Q1-i3 T04), the window's price_movers
     * (T12) and movement_volume (T13), runs DetectMovement when the run is live (mode sync or queued; never replay,
     * R180a), sums the run's counts (start-level plus every done chunk, plus `events`, `epoch_breaks` and `backfill`
     * for a live run) and marks the run `ok`, all in one transaction.
     * It refuses a run with any chunk not done: finalize never runs on a partial day.
     *
     * @return array<string, int>
     */
    public function finalize(int $runId): array
    {
        return $this->db->transaction(function () use ($runId): array {
            $run = $this->db->selectOne('SELECT id, day::text AS day, mode, status, counts FROM ingest_runs WHERE id = ? FOR UPDATE', [$runId], false);
            if (! $run instanceof stdClass || $run->status !== 'running') {
                throw new RuntimeException("ingest run {$runId} is not running and cannot be finalized");
            }
            $open = $this->db->table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', '<>', 'done')->count();
            if ($open > 0) {
                throw new RuntimeException("ingest run {$runId} has {$open} chunks not done; finalize refused");
            }

            $source = (string) config('pricing.source');
            $previous = $this->db->selectOne("SELECT max(day)::text AS day FROM ingest_runs WHERE source = ? AND status = 'ok'", [$source], false);
            $previousDay = $previous instanceof stdClass && is_string($previous->day) ? $previous->day : null;
            $window = DailyRollup::rollup($this->db, (string) $run->day, $previousDay);
            LatestPrices::rebuild($this->db, $window['from'], $window['to']);
            Movers::rebuild($this->db, $window['from'], $window['to']);
            MovementVolume::rebuild($this->db, $window['from'], $window['to']);
            // D6, R180a: only a live run (sync or queued) writes the outbox, and only for the newest finalized day.
            $movement = $run->mode === 'replay' ? [] : DetectMovement::detect(
                $this->db, $runId, (string) $run->day, $window, $previousDay === null || strcmp((string) $run->day, $previousDay) >= 0,
            );

            $counts = [];
            $start = json_decode((string) $run->counts, true, 512, JSON_THROW_ON_ERROR);
            foreach (is_array($start) ? $start : [] as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $counts[$key] = $value;
                }
            }
            foreach (self::CHUNK_COUNTS as $key) {
                $counts[$key] = 0;
            }
            foreach ($this->db->table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', 'done')->pluck('counts') as $json) {
                $chunk = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
                foreach (self::CHUNK_COUNTS as $key) {
                    $counts[$key] += is_array($chunk) && is_int($chunk[$key] ?? null) ? $chunk[$key] : 0;
                }
            }
            $counts['daily_rows'] = $window['rows'];
            $counts += $movement;

            $this->db->table('ingest_runs')->where('id', $runId)->update([
                'status' => 'ok',
                'finished_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'),
                'counts' => json_encode($counts, JSON_THROW_ON_ERROR),
            ]);

            return $counts;
        });
    }

    /**
     * Queued mode (T08): dispatches the run's chunks as one batch on $queueConnection, queue config('pricing.queue').
     * The batch's `then` dispatches FinalizeDay and its `catch` marks the run failed; both are invokable serializable
     * classes (review F4) and the batch does not allow failures, so finalize never runs on a partial day. A run without
     * a chunk to run goes straight to FinalizeDay.
     *
     * @param  list<int>  $chunkIds
     */
    public function dispatchChunks(int $runId, array $chunkIds, string $queueConnection, ?string $database = null): void
    {
        if ($chunkIds === []) {
            Bus::dispatch(new FinalizeDay($runId, $queueConnection, $database));

            return;
        }

        Bus::batch(array_map(fn (int $chunkId): IngestChunk => new IngestChunk($runId, $chunkId, $this->snapshotsRoot, $database), $chunkIds))
            ->then(new FinalizeDayCallback($runId, $queueConnection, $database))
            ->catch(new MarkRunFailed($runId, $database))
            ->name('ingest:'.config('pricing.source').':'.$runId)
            ->onConnection($queueConnection)
            ->onQueue((string) config('pricing.queue'))
            ->dispatch();
    }

    /**
     * Re-opens the newest queued run of the day's current marker when it is `running` or `failed`, and re-dispatches
     * only its chunks that are not done (failed chunks go back to pending); done chunks are not re-read, and finalize
     * sums each chunk's counts once. It holds the `pricing:ingest` lock while it re-opens the run (D12). A newer `ok`
     * or `superseded` run of the marker, a sync or replay run, or a day without a readable marker leaves nothing to
     * resume. started_at is reset to the resume time, so the pending scan's 2 h stall clock restarts.
     *
     * @return array{status: 'resumed'|'none'|'locked', run_id: int|null, marker: CarbonImmutable|null, error: string|null, chunks: list<int>}
     */
    public function resume(string $day, string $queueConnection, ?string $database = null): array
    {
        if (! IngestLock::tryAcquire($this->db)) {
            return ['status' => 'locked', 'run_id' => null, 'marker' => null, 'error' => IngestLock::HELD_MESSAGE, 'chunks' => []];
        }

        try {
            try {
                $marker = PriceSnapshotReader::at($this->snapshotsRoot, $day)->marker();
            } catch (DayRefused $e) {
                return ['status' => 'none', 'run_id' => null, 'marker' => null, 'error' => "nothing to resume for {$day}: {$e->error}", 'chunks' => []];
            }

            $opened = $this->db->transaction(function () use ($day, $marker): ?array {
                $run = $this->db->selectOne(
                    'SELECT id, mode, status FROM ingest_runs WHERE source = ? AND day = ?::date AND marker = ?::timestamptz'
                    ." AND status IN ('ok', 'running', 'failed', 'superseded') ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    [(string) config('pricing.source'), $day, $marker->format('Y-m-d H:i:sP')],
                    false,
                );
                if (! $run instanceof stdClass || $run->mode !== 'queued' || ! in_array($run->status, ['running', 'failed'], true)) {
                    return null;
                }
                $runId = (int) $run->id;
                $this->db->update(
                    "UPDATE ingest_runs SET status = 'running', error = NULL, finished_at = NULL, started_at = ? WHERE id = ?",
                    [CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'), $runId],
                );
                $this->db->update("UPDATE ingest_run_chunks SET status = 'pending', error = NULL WHERE ingest_run_id = ? AND status = 'failed'", [$runId]);

                /** @var list<int> $chunkIds */
                $chunkIds = $this->db->table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', '<>', 'done')
                    ->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

                return ['run_id' => $runId, 'chunks' => $chunkIds];
            });
            if ($opened === null) {
                return ['status' => 'none', 'run_id' => null, 'marker' => $marker, 'error' => "nothing to resume for {$day}: no running or failed queued run of its marker", 'chunks' => []];
            }

            $error = null;
            try {
                $this->dispatchChunks($opened['run_id'], $opened['chunks'], $queueConnection, $database);
            } catch (Throwable $e) {
                // A chunk that failed inline (the sync queue) already marked itself and the run failed.
                report($e);
                $error = mb_substr($e->getMessage(), 0, 2000);
            }

            return ['status' => 'resumed', 'run_id' => $opened['run_id'], 'marker' => $marker, 'error' => $error, 'chunks' => $opened['chunks']];
        } finally {
            IngestLock::release($this->db);
        }
    }

    private function insertRun(string $day, ?CarbonImmutable $marker, string $mode, string $status, CarbonImmutable $startedAt, ?string $error): int
    {
        $finished = $status === 'running' ? null : CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP');

        return (int) $this->db->table('ingest_runs')->insertGetId([
            'source' => (string) config('pricing.source'),
            'feed' => (string) config('pricing.feed'),
            'day' => $day,
            'marker' => $marker?->format('Y-m-d H:i:sP'),
            'mode' => $mode,
            'status' => $status,
            'started_at' => $startedAt->format('Y-m-d H:i:sP'),
            'finished_at' => $finished,
            'error' => $error,
        ]);
    }

    /**
     * The run's reader; a reader opened after start (a queued chunk) first checks the folder still holds the run's
     * marker. ingest_runs stores the marker to the second (the 'Y-m-d H:i:sP' text every writer uses), so the folder's
     * marker is compared at that same precision: a fractional-second marker is not a mismatch (T08).
     */
    private function readerFor(int $runId, string $day, CarbonImmutable $marker): PriceSnapshotReader
    {
        if (! isset($this->readers[$runId])) {
            $reader = PriceSnapshotReader::at($this->snapshotsRoot, $day);
            if ($reader->marker()->format('Y-m-d H:i:sP') !== $marker->utc()->format('Y-m-d H:i:sP')) {
                throw new RuntimeException("snapshot day {$day} no longer holds the marker of ingest run {$runId}");
            }
            $this->readers = [$runId => $reader];
        }

        return $this->readers[$runId];
    }

    /**
     * The map rows of the chunk's products only, in one query: "<productId>|<subTypeName>" => variant and epoch (D2).
     * The run is pinned to the map build it started on (plan Q1-i1 T03): when a newer build has landed since, the chunk
     * throws, writes nothing, and fails through the job's tries; the next scan opens a fresh run on the new map. A run
     * that recorded no build skips the check.
     *
     * @param  list<array{productId: int, subTypeName: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>  $rows
     * @return array<string, array{variant_key: string, series_epoch: int}>
     */
    private function mapFor(int $runId, array $rows): array
    {
        $run = $this->db->selectOne("SELECT counts->>'map_build_id' AS map_build_id FROM ingest_runs WHERE id = ?", [$runId], false);
        $recorded = $run instanceof stdClass ? $run->map_build_id : null;
        if (is_numeric($recorded)) {
            $current = $this->db->table('map_builds')->max('id');
            if (! is_numeric($current) || (int) $current !== (int) $recorded) {
                $now = is_numeric($current) ? (string) (int) $current : 'none';
                throw new RuntimeException("the product map changed since ingest run {$runId} started (map build {$recorded}, now {$now})");
            }
        }

        $products = array_values(array_unique(array_map(fn (array $row): int => $row['productId'], $rows)));
        $products = array_values(array_filter($products, fn (int $id): bool => $id >= 1 && $id <= 2147483647));
        if ($products === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->select(
            'SELECT source_product_id, source_subtype, variant_key, series_epoch FROM source_product_map'
            .' WHERE source = ? AND source_product_id = ANY(?::integer[])',
            [(string) config('pricing.source'), '{'.implode(',', $products).'}'],
            false,
        ) as $row) {
            $map[$row->source_product_id.'|'.$row->source_subtype] = [
                'variant_key' => (string) $row->variant_key,
                'series_epoch' => (int) $row->series_epoch,
            ];
        }

        return $map;
    }
}
