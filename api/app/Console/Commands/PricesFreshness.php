<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Freshness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prints the freshness of the PokéPrices prices (plan P2-i1 T10 item 2, D8): one line
 * `tcgplayer via tcgcsv: as of <as_of>, <age_hours> h old, SLO 26 h: ok` (or BREACHED), or
 * `tcgplayer via tcgcsv: no prices ingested`; --json prints GET /v1/prices/freshness's data object instead. It exits 0
 * only within the SLO. BREACHED is the metric working: no newer collected day was ingested in time.
 */
#[Description('Report how old the newest ingested PokéPrices prices are against the 26 hour freshness SLO; exits 1 when breached or when no prices were ingested.')]
#[Signature('pokeprices:prices:freshness
    {--json : Print the freshness as one JSON line (the data object of GET /v1/prices/freshness)}')]
final class PricesFreshness extends Command
{
    public function handle(): int
    {
        $freshness = Freshness::measure();

        if ($this->option('json') === true) {
            $this->line((string) json_encode($freshness, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } elseif ($freshness['as_of'] === null || $freshness['age_hours'] === null) {
            $this->line("{$freshness['source']} via {$freshness['feed']}: no prices ingested");
        } else {
            $this->line(sprintf(
                '%s via %s: as of %s, %s h old, SLO %d h: %s',
                $freshness['source'],
                $freshness['feed'],
                $freshness['as_of'],
                Freshness::display($freshness['age_hours']),
                $freshness['slo_hours'],
                $freshness['within_slo'] ? 'ok' : 'BREACHED',
            ));
        }

        return $freshness['within_slo'] ? self::SUCCESS : self::FAILURE;
    }
}
