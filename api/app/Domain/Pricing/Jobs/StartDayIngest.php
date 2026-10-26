<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts the queued ingest of one collected day (plan P2-i1 T08, D12): under the `pricing:ingest` lock it runs
 * DayIngest::start() in one transaction (validation, the run row, the older-marker delete and the chunk plan), then
 * dispatches the chunks as one batch. When another session holds the lock, the job releases itself for 60 s; when
 * the day already has a running run, start() writes nothing and the job is done (that run finishes or is resumed).
 *
 * Every job of the queued path goes on $queueConnection (`redis` in production), queue config('pricing.queue'); the
 * connection travels to the chunks and to finalize, so tests can run the same jobs on `database` or `sync`.
 *
 * Runtime policy (plan Q1-i1 T02, review b B2, B3): timeout 80 s, below the redis queue's retry_after (90 s). A release
 * on lock contention is not a failure: the job retries until 6 h after it was queued, and three real exceptions
 * (a timeout counts as one) fail it, the three tries the worker's --tries gave it before.
 */
#[Timeout(80)]
#[MaxExceptions(3)]
final class StartDayIngest implements ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'redis';

    public const int RETRY_HOURS = 6;

    public function __construct(
        public readonly string $day,
        public readonly ?string $snapshots = null,
        public readonly bool $force = false,
        public readonly string $queueConnection = self::CONNECTION,
        public readonly ?string $database = null,
    ) {
        $this->onConnection($queueConnection)->onQueue((string) config('pricing.queue'));
    }

    public function handle(): void
    {
        $result = $this->begin();
        if ($result['status'] !== 'locked') {
            return;
        }
        if ($result['run_id'] !== null) {
            Log::info((string) $result['error'], ['job' => 'StartDayIngest', 'day' => $this->day, 'run_id' => $result['run_id']]);

            return;
        }
        Log::info(IngestLock::HELD_MESSAGE, ['job' => 'StartDayIngest', 'day' => $this->day]);
        $this->release(60);
    }

    /** Contention releases do not use up the tries: the deadline does, and MaxExceptions counts the real failures. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(self::RETRY_HOURS);
    }

    /**
     * The job's work, also run inline by `pokeprices:prices:ingest --queued` so it can print the run id at once.
     * `error` is set on a `running` result when the batch could not be dispatched (or, on the sync queue, a chunk
     * failed inline and marked the run failed); the pending scan resumes a run left running. `locked` with a run id
     * means the day already has that running run and nothing was written.
     *
     * @return array{status: 'running'|'refused'|'skipped_unchanged'|'locked', run_id: int|null, marker: CarbonImmutable|null, error: string|null, chunks: list<int>}
     */
    public function begin(): array
    {
        $db = DB::connection($this->database);
        if (! IngestLock::tryAcquire($db)) {
            return ['status' => 'locked', 'run_id' => null, 'marker' => null, 'error' => IngestLock::HELD_MESSAGE, 'chunks' => []];
        }

        try {
            $ingest = new DayIngest($db, $this->snapshotsRoot());
            $start = $db->transaction(fn (): array => $ingest->start($this->day, 'queued', $this->force));
            if ($start['status'] !== 'running') {
                return $start;
            }
            try {
                $ingest->dispatchChunks($start['run_id'], $start['chunks'], $this->queueConnection, $this->database);
            } catch (Throwable $e) {
                report($e);
                $start['error'] = mb_substr($e->getMessage(), 0, 2000);
            }

            return $start;
        } finally {
            IngestLock::release($db);
        }
    }

    private function snapshotsRoot(): string
    {
        $configured = config('services.tcgcsv.snapshots');

        return $this->snapshots ?? (is_string($configured) ? $configured : '');
    }
}
