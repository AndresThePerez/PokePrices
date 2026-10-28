<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Movement rule `draft-v1` into the price_events outbox (plan P2-i1 T13 item 2, D4, D6, R177(4), R180a; System Design
 * §2, §5). detect() runs inside the finalize transaction of a sync or queued run, after the rollup, price_latest,
 * price_movers and movement_volume; replay never calls it.
 *
 * A real (never filled) close on day D fires when its D4 previous close exists (the most recent real close on an
 * earlier day in the same series_epoch, at most config('pricing.fill_cap_days') days back), abs(change_pct) is at
 * least MovementRule::pct() and abs(change) at least MovementRule::abs(), and D is the newest
 * finalized day of the source. Only the run's own day is written, one row per variant, with ON CONFLICT DO NOTHING on
 * the outbox key: an event is never updated or deleted, so re-finalizing a day adds nothing. dispatched_at stays null
 * (dispatch is P5). The run's counts gain:
 *
 * - `events`: the rows this finalize inserted;
 * - `epoch_breaks`: real closes on D with no previous close in their own epoch but a real close within the cap in
 *   another epoch (a new series_epoch never fires);
 * - `backfill`: the crossings of the run's recompute window that the outbox does not hold. A day older than the
 *   newest finalized day writes nothing, so all its window's crossings count here, including the ones a late older
 *   day exposes on later days; a run of the newest day counts 0.
 */
final class DetectMovement
{
    /**
     * The D4 comparisons of the real closes of price_daily between params.from_day and params.to_day, as the CTE
     * `comparisons`; it reads a CTE `params` holding source, from_day, to_day and cap. change_pct is null when the
     * previous close is 0.00 (D4), so such a comparison never crosses a rule.
     */
    public const string COMPARISONS = <<<'SQL'
        comparisons AS (
            SELECT c.day, c.source, c.variant_key, c.series_epoch, c.close, c.as_of,
                   p.close AS previous_close, p.day AS previous_day, p.as_of AS previous_as_of,
                   c.close - p.close AS change,
                   CASE WHEN p.close = 0 THEN NULL ELSE round((c.close - p.close) / p.close * 100, 2) END AS change_pct
            FROM price_daily c
            CROSS JOIN params x
            JOIN LATERAL (
                SELECT q.close, q.day, q.as_of
                FROM price_daily q
                WHERE q.variant_key = c.variant_key AND q.source = c.source AND NOT q.is_filled
                  AND q.series_epoch = c.series_epoch AND q.day < c.day AND q.day >= c.day - x.cap
                ORDER BY q.day DESC
                LIMIT 1
            ) p ON true
            WHERE c.source = x.source AND NOT c.is_filled AND c.day BETWEEN x.from_day AND x.to_day
        )
        SQL;

    /**
     * Real closes between params.from_day and params.to_day that break an epoch, as the CTE `epoch_breaks` (day,
     * variant_key): no real close in their own epoch within the cap, but one in another epoch.
     */
    public const string EPOCH_BREAKS = <<<'SQL'
        epoch_breaks AS (
            SELECT c.day, c.variant_key
            FROM price_daily c
            CROSS JOIN params x
            WHERE c.source = x.source AND NOT c.is_filled AND c.day BETWEEN x.from_day AND x.to_day
              AND NOT EXISTS (
                  SELECT 1 FROM price_daily q
                  WHERE q.variant_key = c.variant_key AND q.source = c.source AND NOT q.is_filled
                    AND q.series_epoch = c.series_epoch AND q.day < c.day AND q.day >= c.day - x.cap
              )
              AND EXISTS (
                  SELECT 1 FROM price_daily q
                  WHERE q.variant_key = c.variant_key AND q.source = c.source AND NOT q.is_filled
                    AND q.series_epoch <> c.series_epoch AND q.day < c.day AND q.day >= c.day - x.cap
              )
        )
        SQL;

    /**
     * @param  string  $day  the run's day D, YYYY-MM-DD
     * @param  array{from: string, to: string}  $window  the run's D3 recompute window
     * @param  bool  $newest  whether D is the newest finalized day of the source
     * @return array{events: int, epoch_breaks: int, backfill: int}
     */
    public static function detect(ConnectionInterface $db, int $runId, string $day, array $window, bool $newest): array
    {
        $source = PricingConfig::source();
        $cap = (int) config('pricing.fill_cap_days');
        $rule = MovementRule::rule();
        $pct = MovementRule::pct();
        $abs = MovementRule::abs();

        $events = 0;
        if ($newest) {
            $events = $db->affectingStatement(
                'WITH params AS (SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap,'
                .' ?::numeric AS pct, ?::numeric AS abs, ?::text AS rule, ?::bigint AS run_id), '
                .self::COMPARISONS.' '.<<<'SQL'
                INSERT INTO price_events (type, rule, variant_key, card_id, source, day, series_epoch, direction, close,
                                          previous_close, previous_day, change, change_pct, as_of, previous_as_of, ingest_run_id)
                SELECT 'price.moved', x.rule, m.variant_key, v.card_id, m.source, m.day, m.series_epoch,
                       CASE WHEN m.change > 0 THEN 'up' ELSE 'down' END, m.close, m.previous_close, m.previous_day,
                       m.change, m.change_pct, m.as_of, m.previous_as_of, x.run_id
                FROM comparisons m
                CROSS JOIN params x
                JOIN card_variants v ON v.variant_key = m.variant_key
                WHERE abs(m.change_pct) >= x.pct AND abs(m.change) >= x.abs
                ORDER BY m.variant_key COLLATE "C"
                ON CONFLICT (variant_key, source, day, series_epoch) DO NOTHING
                SQL,
                [$source, $day, $day, $cap, $pct, $abs, $rule, $runId],
            );
        }

        $breaks = $db->selectOne(
            'WITH params AS (SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap), '
            .self::EPOCH_BREAKS.' SELECT count(*) AS n FROM epoch_breaks',
            [$source, $day, $day, $cap],
            false,
        );

        $backfill = $db->selectOne(
            'WITH params AS (SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap, ?::numeric AS pct, ?::numeric AS abs), '
            .self::COMPARISONS.' '.<<<'SQL'
                SELECT count(*) AS n
                FROM comparisons m
                CROSS JOIN params x
                WHERE abs(m.change_pct) >= x.pct AND abs(m.change) >= x.abs
                  AND NOT EXISTS (
                      SELECT 1 FROM price_events e
                      WHERE e.variant_key = m.variant_key AND e.source = m.source AND e.day = m.day AND e.series_epoch = m.series_epoch
                  )
                SQL,
            [$source, $window['from'], $window['to'], $cap, $pct, $abs],
            false,
        );

        return [
            'events' => $events,
            'epoch_breaks' => $breaks instanceof stdClass ? (int) $breaks->n : 0,
            'backfill' => $backfill instanceof stdClass ? (int) $backfill->n : 0,
        ];
    }
}
