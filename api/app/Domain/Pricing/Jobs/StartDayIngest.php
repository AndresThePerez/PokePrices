<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PricingConfig;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts the queued ingest of one collected day: under the `pricing:ingest` lock it runs
 * DayIngest::start() in one transaction (validation, the run row, the older-marker delete and the chunk plan), then
 * dispatches the chunks as one batch. When another session holds the lock, the job releases itself for 60 s; when
 * the day already has a running run, start() writes nothing and the job is done (that run finishes or is resumed).
 * A refused folder and a day blocked by a running run each log a warning (`ingest_refused`, `ingest_blocked`), once
 * per cause.
 *
 * Every job of the queued path goes on $queueConnection (`redis` in production), queue PricingConfig::queue(); the
 * connection travels to the chunks and to finalize, so tests can run the same jobs on `database` or `sync`.
 *
 * Runtime policy: timeout 80 s, below the redis queue's retry_after (90 s). A release
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
        $this->onConnection($queueConnection)->onQueue(PricingConfig::queue());
    }

    public function handle(): void
    {
        $result = $this->begin();
        if ($result['status'] === 'refused' && $result['run_id'] !== null) {
            $this->warnRefused($result['run_id'], $result['marker'], (string) $result['error']);

            return;
        }
        if ($result['status'] !== 'locked') {
            return;
        }
        if ($result['run_id'] !== null) {
            $this->warnBlocked($result['run_id']);

            return;
        }
        Log::info(IngestLock::HELD_MESSAGE, ['job' => 'StartDayIngest', 'day' => $this->day]);
        $this->release(60);
    }

    /**
     * Logs `ingest_refused` with the day, the marker (null when none was read) and the reason, unless an earlier
     * refused run of the day already carries the same marker and reason: the pending scan retries an unchanged refused
     * folder once a day, and each retry writes a refused run but no second warning.
     */
    private function warnRefused(int $runId, ?CarbonImmutable $marker, string $error): void
    {
        $earlier = DB::connection($this->database)->table('ingest_runs')
            ->where('source', PricingConfig::source())
            ->where('day', $this->day)
            ->where('status', 'refused')
            ->where('id', '<', $runId)
            ->where('error', $error);
        $earlier = $marker === null ? $earlier->whereNull('marker') : $earlier->where('marker', $marker->utc()->format('Y-m-d H:i:sP'));
        if ($earlier->exists()) {
            return;
        }

        Log::warning('ingest_refused', [
            'day' => $this->day,
            'marker' => $marker?->utc()->format('Y-m-d\TH:i:s\Z'),
            'error' => $error,
        ]);
    }

    /**
     * Logs `ingest_blocked` with the day and the running run that keeps it from starting, once per run (the cache key
     * remembers a run for 30 days; a cache store that throws only means the warning may repeat). The pending scan only
     * sends a start for a day whose folder's marker has no running run, so a start that finds one met a run of another
     * marker, or a start queued twice; the first stays blocked until a replay or a repair clears that run.
     */
    private function warnBlocked(int $runId): void
    {
        try {
            $first = Cache::add('pricing:ingest_blocked:'.$runId, true, now()->addDays(30));
        } catch (Throwable) {
            $first = true;
        }
        if ($first) {
            Log::warning('ingest_blocked', ['day' => $this->day, 'run_id' => $runId]);
        }
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
