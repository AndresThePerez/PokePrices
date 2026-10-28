<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * The chunk batch's `then` callback: an invokable, serializable class, never a captured
 * closure. It runs once every chunk is done and dispatches FinalizeDay on the run's queue connection.
 */
final class FinalizeDayCallback
{
    public function __construct(
        public readonly int $runId,
        public readonly string $queueConnection,
        public readonly ?string $database = null,
    ) {}

    public function __invoke(Batch $batch): void
    {
        Bus::dispatch(new FinalizeDay($this->runId, $this->queueConnection, $this->database));
    }
}
