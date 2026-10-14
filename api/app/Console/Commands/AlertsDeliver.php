<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs one PokéPrices delivery sweep in-line (plan P5-i1 T02, D7, D8), with the same caps and budget as the
 * scheduled job, and prints {"retired", "claimed", "delivered", "retry", "failed", "released"}; exit 0.
 */
#[Description('Send the webhook deliveries that are due, once, within the sweep caps.')]
#[Signature('pokeprices:alerts:deliver')]
final class AlertsDeliver extends Command
{
    public function handle(DeliverySender $sender, RetryPolicy $retry): int
    {
        $counts = DeliverDue::sweep(DB::connection(), $sender, $retry, CarbonImmutable::now());
        $this->line(json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
