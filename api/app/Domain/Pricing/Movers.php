<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Models\PriceMover;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use stdClass;

/**
 * The ranked movers of each collected day in price_movers (System Design §4: a nightly ranked
 * snapshot, so "top movers" is an index scan).
 *
 * For day D a variant is ranked when all of this holds:
 *
 * - it has a real (never filled) close on D and a previous close: the most recent real close on an earlier day in the
 *   same series_epoch, at most config('pricing.fill_cap_days') days back;
 * - the previous close is at least config('pricing.movers.min_previous_close');
 * - the move is material: abs(change_pct), as stored (two decimals, half away from zero), is at least
 *   PricingConfig::MOVERS_MIN_CHANGE_PCT and abs(change) is at least PricingConfig::MOVERS_MIN_CHANGE, both;
 * - the series shows recent price activity: of its PricingConfig::MOVERS_ACTIVITY_CLOSES most recent real closes in
 *   the epoch, D's own included and none older than fill_cap_days before D, at least
 *   PricingConfig::MOVERS_ACTIVITY_MIN_DISTINCT are distinct values. A filled or missing day is no observation: it adds
 *   neither a close nor a change, and the count reaches one real close further back instead. A price the series was
 *   at before is no new value, so a spike that comes back counts once;
 * - both closes are in line with the listings of their own observation, the price_points row each close came from
 *   (observed_at = the candle's as_of): its low and mid are present and above zero, and the close is no lower than
 *   PricingConfig::MOVERS_LISTING_LOW_RATIO times the low and no higher than PricingConfig::MOVERS_LISTING_MID_RATIO
 *   times the mid, both bounds included. A close whose observation or listings are missing cannot be shown to be in
 *   line and is not ranked, and neither is a close of 0.00.
 *
 * The ranked variants are `up` when change > 0 and `down` when change < 0, by abs(change_pct) desc, abs(change) desc,
 * variant_key asc (byte order), top PricingConfig::moversLimit() per direction. A move whose percent does not fit
 * numeric(12,2) could not be stored and is left out. The window is the previous close. The evidence for each threshold
 * is in docs/measurements/movers-eligibility.md.
 *
 * rebuild() runs inside the ingest finalize transaction, after the rollup and price_latest, for every day of the
 * recompute window (the days whose real closes or previous closes the ingest can change): it deletes those days'
 * rows and re-inserts them with one statement each. Everything day D's rows are made of (its close, its previous
 * close, the closes counted for activity and the two observations) lies between D - fill_cap_days and D, and ingesting
 * day E recomputes E to E + fill_cap_days. Identical price_daily and price_points therefore give identical rows,
 * whatever the order days arrive in. It runs in a replay too: movers are derived data, outside PriceDigest.
 *
 * Each row also carries as_of, the snapshot marker of its day: max(as_of) of that day's real price_daily closes for the
 * source. A day's real closes only change when its day is in a recompute window, and the same
 * window rebuilds its movers, so the stored value always equals that maximum. newest() and page() are the board's two
 * reads; neither touches price_daily.
 */
final class Movers
{
    /** The largest abs(change_pct) price_movers.change_pct, numeric(12,2), can hold. */
    private const string MAX_CHANGE_PCT = '9999999999.99';

