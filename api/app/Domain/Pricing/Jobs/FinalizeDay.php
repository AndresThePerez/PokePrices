<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finalizes a queued run (plan P2-i1 T08 item 1, D12): in one transaction under pg_advisory_xact_lock on the
 * `pricing:ingest` key, DayIngest::finalize() rolls up the D3 window, rebuilds price_latest and sums the chunk counts
 * into the run. A run that is no longer `running` (already finalized by a duplicate delivery, superseded by a replay,
 * or failed) is left as it is. When the tries run out, failed() marks the run `failed`, so --resume can re-open it.
 */
#[Tries(3)]
#[Backoff([30, 120, 600])]
final class FinalizeDay implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $runId,
        public readonly string $queueConnection = StartDayIngest::CONNECTION,
        public readonly ?string $database = null,
    ) {
        $this->onConnection($queueConnection)->onQueue((string) config('pricing.queue'));
    }

    public function handle(): void
    {
        $db = DB::connection($this->database);
        $db->transaction(function () use ($db): void {
            $db->select('SELECT pg_advisory_xact_lock(?)', [IngestLock::KEY], false);
            if ($db->table('ingest_runs')->where('id', $this->runId)->value('status') !== 'running') {
                return;
            }
            (new DayIngest($db, ''))->finalize($this->runId);
        });
    }

    public function failed(Throwable $exception): void
    {
        DB::connection($this->database)->update(
            "UPDATE ingest_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status = 'running'",
            ['finalize failed: '.mb_substr($exception->getMessage(), 0, 2000), CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'), $this->runId],
        );
    }
}
