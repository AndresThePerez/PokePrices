<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts the queued ingest of one collected day (plan P2-i1 T08, D12): under the `pricing:ingest` lock it runs
 * DayIngest::start() in one transaction (validation, the run row, the older-marker delete and the chunk plan), then
 * dispatches the chunks as one batch. When another session holds the lock, the job releases itself for 60 s.
 *
 * Every job of the queued path goes on $queueConnection (`redis` in production), queue config('pricing.queue'); the
 * connection travels to the chunks and to finalize, so tests can run the same jobs on `database` or `sync`.
 */
final class StartDayIngest implements ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'redis';

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
        if ($this->begin()['status'] === 'locked') {
            Log::info(IngestLock::HELD_MESSAGE, ['job' => 'StartDayIngest', 'day' => $this->day]);
            $this->release(60);
        }
    }

    /**
     * The job's work, also run inline by `pokeprices:prices:ingest --queued` so it can print the run id at once.
     * `error` is set on a `running` result when the batch could not be dispatched (or, on the sync queue, a chunk
     * failed inline and marked the run failed); the pending scan resumes a run left running.
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
