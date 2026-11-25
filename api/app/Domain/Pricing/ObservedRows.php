<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Which price_points rows are observations. A queued run commits the rows of each finished chunk on its own, so a run
 * that failed, or one a replay superseded, can leave rows behind. A row counts only when an `ok` run backs its day and
 * marker, or when it lies on the day of the current run: the run being finalized for DailyRollup, the source's
 * `running` run (at most one per day) for a reader outside an ingest (Listings).
 *
 * The condition is plain SQL so both statements carry the same text; it binds the source once, where it appears.
 */
final class ObservedRows
{
    /** The current run's day outside an ingest: the day of any `running` run of the source; binds the source. */
    public const string RUNNING_DAY = "ANY (SELECT r.day FROM ingest_runs r WHERE r.source = ? AND r.status = 'running')";

    /**
     * The condition on the price_points row aliased $alias; $currentDay is compared with `=` to the row's day.
     * Binds one parameter, the source, after any parameter of $currentDay.
     */
    public static function condition(string $alias, string $currentDay): string
    {
        return "({$alias}.day = {$currentDay} OR ({$alias}.day, {$alias}.observed_at) IN ("
            ."SELECT r.day, r.marker FROM ingest_runs r WHERE r.source = ? AND r.status = 'ok' AND r.marker IS NOT NULL))";
    }
}
