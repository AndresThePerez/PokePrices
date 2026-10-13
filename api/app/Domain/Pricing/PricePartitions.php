<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly partitions of price_points (plan P2-i1 T02). price_points has no DEFAULT partition, so an ingest run
 * calls ensureFor() with its marker before it writes; a month nobody prepared fails with SQLSTATE 23514.
 */
final class PricePartitions
{
    /**
     * Creates the partition of the UTC calendar month holding $observedAt unless it exists, and returns its name
     * (price_points_yYYYYmMM, bounds [month start, next month start) at 00:00+00).
     */
    public static function ensureFor(CarbonImmutable $observedAt): string
    {
        $from = $observedAt->utc()->startOfMonth();
        $to = $from->addMonthNoOverflow();
        $name = sprintf('price_points_y%04dm%02d', $from->year, $from->month);

        DB::statement(sprintf(
            "CREATE TABLE IF NOT EXISTS %s PARTITION OF price_points FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $from->format('Y-m-d H:i:sP'),
            $to->format('Y-m-d H:i:sP'),
        ));

        return $name;
    }
}
