<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds price_latest from price_daily (plan P2-i1 T05 item 2, D4, D5; Q1-i3 T04). It runs inside the ingest finalize
 * transaction, after the rollup, and recomputes, with one set-based statement, the newest real (never filled) candle of
 * each variant of a set S with its previous close:
 *
 * - close, day and as_of come from the variant's newest real row, series_epoch from that row;
 * - previous close: the most recent real close on an earlier day in the same series_epoch, at most
 *   config('pricing.fill_cap_days') (14) days before; otherwise every previous field is null;
 * - change = close - previous_close; change_pct = round(change / previous_close * 100, 2) in numeric (half away from
 *   zero), null when previous_close is 0.00.
 *
 * Given the ingest's recompute window [from, to], S is the variants whose newest real close or previous close the
 * window can change: those with a real price_daily row in [from, to + cap] after the rollup (a real close in the
 * window, or a newest close whose previous close may sit in it), plus those whose current price_latest day or
 * previous_day is at least from (a close that may have vanished). The rollup only writes real rows inside the window,
 * so every other variant keeps the same newest close and previous close, and its row is not written. First the rows
 * of S whose variant has no real close left are deleted (only a row that may have lost its close can be one of them),
 * then S's remaining rows are upserted. Without a window, S is every variant of the source: the whole table is
 * recomputed.
 *
 * A rebuild of identical price_daily gives identical rows, so the order days arrive in never changes the table, and
 * a variant whose candles vanished loses its row.
 */
final class LatestPrices
{
    /**
     * @param  string|null  $from  the first day of the recompute window, YYYY-MM-DD; null with $to for the whole table
     * @param  string|null  $to  the last day of the recompute window, YYYY-MM-DD
     */
    public static function rebuild(ConnectionInterface $db, ?string $from = null, ?string $to = null): void
    {
        $source = PricingConfig::source();
        $cap = (int) config('pricing.fill_cap_days');
        $whole = $from === null || $to === null;

        $db->delete(<<<'SQL'
            DELETE FROM price_latest pl
            WHERE pl.source = ? AND (?::boolean OR pl.day >= ?::date OR pl.previous_day >= ?::date)
              AND NOT EXISTS (
                  SELECT 1 FROM price_daily d WHERE d.variant_key = pl.variant_key AND d.source = pl.source AND NOT d.is_filled
              )
            SQL, [$source, $whole ? 'true' : 'false', $from, $from]);
        $db->statement(<<<'SQL'
            WITH params AS (
                SELECT ?::text AS source, ?::boolean AS whole, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap
            ),
            s AS (
                SELECT d.variant_key
                FROM price_daily d
                CROSS JOIN params x
                WHERE d.source = x.source AND NOT d.is_filled
                  AND (x.whole OR d.day BETWEEN x.from_day AND x.to_day + x.cap)
                UNION
                SELECT pl.variant_key
                FROM price_latest pl
                CROSS JOIN params x
                WHERE pl.source = x.source AND (x.whole OR pl.day >= x.from_day OR pl.previous_day >= x.from_day)
            ),
            target AS (
                SELECT l.variant_key, l.source, l.series_epoch, l.close, l.day, l.as_of,
                       p.close AS previous_close, p.day AS previous_day, l.close - p.close AS change,
                       CASE WHEN p.close IS NULL OR p.close = 0 THEN NULL ELSE round((l.close - p.close) / p.close * 100, 2) END AS change_pct
                FROM (
                    SELECT DISTINCT ON (d.variant_key, d.source) d.variant_key, d.source, d.series_epoch, d.close, d.day, d.as_of
                    FROM price_daily d
                    CROSS JOIN params x
                    WHERE d.source = x.source AND NOT d.is_filled AND d.variant_key IN (SELECT variant_key FROM s)
                    ORDER BY d.variant_key, d.source, d.day DESC
                ) l
                CROSS JOIN params x
                LEFT JOIN LATERAL (
                    SELECT q.close, q.day
                    FROM price_daily q
                    WHERE q.variant_key = l.variant_key AND q.source = l.source AND NOT q.is_filled
                      AND q.series_epoch = l.series_epoch AND q.day < l.day AND q.day >= l.day - x.cap
                    ORDER BY q.day DESC
                    LIMIT 1
                ) p ON true
            )
            INSERT INTO price_latest (variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)
            SELECT variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct
            FROM target
            ON CONFLICT (variant_key, source) DO UPDATE SET
                series_epoch = EXCLUDED.series_epoch, close = EXCLUDED.close, day = EXCLUDED.day, as_of = EXCLUDED.as_of,
                previous_close = EXCLUDED.previous_close, previous_day = EXCLUDED.previous_day, change = EXCLUDED.change,
                change_pct = EXCLUDED.change_pct
            SQL, [$source, $whole ? 'true' : 'false', $from, $to, $cap]);
    }
}
