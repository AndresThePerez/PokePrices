<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Models\IngestRun;
use Carbon\CarbonImmutable;

/**
 * The freshness metric of the source's prices (plan P2-i1 T10, D8; ADR draft 0001 "schedule and freshness").
 *
 * - as_of is the newest marker among the source's ingest runs with status ok (any mode, replay included); a
 *   superseded, failed, refused, running or skipped_unchanged run never moves it. day is that run's day, the marker's
 *   UTC date (D1).
 * - age_hours is (measured_at - as_of) in hours, shown with one decimal, rounded half up from the unrounded age.
 * - within_slo is judged on the unrounded age: unrounded < freshness.slo_hours (26). 25.96 h is within the SLO and
 *   shows 26.0; 26.00 h is not. The display never decides the SLO.
 * - measured_at is the server clock and the only clock value; it never enters a price-shaped field.
 * - last_run is the source's newest run of any status, or null when there is none.
 *
 * With no ok run, as_of, day and age_hours are null and within_slo is false. The age is computed in integer
 * microseconds, so no float rounding can move a value across the SLO or a display boundary.
 */
final class Freshness
{
    private const int MICROS_PER_TENTH_HOUR = 360_000_000;

    private const int MICROS_PER_HOUR = 3_600_000_000;

    /**
     * @return array{source: string, feed: string, as_of: string|null, day: string|null, age_hours: float|null, slo_hours: int, within_slo: bool, measured_at: string, last_run: array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null}
     */
    public static function measure(): array
    {
        $source = (string) config('pricing.source');
        $slo = (int) config('pricing.freshness.slo_hours');
        $now = CarbonImmutable::now()->utc();

        /** @var IngestRun|null $newestOk */
        $newestOk = IngestRun::query()
            ->where('source', $source)
            ->where('status', 'ok')
            ->whereNotNull('marker')
            ->orderByDesc('marker')
            ->orderByDesc('id')
            ->first();

        /** @var IngestRun|null $lastRun */
        $lastRun = IngestRun::query()
            ->where('source', $source)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $asOf = $newestOk?->marker?->utc();
        $ageHours = null;
        $within = false;
        if ($asOf !== null) {
            $ageMicros = ($now->getTimestamp() - $asOf->getTimestamp()) * 1_000_000 + ($now->micro - $asOf->micro);
            // A float even on a whole hour (26.0, never the int 26), so JSON keeps its one decimal.
            $ageHours = self::floorDiv($ageMicros + intdiv(self::MICROS_PER_TENTH_HOUR, 2), self::MICROS_PER_TENTH_HOUR) / 10.0;
            $within = $ageMicros < $slo * self::MICROS_PER_HOUR;
        }

        return [
            'source' => $source,
            'feed' => (string) config('pricing.feed'),
            'as_of' => $asOf?->format('Y-m-d\TH:i:s\Z'),
            'day' => $newestOk?->day->format('Y-m-d'),
            'age_hours' => $ageHours,
            'slo_hours' => $slo,
            'within_slo' => $within,
            'measured_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'last_run' => $lastRun === null ? null : [
                'id' => $lastRun->id,
                'status' => $lastRun->status,
                'mode' => $lastRun->mode,
                'day' => $lastRun->day->format('Y-m-d'),
                'finished_at' => $lastRun->finished_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
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
