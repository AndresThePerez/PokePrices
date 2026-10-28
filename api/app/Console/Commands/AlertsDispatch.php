<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerts\DeliverableDay;
use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\PricingConfig;
use App\Support\IsoDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs one PokéPrices alerts dispatch in-line and prints its summary as one JSON line;
 * exit 0, also when nothing is deliverable. --reset-watermark=<YYYY-MM-DD> is the operator reset: it
 * deletes the source's dispatch_days rows dated after that day, prints {"reset_after", "deleted"} and dispatches
 * nothing; a malformed date, or one after the newest finalized day, exits 1 and deletes nothing.
 */
#[Description('Dispatch the movement events of the newest finalized day to the webhooks of the people who watch or hold each card.')]
#[Signature('pokeprices:alerts:dispatch
    {--reset-watermark= : Delete the dispatch records dated after this day (YYYY-MM-DD) and dispatch nothing}')]
final class AlertsDispatch extends Command
{
    public function handle(CatalogRepository $catalog): int
    {
        $reset = $this->option('reset-watermark');
        if ($reset !== null || $this->input->hasParameterOption('--reset-watermark')) {
            return $this->reset(is_string($reset) ? $reset : '');
        }

        $summary = DispatchEvents::run(DB::connection(), $catalog, CarbonImmutable::now());
        $this->line(self::json($summary));

        return self::SUCCESS;
    }

    private function reset(string $day): int
    {
        if (! IsoDay::isDay($day)) {
            $this->error('--reset-watermark needs a date as YYYY-MM-DD; nothing was deleted.');

            return self::FAILURE;
        }

        $db = DB::connection();
        $source = PricingConfig::source();
        $newest = DeliverableDay::terms($db, $source, CarbonImmutable::now())['newest'];
        if ($newest === null) {
            $this->error('There is no finalized day to reset to; nothing was deleted.');

            return self::FAILURE;
        }
        if (strcmp($day, $newest) > 0) {
            $this->error("{$day} is after the newest finalized day {$newest}; nothing was deleted.");

            return self::FAILURE;
        }

        $this->line(self::json(['reset_after' => $day, 'deleted' => DeliverableDay::resetAfter($db, $source, $day)]));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
