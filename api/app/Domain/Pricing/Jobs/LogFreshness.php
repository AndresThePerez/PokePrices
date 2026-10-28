<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\Freshness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * The hourly freshness log line (scheduled as pokeprices-freshness). One record with
 * message price_freshness and context {source, feed, as_of, age_hours, slo_hours, within_slo} through the default
 * channel's JSON formatter: info within the SLO, warning when breached or when no prices were ingested. age_hours is
 * the same one-decimal value the API and the command print.
 *
 * Timeout 30 s, below the redis queue's retry_after (90 s); three tries, as the worker's --tries gave it before.
 */
#[Tries(3)]
#[Timeout(30)]
final class LogFreshness implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $freshness = Freshness::measure();
        $context = [
            'source' => $freshness['source'],
            'feed' => $freshness['feed'],
            'as_of' => $freshness['as_of'],
            'age_hours' => $freshness['age_hours'],
            'slo_hours' => $freshness['slo_hours'],
            'within_slo' => $freshness['within_slo'],
        ];

        if ($freshness['within_slo']) {
            Log::info('price_freshness', $context);
        } else {
            Log::warning('price_freshness', $context);
        }
    }
}
