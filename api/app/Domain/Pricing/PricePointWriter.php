<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Upserts one chunk of normalized price rows into price_points with one multi-row statement.
 * A conflicting row is rewritten only when a stored value differs, so the RETURNING count is the rows inserted or
 * changed (`upserted`); the rest of the chunk's rows were already stored as they are (`unchanged`). The caller keeps
 * a chunk at config('pricing.chunk_rows') rows (200), and the reader's de-duplication means no statement ever carries
 * one key twice.
 */
final class PricePointWriter
{
    private const array COLUMNS = [
        'variant_key', 'source', 'observed_at', 'day', 'series_epoch', 'source_product_id', 'source_subtype',
        'market', 'low', 'mid', 'high', 'direct_low', 'ingest_run_id',
    ];

    /** The columns compared before a conflicting row is rewritten (ingest_run_id is bookkeeping, not a value). */
    private const array VALUES = ['series_epoch', 'source_product_id', 'source_subtype', 'market', 'low', 'mid', 'high', 'direct_low'];

    /**
     * @param  list<array{variant_key: string, series_epoch: int, source_product_id: int, source_subtype: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>  $points
     * @return int the rows inserted or changed
     */
    public static function upsert(ConnectionInterface $db, array $points, CarbonImmutable $observedAt, int $runId): int
    {
        if ($points === []) {
            return 0;
        }

        $source = PricingConfig::source();
        $at = $observedAt->utc()->format('Y-m-d H:i:sP');
        $day = $observedAt->utc()->format('Y-m-d');
        $tuple = '('.implode(', ', array_fill(0, count(self::COLUMNS), '?')).')';
        $bindings = [];
        foreach ($points as $p) {
            array_push($bindings, $p['variant_key'], $source, $at, $day, $p['series_epoch'], $p['source_product_id'],
                $p['source_subtype'], $p['market'], $p['low'], $p['mid'], $p['high'], $p['direct_low'], $runId);
        }

        $update = implode(', ', array_map(fn (string $c): string => "{$c} = EXCLUDED.{$c}", ['day', ...self::VALUES, 'ingest_run_id']));
        $stored = implode(', ', array_map(fn (string $c): string => "price_points.{$c}", self::VALUES));
        $incoming = implode(', ', array_map(fn (string $c): string => "EXCLUDED.{$c}", self::VALUES));

        $returned = $db->select(
            'INSERT INTO price_points ('.implode(', ', self::COLUMNS).') VALUES '
            .implode(', ', array_fill(0, count($points), $tuple))
            ." ON CONFLICT (variant_key, source, observed_at) DO UPDATE SET {$update}"
            ." WHERE ({$stored}) IS DISTINCT FROM ({$incoming}) RETURNING 1 AS written",
            $bindings,
            false,
        );

        return count($returned);
    }
}
