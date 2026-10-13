<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The measured movement volume in movement_volume (plan P2-i1 T13 items 5 and 6, D4, D6): per (day, source, rule),
 * computed from price_daily and never from price_events, so it holds for every collected day whatever was ingested
 * in which mode. Per day and rule of the grid:
 *
 * - `compared`: real closes with a D4 previous close;
 * - `up` / `down`: the comparisons crossing the rule (change_pct >= pct and change >= abs, or <= -pct and <= -abs);
 * - `epoch_breaks`: real closes whose only previous real close within the cap is in another epoch;
 * - `filled_skipped`: the filled rows of the day (they never fire);
 * - `extreme`: comparisons with close / previous_close > 5 or < 0.2 and abs(change) >= $5;
 * - `sub_2_previous`: the rule's movers whose previous close is under $2.00.
 *
 * Days without a real close (uncollected gap days) have no row. rebuild() runs inside every finalize for the run's D3
 * recompute window (the days whose closes or previous closes the run can change), replay included, and the
 * pokeprices:prices:movement-volume command runs it over every day.
 */
final class MovementVolume
{
    /** The grid: rule => [pct, abs]; draft-v1 comes from config('pricing.movement'). */
    public const array GRID = [
        'tight' => ['3.00', '0.50'],
        'loose' => ['10.00', '2.00'],
        'coarse' => ['20.00', '5.00'],
    ];

    public const string EXTREME_RATIO = '5';

    public const string EXTREME_ABS = '5.00';

    public const string SUB_PREVIOUS = '2.00';

    /**
     * The rules in report order, draft-v1 first.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rules(): array
    {
        return [(string) config('pricing.movement.rule') => [(string) config('pricing.movement.pct'), (string) config('pricing.movement.abs')]] + self::GRID;
    }

    /**
     * Rewrites the movement_volume rows of the source's days from $from to $to.
     *
     * @return int the rows written
     */
    public static function rebuild(ConnectionInterface $db, string $from, string $to): int
    {
        $source = (string) config('pricing.source');
        $values = [];
        $bindings = [$source, $from, $to, (int) config('pricing.fill_cap_days')];
        foreach (self::rules() as $rule => [$pct, $abs]) {
            $values[] = '(?::text, ?::numeric, ?::numeric)';
            array_push($bindings, $rule, $pct, $abs);
        }
        array_push($bindings, self::EXTREME_RATIO, self::EXTREME_RATIO, self::EXTREME_ABS, self::SUB_PREVIOUS);

        $db->delete('DELETE FROM movement_volume WHERE source = ? AND day BETWEEN ?::date AND ?::date', [$source, $from, $to]);

        return $db->affectingStatement(
            'WITH params AS (SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap), '
            .'rules (rule, pct, abs) AS (VALUES '.implode(', ', $values).'), '
            .DetectMovement::COMPARISONS.', '.DetectMovement::EPOCH_BREAKS.', '.<<<'SQL'
            days AS (
                SELECT DISTINCT d.day FROM price_daily d CROSS JOIN params x
                WHERE d.source = x.source AND NOT d.is_filled AND d.day BETWEEN x.from_day AND x.to_day
            ),
            filled AS (
                SELECT d.day, count(*) AS n FROM price_daily d CROSS JOIN params x
                WHERE d.source = x.source AND d.is_filled AND d.day BETWEEN x.from_day AND x.to_day
                GROUP BY d.day
            ),
            breaks AS (SELECT day, count(*) AS n FROM epoch_breaks GROUP BY day),
            measured AS (
                SELECT d.day, r.rule, r.pct, r.abs,
                       count(m.variant_key) AS compared,
                       count(*) FILTER (WHERE m.change_pct >= r.pct AND m.change >= r.abs) AS up,
                       count(*) FILTER (WHERE m.change_pct <= -r.pct AND m.change <= -r.abs) AS down,
                       count(*) FILTER (WHERE m.previous_close > 0
                           AND (m.close > ?::numeric * m.previous_close OR m.close * ?::numeric < m.previous_close)
                           AND abs(m.change) >= ?::numeric) AS extreme,
                       count(*) FILTER (WHERE m.previous_close < ?::numeric AND abs(m.change_pct) >= r.pct AND abs(m.change) >= r.abs) AS sub_2_previous
                FROM days d
                CROSS JOIN rules r
                LEFT JOIN comparisons m ON m.day = d.day
                GROUP BY d.day, r.rule, r.pct, r.abs
            )
            INSERT INTO movement_volume (day, source, rule, pct, abs, compared, up, down, epoch_breaks, filled_skipped, extreme, sub_2_previous)
            SELECT m.day, x.source, m.rule, m.pct, m.abs, m.compared, m.up, m.down, coalesce(b.n, 0), coalesce(f.n, 0), m.extreme, m.sub_2_previous
            FROM measured m
            CROSS JOIN params x
            LEFT JOIN breaks b ON b.day = m.day
            LEFT JOIN filled f ON f.day = m.day
            SQL,
            $bindings,
        );
    }

    /**
     * Rewrites every day of the source that price_daily holds; returns the first and last day, or null when none.
     *
     * @return array{from: string, to: string, rows: int}|null
     */
    public static function rebuildAll(ConnectionInterface $db): ?array
    {
        $range = $db->selectOne(
            'SELECT min(day)::text AS from_day, max(day)::text AS to_day FROM price_daily WHERE source = ? AND NOT is_filled',
            [(string) config('pricing.source')],
            false,
        );
        if (! $range instanceof stdClass || ! is_string($range->from_day) || ! is_string($range->to_day)) {
            return null;
        }
        $db->delete('DELETE FROM movement_volume WHERE source = ?', [(string) config('pricing.source')]);

        return ['from' => $range->from_day, 'to' => $range->to_day, 'rows' => self::rebuild($db, $range->from_day, $range->to_day)];
    }

    /**
     * The measured rows as rule => list of day rows, in rules() order and by day.
     *
     * @return array<string, list<array{day: string, compared: int, up: int, down: int, epoch_breaks: int, filled_skipped: int, extreme: int, sub_2_previous: int}>>
     */
    public static function rows(ConnectionInterface $db): array
    {
        $out = array_fill_keys(array_keys(self::rules()), []);
        foreach ($db->select(
            'SELECT day::text AS day, rule, compared, up, down, epoch_breaks, filled_skipped, extreme, sub_2_previous
             FROM movement_volume WHERE source = ? ORDER BY day, rule',
            [(string) config('pricing.source')],
            false,
        ) as $r) {
            if (! $r instanceof stdClass || ! array_key_exists((string) $r->rule, $out)) {
                continue;
            }
            $out[(string) $r->rule][] = [
                'day' => (string) $r->day,
                'compared' => (int) $r->compared,
                'up' => (int) $r->up,
                'down' => (int) $r->down,
                'epoch_breaks' => (int) $r->epoch_breaks,
                'filled_skipped' => (int) $r->filled_skipped,
                'extreme' => (int) $r->extreme,
                'sub_2_previous' => (int) $r->sub_2_previous,
            ];
        }

        return $out;
    }
}
