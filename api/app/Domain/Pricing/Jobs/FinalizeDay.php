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
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Finalizes a queued run (plan P2-i1 T08 item 1, D12): in one transaction under pg_advisory_xact_lock on the
 * `pricing:ingest` key, DayIngest::finalize() rolls up the D3 window, rebuilds price_latest and sums the chunk counts
 * into the run. A run that is no longer `running` (already finalized by a duplicate delivery, superseded by a replay,
 * or failed) is left as it is. When the tries run out, failed() marks the run `failed`, so --resume can re-open it.
 *
 * Bounded lock wait (plan Q1-i1 T02, review b B3): the run status is read first, so a run that is no longer running
 * returns without touching the lock. A running run takes the lock with pg_try_advisory_xact_lock; while another
 * session holds it (a replay, a sync ingest or a start) the job releases itself for 60 s instead of waiting. Such a
 * release is not a failure: the job retries until 6 h after it was queued, and three real exceptions (a timeout
 * counts as one) still fail it and the run through failed(). Timeout 80 s, below the redis queue's retry_after (90 s).
 */
#[Tries(3)]
#[Backoff([30, 120, 600])]
#[MaxExceptions(3)]
#[Timeout(80)]
final class FinalizeDay implements ShouldQueue
{
    use Queueable;

    public const int RELEASE_SECONDS = 60;

    public function __construct(
        public readonly int $runId,
        public readonly string $queueConnection = StartDayIngest::CONNECTION,
        public readonly ?string $database = null,
    ) {
        $this->onConnection($queueConnection)->onQueue(PricingConfig::queue());
    }

    public function handle(): void
    {
        $db = DB::connection($this->database);
        if ($db->table('ingest_runs')->where('id', $this->runId)->value('status') !== 'running') {
            return;
        }

        $locked = $db->transaction(function () use ($db): bool {
            $row = $db->selectOne('SELECT pg_try_advisory_xact_lock(?) AS taken', [IngestLock::KEY], false);
            if (! $row instanceof stdClass || ($row->taken ?? false) !== true) {
                return true;
            }
            // Re-read under the lock: a replay may have superseded the run since the first read.
            if ($db->table('ingest_runs')->where('id', $this->runId)->value('status') === 'running') {
                (new DayIngest($db, ''))->finalize($this->runId);
            }

            return false;
        });

        if ($locked) {
            Log::info(IngestLock::HELD_MESSAGE, ['job' => 'FinalizeDay', 'run_id' => $this->runId]);
            $this->release(self::RELEASE_SECONDS);
        }
    }

    /** Contention releases do not use up the tries: the deadline does, and MaxExceptions counts the real failures. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(StartDayIngest::RETRY_HOURS);
    }

    public function failed(Throwable $exception): void
    {
        DB::connection($this->database)->update(
            "UPDATE ingest_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status = 'running'",
            ['finalize failed: '.mb_substr($exception->getMessage(), 0, 2000), CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'), $this->runId],
        );
    }
}
