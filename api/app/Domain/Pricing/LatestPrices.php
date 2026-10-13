<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds price_latest from price_daily (plan P2-i1 T05 item 2, D4, D5). It runs inside the ingest finalize
 * transaction, after the rollup, and recomputes the whole table with one set-based statement pair: delete every row
 * of the source, then insert the newest real (never filled) candle of each variant with its previous close.
 *
 * - close, day and as_of come from the variant's newest real row, series_epoch from that row;
 * - previous close: the most recent real close on an earlier day in the same series_epoch, at most
 *   config('pricing.fill_cap_days') (14) days before; otherwise every previous field is null;
 * - change = close - previous_close; change_pct = round(change / previous_close * 100, 2) in numeric (half away from
 *   zero), null when previous_close is 0.00.
 *
 * A rebuild of identical price_daily gives identical rows, so the order days arrive in never changes the table, and
 * a variant whose candles vanished loses its row.
 */
final class LatestPrices
{
    public static function rebuild(ConnectionInterface $db): void
    {
        $source = (string) config('pricing.source');
        $cap = (int) config('pricing.fill_cap_days');

        $db->delete('DELETE FROM price_latest WHERE source = ?', [$source]);
        $db->insert(<<<'SQL'
            INSERT INTO price_latest (variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)
            SELECT l.variant_key, l.source, l.series_epoch, l.close, l.day, l.as_of,
                   p.close, p.day, l.close - p.close,
                   CASE WHEN p.close IS NULL OR p.close = 0 THEN NULL ELSE round((l.close - p.close) / p.close * 100, 2) END
            FROM (
                SELECT DISTINCT ON (d.variant_key, d.source) d.variant_key, d.source, d.series_epoch, d.close, d.day, d.as_of
                FROM price_daily d
                WHERE d.source = ? AND NOT d.is_filled
                ORDER BY d.variant_key, d.source, d.day DESC
            ) l
            LEFT JOIN LATERAL (
                SELECT q.close, q.day
                FROM price_daily q
                WHERE q.variant_key = l.variant_key AND q.source = l.source AND NOT q.is_filled
                  AND q.series_epoch = l.series_epoch AND q.day < l.day AND q.day >= l.day - ?::integer
                ORDER BY q.day DESC
                LIMIT 1
            ) p ON true
            SQL, [$source, $cap]);
    }
}
