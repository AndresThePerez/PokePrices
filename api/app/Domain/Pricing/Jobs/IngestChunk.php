<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Normalizes and upserts one chunk of a queued run (plan P2-i1 T08 item 2): at most config('pricing.chunk_rows')
 * de-duplicated rows of one group, one statement, its own counts (DayIngest::chunk()).
 *
 * - It returns without writing when its batch is cancelled, and DayIngest::chunk() returns without writing when the
 *   run is no longer `running` (a replay superseded it; D12: the run row is read FOR SHARE).
 * - Every failed attempt is counted on the chunk row with its error. When the tries run out, failed() marks the chunk
 *   and the run `failed`, also on the sync queue, where a job fails once without retries; the worker writes the
 *   failed_jobs row. The batch allows no failure, so finalize never runs on a partial day.
 * - On the sync queue the batch runs its jobs inside the batch repository's transaction, so a thrown exception would
 *   roll the failed() writes back with it. There the job fails itself instead of throwing (a sync job fails once
 *   without retries either way), and the failure stays recorded.
 *
 * Timeout 80 s (plan Q1-i1 T02, review b B2): below the redis queue's retry_after (90 s), so a hung chunk is stopped by
 * its worker before the queue hands it out again; a timeout is one of the three tries.
 */
#[Tries(3)]
#[Backoff([10, 60, 300])]
#[Timeout(80)]
final class IngestChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public readonly int $runId,
        public readonly int $chunkId,
        public readonly string $snapshots,
        public readonly ?string $database = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $db = DB::connection($this->database);
        try {
            (new DayIngest($db, $this->snapshots))->chunk($this->runId, $this->chunkId);
        } catch (Throwable $e) {
            $db->update(
                "UPDATE ingest_run_chunks SET attempts = attempts + 1, error = ? WHERE id = ? AND status <> 'done'",
                [mb_substr($e->getMessage(), 0, 2000), $this->chunkId],
            );
            if ($this->job instanceof SyncJob) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $db = DB::connection($this->database);
        $error = mb_substr($exception->getMessage(), 0, 2000);
        $db->update("UPDATE ingest_run_chunks SET status = 'failed', error = ? WHERE id = ? AND status <> 'done'", [$error, $this->chunkId]);
        $db->update(
            "UPDATE ingest_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status = 'running'",
            ["chunk {$this->chunkId} failed: {$error}", CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'), $this->runId],
        );
    }
}
