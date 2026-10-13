<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use stdClass;

/**
 * The daily candles of price_daily (plan P2-i1 T04 item 5, D1, D3). Observed rows only in T04; carry-forward is T07.
 *
 * Ingesting day D recomputes the window LEAST(D, P + 1) .. LEAST(D + 14, N), where P is the newest ingested day
 * before the run (none: the window starts at D) and N the newest after it (review F3). Within the window every
 * variant with a non-null market observation on a day gets one real candle (n_obs = the observations of the latest
 * epoch seen that day, open the earliest and close the latest of them, as_of the latest marker), through one
 * set-based INSERT … SELECT … ON CONFLICT DO UPDATE. A candle whose observation is gone (a later marker replaced it,
 * D1) is deleted. Identical price_points therefore give byte-identical rows whatever the ingest order.
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
        $newest = $p === null || $d->greaterThan($p) ? $d : $p;
        $from = $p === null ? $d : $d->min($p->addDay());
        $to = $d->addDays((int) config('pricing.fill_cap_days'))->min($newest);

        $source = (string) config('pricing.source');
        $window = [$from->format('Y-m-d H:i:sP'), $to->addDay()->format('Y-m-d H:i:sP')];
        $days = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        $db->delete(<<<'SQL'
            DELETE FROM price_daily d
            WHERE d.source = ? AND d.day BETWEEN ?::date AND ?::date
              AND NOT EXISTS (
                  SELECT 1 FROM price_points p
                  WHERE p.variant_key = d.variant_key AND p.source = d.source AND p.day = d.day
                    AND p.market IS NOT NULL AND p.observed_at >= ?::timestamptz AND p.observed_at < ?::timestamptz
              )
            SQL, [$source, ...$days, ...$window]);

        $db->statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            SELECT o.variant_key, o.source, o.day, o.series_epoch,
                   (array_agg(o.market ORDER BY o.observed_at))[1],
                   max(o.market), min(o.market),
                   (array_agg(o.market ORDER BY o.observed_at DESC))[1],
                   count(*)::smallint, false, NULL::date, max(o.observed_at)
            FROM (
                SELECT p.variant_key, p.source, p.day, p.series_epoch, p.market, p.observed_at,
                       first_value(p.series_epoch) OVER (
                           PARTITION BY p.variant_key, p.source, p.day ORDER BY p.observed_at DESC
                       ) AS latest_epoch
                FROM price_points p
                WHERE p.source = ? AND p.market IS NOT NULL
                  AND p.observed_at >= ?::timestamptz AND p.observed_at < ?::timestamptz
            ) o
            WHERE o.series_epoch = o.latest_epoch
            GROUP BY o.variant_key, o.source, o.day, o.series_epoch
            ON CONFLICT (variant_key, source, day) DO UPDATE SET
                series_epoch = EXCLUDED.series_epoch, open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low,
                close = EXCLUDED.close, n_obs = EXCLUDED.n_obs, is_filled = EXCLUDED.is_filled,
                filled_from = EXCLUDED.filled_from, as_of = EXCLUDED.as_of
            WHERE (price_daily.series_epoch, price_daily.open, price_daily.high, price_daily.low, price_daily.close,
                   price_daily.n_obs, price_daily.is_filled, price_daily.filled_from, price_daily.as_of)
                IS DISTINCT FROM (EXCLUDED.series_epoch, EXCLUDED.open, EXCLUDED.high, EXCLUDED.low, EXCLUDED.close,
                   EXCLUDED.n_obs, EXCLUDED.is_filled, EXCLUDED.filled_from, EXCLUDED.as_of)
            SQL, [$source, ...$window]);

        $rows = $db->selectOne('SELECT count(*) AS n FROM price_daily WHERE source = ? AND day BETWEEN ?::date AND ?::date', [$source, ...$days], false);

        return ['from' => $days[0], 'to' => $days[1], 'rows' => $rows instanceof stdClass ? (int) $rows->n : 0];
    }
}
