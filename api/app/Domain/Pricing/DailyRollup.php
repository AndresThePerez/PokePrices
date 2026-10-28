<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use stdClass;

/**
 * The daily candles of price_daily (plan P2-i1 T04 item 5, T07 item 1, D1, D3; review F3).
 *
 * Ingesting day D recomputes the window LEAST(D, P + 1) .. LEAST(D + 14, N), where P is the newest ingested day
 * before the run (none: the window starts at D) and N the newest after it. The window therefore holds the
 * uncollected days between P and D when D is newer than P, and they are filled like any other day. Within it:
 *
 * - a real candle for every variant with a non-null market observation on a day (n_obs = the observations of the
 *   latest epoch seen that day, open the earliest and close the latest of them, as_of the latest marker);
 * - a filled candle on every other day that follows a real close of the variant by at most
 *   config('pricing.fill_cap_days') (14) days: open = high = low = close = that close, n_obs 0, is_filled, filled_from
 *   the real day, as_of its marker and its series_epoch;
 * - no row past the cap, before the variant's first real close, or past N.
 *
 * The real closes a fill may repeat come from price_points inside the window and from the real price_daily rows of the
 * 14 days before it (those rows only change when their own day is ingested, which recomputes them). The target rows
 * are written with one set-based statement: rows of the window that are not in the target are deleted, the rest are
 * upserted where they differ. Identical price_points therefore give byte-identical rows whatever the ingest order.
 */
final class DailyRollup
{
    /**
     * @param  string  $day  D, YYYY-MM-DD
     * @param  string|null  $previousNewest  P, YYYY-MM-DD, or null when nothing was ingested before
     * @return array{from: string, to: string, rows: int} the window and the price_daily rows it holds afterwards
     */
    public static function rollup(ConnectionInterface $db, string $day, ?string $previousNewest): array
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');
        $p = $previousNewest === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $previousNewest, 'UTC');
        if ($d === null || ($previousNewest !== null && $p === null)) {
            throw new InvalidArgumentException("rollup needs YYYY-MM-DD days, got {$day} and {$previousNewest}");
        }
        $cap = (int) config('pricing.fill_cap_days');
        $newest = $p === null || $d->greaterThan($p) ? $d : $p;
        $from = $p === null ? $d : $d->min($p->addDay());
        $to = $d->addDays($cap)->min($newest);

        $source = PricingConfig::source();
        $days = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        $db->statement(<<<'SQL'
            WITH params AS (
                SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap
            ),
            obs AS (
                SELECT p.variant_key, p.source, p.day, p.series_epoch, p.market, p.observed_at,
                       first_value(p.series_epoch) OVER (
                           PARTITION BY p.variant_key, p.source, p.day ORDER BY p.observed_at DESC
                       ) AS latest_epoch
                FROM price_points p, params x
                WHERE p.source = x.source AND p.market IS NOT NULL
                  AND p.observed_at >= x.from_day::timestamp AT TIME ZONE 'UTC'
                  AND p.observed_at < (x.to_day + 1)::timestamp AT TIME ZONE 'UTC'
            ),
            real_rows AS (
                SELECT o.variant_key, o.source, o.day, o.series_epoch,
                       (array_agg(o.market ORDER BY o.observed_at))[1] AS open,
                       max(o.market) AS high, min(o.market) AS low,
                       (array_agg(o.market ORDER BY o.observed_at DESC))[1] AS close,
                       count(*)::smallint AS n_obs, max(o.observed_at) AS as_of
                FROM obs o
                WHERE o.series_epoch = o.latest_epoch
                GROUP BY o.variant_key, o.source, o.day, o.series_epoch
            ),
            anchors AS (
                SELECT variant_key, source, day, series_epoch, close, as_of FROM real_rows
                UNION ALL
                SELECT d.variant_key, d.source, d.day, d.series_epoch, d.close, d.as_of
                FROM price_daily d, params x
                WHERE d.source = x.source AND NOT d.is_filled
                  AND d.day >= x.from_day - x.cap AND d.day < x.from_day
            ),
            spans AS (
                SELECT a.variant_key, a.source, a.day, a.series_epoch, a.close, a.as_of,
                       lead(a.day) OVER (PARTITION BY a.variant_key, a.source ORDER BY a.day) AS next_day
                FROM anchors a
            ),
            filled_rows AS (
                SELECT s.variant_key, s.source, g.day::date AS day, s.series_epoch, s.close, s.day AS filled_from, s.as_of
                FROM spans s
                CROSS JOIN params x
                CROSS JOIN LATERAL generate_series(
                    GREATEST(s.day + 1, x.from_day)::timestamp,
                    LEAST(s.day + x.cap, coalesce(s.next_day - 1, x.to_day), x.to_day)::timestamp,
                    interval '1 day'
                ) AS g(day)
            ),
            target AS (
                SELECT variant_key, source, day, series_epoch, open, high, low, close, n_obs,
                       false AS is_filled, NULL::date AS filled_from, as_of
                FROM real_rows
                UNION ALL
                SELECT variant_key, source, day, series_epoch, close, close, close, close, 0::smallint,
                       true, filled_from, as_of
                FROM filled_rows
            ),
            removed AS (
                DELETE FROM price_daily d
                USING params x
                WHERE d.source = x.source AND d.day BETWEEN x.from_day AND x.to_day
                  AND NOT EXISTS (
                      SELECT 1 FROM target t WHERE t.variant_key = d.variant_key AND t.source = d.source AND t.day = d.day
                  )
            )
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            SELECT variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of
            FROM target
            ON CONFLICT (variant_key, source, day) DO UPDATE SET
                series_epoch = EXCLUDED.series_epoch, open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low,
                close = EXCLUDED.close, n_obs = EXCLUDED.n_obs, is_filled = EXCLUDED.is_filled,
                filled_from = EXCLUDED.filled_from, as_of = EXCLUDED.as_of
            WHERE (price_daily.series_epoch, price_daily.open, price_daily.high, price_daily.low, price_daily.close,
                   price_daily.n_obs, price_daily.is_filled, price_daily.filled_from, price_daily.as_of)
                IS DISTINCT FROM (EXCLUDED.series_epoch, EXCLUDED.open, EXCLUDED.high, EXCLUDED.low, EXCLUDED.close,
                   EXCLUDED.n_obs, EXCLUDED.is_filled, EXCLUDED.filled_from, EXCLUDED.as_of)
            SQL, [$source, $days[0], $days[1], $cap]);

        $rows = $db->selectOne('SELECT count(*) AS n FROM price_daily WHERE source = ? AND day BETWEEN ?::date AND ?::date', [$source, ...$days], false);

        return ['from' => $days[0], 'to' => $days[1], 'rows' => $rows instanceof stdClass ? (int) $rows->n : 0];
    }
}
