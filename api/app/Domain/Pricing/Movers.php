<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;

/**
 * The ranked movers of each collected day in price_movers (plan P2-i1 T12, D4, D7; System Design §4: a nightly ranked
 * snapshot, so "top movers" is an index scan).
 *
 * For day D, the variants with a real (never filled) close on D and a D4 previous close (the most recent real close
 * on an earlier day in the same series_epoch, at most config('pricing.fill_cap_days') days back) of at least
 * config('pricing.movers.min_previous_close') are ranked: `up` when change > 0, `down` when change < 0, by
 * abs(change_pct) desc, abs(change) desc, variant_key asc (byte order), top config('pricing.movers.limit') per
 * direction. There is no $1 floor (R177(10)); that belongs to the movement rule. The window is the previous close.
 *
 * rebuild() runs inside the ingest finalize transaction, after the rollup and price_latest, for every day of the D3
 * recompute window (the days whose real closes or previous closes the ingest can change): it deletes those days'
 * rows and re-inserts them with one statement each. Identical price_daily therefore gives identical rows, whatever
 * the order days arrive in. It runs in a replay too: movers are derived data, outside PriceDigest (R180b).
 */
final class Movers
{
    /**
     * @param  string  $from  the first day of the window, YYYY-MM-DD
     * @param  string  $to  the last day of the window, YYYY-MM-DD
     */
    public static function rebuild(ConnectionInterface $db, string $from, string $to): void
    {
        $source = (string) config('pricing.source');

        $db->delete('DELETE FROM price_movers WHERE source = ? AND day BETWEEN ?::date AND ?::date', [$source, $from, $to]);
        $db->insert(<<<'SQL'
            WITH params AS (
                SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap,
                       ?::numeric AS min_previous, ?::integer AS lim
            ),
            moves AS (
                SELECT c.day, c.source, c.variant_key, c.series_epoch, c.close,
                       p.close AS previous_close, p.day AS previous_day, c.close - p.close AS change,
                       round((c.close - p.close) / p.close * 100, 2) AS change_pct
                FROM price_daily c
                CROSS JOIN params x
                JOIN LATERAL (
                    SELECT q.close, q.day
                    FROM price_daily q
                    WHERE q.variant_key = c.variant_key AND q.source = c.source AND NOT q.is_filled
                      AND q.series_epoch = c.series_epoch AND q.day < c.day AND q.day >= c.day - x.cap
                    ORDER BY q.day DESC
                    LIMIT 1
                ) p ON true
                WHERE c.source = x.source AND NOT c.is_filled AND c.day BETWEEN x.from_day AND x.to_day
                  AND p.close >= x.min_previous AND c.close <> p.close
            ),
            ranked AS (
                SELECT m.*, CASE WHEN m.change > 0 THEN 'up' ELSE 'down' END AS direction,
                       row_number() OVER (
                           PARTITION BY m.day, m.change > 0
                           ORDER BY abs(m.change_pct) DESC, abs(m.change) DESC, m.variant_key COLLATE "C" ASC
                       ) AS rank
                FROM moves m
            )
            INSERT INTO price_movers (day, source, direction, rank, variant_key, card_id, series_epoch, close,
                                      previous_close, previous_day, change, change_pct)
            SELECT r.day, r.source, r.direction, r.rank, r.variant_key, v.card_id, r.series_epoch, r.close,
                   r.previous_close, r.previous_day, r.change, r.change_pct
            FROM ranked r
            JOIN card_variants v ON v.variant_key = r.variant_key
            CROSS JOIN params x
            WHERE r.rank <= x.lim
            SQL, [
            $source, $from, $to,
            (int) config('pricing.fill_cap_days'),
            (string) config('pricing.movers.min_previous_close'),
            (int) config('pricing.movers.limit'),
        ]);
    }
}
