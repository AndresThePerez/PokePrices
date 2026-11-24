<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Models\IngestRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The freshness metric of the source's prices (ADR draft 0001 "schedule and freshness").
 *
 * - as_of is the newest marker among the source's ingest runs with status ok (any mode, replay included); a
 *   superseded, failed, refused, running or skipped_unchanged run never moves it. day is that run's day, the marker's
 *   UTC date.
 * - age_hours is (measured_at - as_of) in hours, shown with one decimal, rounded half up from the unrounded age.
 * - within_slo is judged on the unrounded age: unrounded < freshness.slo_hours (26). 25.96 h is within the SLO and
 *   shows 26.0; 26.00 h is not. The display never decides the SLO.
 * - measured_at is the server clock and the only clock value; it never enters a price-shaped field.
 * - last_run is the source's newest run of any status, or null when there is none.
 * - missing_days are the calendar days between the source's first and newest day with an ok run that have no ok run
 *   themselves (never collected, refused, failed or blocked, and filled from an earlier close meanwhile), as ISO dates,
 *   oldest first; the answer carries the newest MISSING_DAYS_CAP of them and missing_days_count counts them all. A
 *   missing day does not change within_slo.
 *
 * With no ok run, as_of, day and age_hours are null, within_slo is false and no day is missing. The age is computed in
 * integer microseconds, so no float rounding can move a value across the SLO or a display boundary.
 */
final class Freshness
{
    /** The most missing days the answer lists (the newest ones); missing_days_count is never capped. */
    public const int MISSING_DAYS_CAP = 30;

    private const int MICROS_PER_TENTH_HOUR = 360_000_000;

    private const int MICROS_PER_HOUR = 3_600_000_000;

    /**
     * @return array{source: string, feed: string, as_of: string|null, day: string|null, age_hours: float|null, slo_hours: int, within_slo: bool, measured_at: string, last_run: array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null, missing_days: list<string>, missing_days_count: int}
     */
    public static function measure(): array
    {
        $source = PricingConfig::source();
        $now = CarbonImmutable::now()->utc();
        $collected = self::collected();

        /** @var IngestRun|null $lastRun */
        $lastRun = IngestRun::query()
            ->where('source', $source)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $asOf = $collected['as_of'];
        $age = $asOf === null ? null : self::age($asOf, $now);

        return [
            'source' => $source,
            'feed' => PricingConfig::feed(),
            'as_of' => $asOf?->format('Y-m-d\TH:i:s\Z'),
            'day' => $collected['day'],
            'age_hours' => $age === null ? null : $age['age_hours'],
            'slo_hours' => self::sloHours(),
            'within_slo' => $age !== null && $age['within_slo'],
            'measured_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'last_run' => $lastRun === null ? null : [
                'id' => $lastRun->id,
                'status' => $lastRun->status,
                'mode' => $lastRun->mode,
                'day' => $lastRun->day->format('Y-m-d'),
                'finished_at' => $lastRun->finished_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'missing_days' => array_slice($collected['missing_days'], -self::MISSING_DAYS_CAP),
            'missing_days_count' => count($collected['missing_days']),
        ];
    }

    /**
     * What the source's ok runs cover, in one statement: the newest ok marker (as_of) with its run's day, and every
     * calendar day between the first and the newest day with an ok run that has no ok run, oldest first and uncapped.
     *
     * @return array{as_of: CarbonImmutable|null, day: string|null, missing_days: list<string>}
     */
    public static function collected(?ConnectionInterface $db = null): array
    {
        $source = PricingConfig::source();
        $rows = ($db ?? DB::connection())->select(<<<'SQL'
            WITH ok AS (
                SELECT id, day, marker FROM ingest_runs WHERE source = ? AND status = 'ok'
            ),
            span AS (
                SELECT min(day) AS first_day, max(day) AS last_day FROM ok
            ),
            newest AS (
                SELECT day, marker FROM ok WHERE marker IS NOT NULL ORDER BY marker DESC, id DESC LIMIT 1
            ),
            missing AS (
                SELECT g.day::date AS day
                FROM span s
                CROSS JOIN LATERAL generate_series(s.first_day::timestamp, s.last_day::timestamp, interval '1 day') AS g(day)
                WHERE NOT EXISTS (SELECT 1 FROM ok WHERE ok.day = g.day::date)
            )
            SELECT to_char(n.day, 'YYYY-MM-DD') AS newest_day, n.marker AS newest_marker,
                   to_char(m.day, 'YYYY-MM-DD') AS missing_day
            FROM (SELECT 1) AS one
            LEFT JOIN newest n ON true
            LEFT JOIN missing m ON true
            ORDER BY m.day
            SQL, [$source], false);

        $collected = ['as_of' => null, 'day' => null, 'missing_days' => []];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            if (is_string($row->newest_marker) && is_string($row->newest_day)) {
                $collected['as_of'] = CarbonImmutable::parse($row->newest_marker)->utc();
                $collected['day'] = $row->newest_day;
            }
            if (is_string($row->missing_day)) {
                $collected['missing_days'][] = $row->missing_day;
            }
        }

        return $collected;
    }

    /**
     * The age of a marker at an instant and the SLO verdict on it: the one place the freshness limit is judged.
     * age_hours is a float even on a whole hour (26.0, never the int 26), so JSON keeps its one decimal.
     *
     * @return array{age_hours: float, within_slo: bool}
     */
    public static function age(CarbonInterface $asOf, CarbonInterface $now): array
    {
        $ageMicros = ($now->getTimestamp() - $asOf->getTimestamp()) * 1_000_000 + ($now->micro - $asOf->micro);

        return [
            'age_hours' => self::floorDiv($ageMicros + intdiv(self::MICROS_PER_TENTH_HOUR, 2), self::MICROS_PER_TENTH_HOUR) / 10.0,
            'within_slo' => $ageMicros < self::sloHours() * self::MICROS_PER_HOUR,
        ];
    }

    /** The freshness limit in hours (config pricing.freshness.slo_hours). */
    public static function sloHours(): int
    {
        return (int) config('pricing.freshness.slo_hours');
    }

    /** The displayed age: one decimal, "26.0" for 26.0 (the command and log line print the same value as the API). */
    public static function display(float $ageHours): string
    {
        return sprintf('%.1f', $ageHours);
    }

    /** Integer division rounded toward negative infinity (a clock behind the marker gives a negative age). */
    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return ($dividend % $divisor !== 0 && $dividend < 0) ? $quotient - 1 : $quotient;
    }
}