    /**
     * @param  string  $from  the first day of the window, YYYY-MM-DD
     * @param  string  $to  the last day of the window, YYYY-MM-DD
     */
    public static function rebuild(ConnectionInterface $db, string $from, string $to): void
    {
        $source = PricingConfig::source();

        $db->delete('DELETE FROM price_movers WHERE source = ? AND day BETWEEN ?::date AND ?::date', [$source, $from, $to]);
        // moves is materialized on purpose: the previous-close comparison and its cheap filters run first, one index
        // probe per real close of the window as before, and only the material moves that are left (a small share of
        // them) pay for the activity count and the two observation lookups, each a primary-key probe.
        $db->insert(<<<'SQL'
            WITH params AS (
                SELECT ?::text AS source, ?::date AS from_day, ?::date AS to_day, ?::integer AS cap,
                       ?::numeric AS min_previous, ?::integer AS lim,
                       ?::numeric AS min_pct, ?::numeric AS max_pct, ?::numeric AS min_change,
                       ?::integer AS activity_closes, ?::integer AS activity_min,
                       ?::numeric AS low_ratio, ?::numeric AS mid_ratio
            ),
            moves AS MATERIALIZED (
                SELECT c.day, c.source, c.variant_key, c.series_epoch, c.close, c.as_of,
                       p.close AS previous_close, p.day AS previous_day, p.as_of AS previous_as_of,
                       c.close - p.close AS change,
                       round((c.close - p.close) / NULLIF(p.close, 0) * 100, 2) AS change_pct
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
                  AND p.close >= x.min_previous AND abs(c.close - p.close) >= x.min_change
                  AND abs(round((c.close - p.close) / NULLIF(p.close, 0) * 100, 2)) BETWEEN x.min_pct AND x.max_pct
            ),
            eligible AS (
                SELECT m.day, m.source, m.variant_key, m.series_epoch, m.close, m.previous_close, m.previous_day,
                       m.change, m.change_pct
                FROM moves m
                CROSS JOIN params x
                JOIN LATERAL (
                    SELECT o.low, o.mid
                    FROM price_points o
                    WHERE o.variant_key = m.variant_key AND o.source = m.source AND o.observed_at = m.as_of
                    LIMIT 1
                ) listed ON true
                JOIN LATERAL (
                    SELECT o.low, o.mid
                    FROM price_points o
                    WHERE o.variant_key = m.variant_key AND o.source = m.source AND o.observed_at = m.previous_as_of
                    LIMIT 1
                ) previously_listed ON true
                WHERE (
                        SELECT count(DISTINCT recent.close)
                        FROM (
                            SELECT q.close
                            FROM price_daily q
                            WHERE q.variant_key = m.variant_key AND q.source = m.source AND NOT q.is_filled
                              AND q.series_epoch = m.series_epoch AND q.day <= m.day AND q.day >= m.day - x.cap
                            ORDER BY q.day DESC
                            LIMIT x.activity_closes
                        ) recent
                      ) >= x.activity_min
                  -- A low of 0.00 is no listing. A mid that is null or 0.00 fails the upper bound by itself, because
                  -- the close is at least half of a low above zero and so above zero too.
                  AND listed.low > 0
                  AND m.close >= listed.low * x.low_ratio AND m.close <= listed.mid * x.mid_ratio
                  AND previously_listed.low > 0
                  AND m.previous_close >= previously_listed.low * x.low_ratio
                  AND m.previous_close <= previously_listed.mid * x.mid_ratio
            ),
            ranked AS (
                SELECT m.*, CASE WHEN m.change > 0 THEN 'up' ELSE 'down' END AS direction,
                       row_number() OVER (
                           PARTITION BY m.day, m.change > 0
                           ORDER BY abs(m.change_pct) DESC, abs(m.change) DESC, m.variant_key COLLATE "C" ASC
                       ) AS rank
                FROM eligible m
            ),
            markers AS (
                SELECT d.day, max(d.as_of) AS as_of
                FROM price_daily d
                CROSS JOIN params x
                WHERE d.source = x.source AND NOT d.is_filled AND d.day BETWEEN x.from_day AND x.to_day
                GROUP BY d.day
            )
            INSERT INTO price_movers (day, source, direction, rank, variant_key, card_id, series_epoch, close,
                                      previous_close, previous_day, change, change_pct, as_of)
            SELECT r.day, r.source, r.direction, r.rank, r.variant_key, v.card_id, r.series_epoch, r.close,
                   r.previous_close, r.previous_day, r.change, r.change_pct, k.as_of
            FROM ranked r
            JOIN card_variants v ON v.variant_key = r.variant_key
            JOIN markers k ON k.day = r.day
            CROSS JOIN params x
            WHERE r.rank <= x.lim
            SQL, [
            $source, $from, $to,
            (int) config('pricing.fill_cap_days'),
            (string) config('pricing.movers.min_previous_close'),
            PricingConfig::moversLimit(),
            PricingConfig::MOVERS_MIN_CHANGE_PCT, self::MAX_CHANGE_PCT, PricingConfig::MOVERS_MIN_CHANGE,
            PricingConfig::MOVERS_ACTIVITY_CLOSES, PricingConfig::MOVERS_ACTIVITY_MIN_DISTINCT,
            PricingConfig::MOVERS_LISTING_LOW_RATIO, PricingConfig::MOVERS_LISTING_MID_RATIO,
        ]);
    }

    /**
     * The newest movers day of $source and its stored snapshot marker, as YYYY-MM-DD and an ISO-8601 UTC instant, or
     * nulls before any movers exist: one backward read of the primary key.
     *
     * @return array{day: string|null, as_of: string|null}
     */
    public static function newest(ConnectionInterface $db, string $source): array
    {
        $row = $db->selectOne(<<<'SQL'
            SELECT to_char(day, 'YYYY-MM-DD') AS day,
                   to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM price_movers
            WHERE source = ?
            ORDER BY day DESC
            LIMIT 1
            SQL, [$source]);

        return [
            'day' => $row instanceof stdClass && is_string($row->day) ? $row->day : null,
            'as_of' => $row instanceof stdClass && is_string($row->as_of) ? $row->as_of : null,
        ];
    }

    /**
     * The ranked rows of $source on $day in $direction, at most $limit, with each variant's finish, edition and
     * treatment.
     *
     * @return Collection<int, PriceMover>
     */
    public static function page(string $source, string $day, string $direction, int $limit): Collection
    {
        return PriceMover::query()
            ->from('price_movers as m')
            ->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')
            ->where('m.source', $source)
            ->where('m.day', $day)
            ->where('m.direction', $direction)
            ->orderBy('m.rank')
            ->limit($limit)
            ->get(['m.rank', 'm.card_id', 'm.variant_key', 'v.finish', 'v.edition', 'v.treatment', 'm.close', 'm.previous_close',
                'm.previous_day', 'm.change', 'm.change_pct', 'm.series_epoch']);
    }
}
