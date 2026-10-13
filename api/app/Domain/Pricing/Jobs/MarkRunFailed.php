<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The chunk batch's `catch` callback (plan P2-i1 T08, review F4): an invokable, serializable class, never a captured
 * closure. The first chunk that runs out of tries marks the still-running run `failed`.
 */
final class MarkRunFailed
{
    public function __construct(public readonly int $runId, public readonly ?string $database = null) {}

    public function __invoke(Batch $batch, Throwable $exception): void
    {
        DB::connection($this->database)->update(
            "UPDATE ingest_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status = 'running'",
            ['chunk batch failed: '.mb_substr($exception->getMessage(), 0, 2000), CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'), $this->runId],
        );
    }
}
