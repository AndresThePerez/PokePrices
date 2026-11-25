<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Jobs\IngestPending;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What is wrong with the price job at this instant, read from ingest_runs and the clock and nothing else: no folder,
 * no cache, no write. The same rows and the same clock always give the same list.
 *
 * Each problem is ['kind', 'day', 'key', 'sentence']:
 *
 * - late: the newest ok marker is past the freshness limit (Freshness::age, the rule of the freshness answer), or no
 *   price day was ever ingested. day is the newest day with an ok run, or null when there is none.
 * - missing_day: a calendar day between the first and the newest day with an ok run that has no ok run itself
 *   (Freshness::collected) and is at most pricing.freshness.missing_day_warning_days calendar days old
 *   (Freshness::warningMissingDays). One problem per day, whatever the reason; a refused or stuck day in that span is
 *   listed under both kinds. A day that ages past the limit leaves the list for good, so a report of it is cleared
 *   once; the late, refused and stuck kinds have no age limit.
 * - refused: a day whose newest run is a refusal (`skipped_unchanged` runs are not runs here, as in the pending scan).
 *   A later ok run of the day ends it.
 * - stuck: a run left `running` for STUCK_AFTER_MINUTES or longer (the pending scan resumes a stalled run of the
 *   folder's marker within that time, so an older one is a run nothing resumes and no start of its day gets past), or
 *   a day on hold: its newest runs are two or more consecutive failures of one marker and the hold the pending scan
 *   keeps (IngestPending::FAILED_TWICE_HOLD_HOURS, FAILED_REPEATEDLY_HOLD_HOURS) has not ended.
 *
 * key names one problem across readings: the same key is the same problem, a new cause is a new key (`late:<day>`,
 * `missing_day:<day>`, `refused:<day>:<marker>:<hash of the reason>`, `stuck:<day>:run:<run id>`,
 * `stuck:<day>:hold:<newest failed run id>`). sentence is one short plain line for a person; it carries the day, never
 * a clock time or a duration.
 *
 * Order: late first, then by day, and within a day missing_day, refused, stuck. Two statements, whatever the number of
 * runs.
 */
final class PriceJobProblems
{
    public const string LATE = 'late';

    public const string MISSING_DAY = 'missing_day';

    public const string REFUSED = 'refused';

    public const string STUCK = 'stuck';

    /** The stall limit of the pending scan plus the hour until its next pass. */
    public const int STUCK_AFTER_MINUTES = IngestPending::STALLED_AFTER_MINUTES + 60;

    /** The most characters of a refusal's reason a sentence repeats. */
    private const int REASON_LENGTH = 200;

    /**
     * @return list<array{kind: 'late'|'missing_day'|'refused'|'stuck', day: string|null, key: string, sentence: string}>
     */
    public static function all(?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $now = CarbonImmutable::now()->utc();
        $collected = Freshness::collected($db);

        $late = [];
        if ($collected['as_of'] === null) {
            $late[] = self::problem(self::LATE, null, 'late:none', 'No price day has been ingested yet.');
        } elseif (! Freshness::age($collected['as_of'], $now)['within_slo']) {
            $day = (string) $collected['day'];
            $late[] = self::problem(self::LATE, $day, "late:{$day}", sprintf(
                'The newest prices are from %s, which is past the limit of %d hours.', $day, Freshness::sloHours(),
            ));
        }

        $byDay = [];
        foreach (Freshness::warningMissingDays($collected['missing_days'], $now) as $day) {
            $byDay[] = self::problem(self::MISSING_DAY, $day, "missing_day:{$day}", "No price run finished for {$day}.");
        }
        foreach (self::unfinishedDays($db) as $state) {
            $day = $state['day'];
            if ($state['status'] === 'refused') {
                $reason = $state['error'] ?? 'no reason recorded';
                $marker = $state['marker']?->format('Y-m-d\TH:i:s\Z') ?? 'none';
                $shown = mb_strlen($reason) > self::REASON_LENGTH ? mb_substr($reason, 0, self::REASON_LENGTH).'…' : $reason;
                $byDay[] = self::problem(self::REFUSED, $day, "refused:{$day}:{$marker}:".substr(sha1($reason), 0, 12),
                    "The folder of {$day} was refused: {$shown}.");
            }
            if ($state['running_id'] !== null && $state['running_started_at'] !== null
                && $state['running_started_at']->lessThanOrEqualTo($now->subMinutes(self::STUCK_AFTER_MINUTES))) {
                $byDay[] = self::problem(self::STUCK, $day, "stuck:{$day}:run:{$state['running_id']}",
                    "Run {$state['running_id']} of {$day} has not finished and blocks its day.");
            }
            if ($state['status'] === 'failed' && $state['marker'] !== null && $state['failures'] >= 2 && $state['failed_at'] !== null) {
                $hours = $state['failures'] === 2 ? IngestPending::FAILED_TWICE_HOLD_HOURS : IngestPending::FAILED_REPEATEDLY_HOLD_HOURS;
                if ($now->lessThan($state['failed_at']->addHours($hours))) {
                    $times = $state['failures'] === 2 ? 'twice' : "{$state['failures']} times";
                    $byDay[] = self::problem(self::STUCK, $day, "stuck:{$day}:hold:{$state['id']}",
                        "{$day} failed {$times} in a row and is on hold.");
                }
            }
        }

        $rank = [self::MISSING_DAY => 0, self::REFUSED => 1, self::STUCK => 2];
        usort($byDay, fn (array $a, array $b): int => [$a['day'], $rank[$a['kind']] ?? 3, $a['key']] <=> [$b['day'], $rank[$b['kind']] ?? 3, $b['key']]);

        return [...$late, ...$byDay];
    }

    /**
     * The days without an ok run between the first and the newest day that has one, oldest first and uncapped.
     *
     * @return list<string>
     */
    public static function missingDays(?ConnectionInterface $db = null): array
    {
        return Freshness::collected($db)['missing_days'];
    }

    /**
     * Per day whose newest run is refused or failed, or that has a running run (one statement): the newest run, the
     * day's oldest running run, and the consecutive failed runs of the newest run's marker, newest first, with the
     * newest one's end (its start when it has none). `skipped_unchanged` runs are ignored, as in the pending scan.
     *
     * @return list<array{day: string, id: int, status: string, marker: CarbonImmutable|null, error: string|null, running_id: int|null, running_started_at: CarbonImmutable|null, failures: int, failed_at: CarbonImmutable|null}>
     */
    private static function unfinishedDays(ConnectionInterface $db): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT to_char(day, 'YYYY-MM-DD') AS day,
                (array_agg(id ORDER BY id DESC))[1] AS id,
                (array_agg(status ORDER BY id DESC))[1] AS status,
                (array_agg(marker ORDER BY id DESC))[1] AS marker,
                (array_agg(error ORDER BY id DESC))[1] AS error,
                (array_agg(id ORDER BY id) FILTER (WHERE status = 'running'))[1] AS running_id,
                (array_agg(started_at ORDER BY id) FILTER (WHERE status = 'running'))[1] AS running_started_at,
                count(*) FILTER (WHERE status = 'failed' AND breaks = 0 AND newest_marker) AS failures,
                (array_agg(COALESCE(finished_at, started_at) ORDER BY id DESC)
                    FILTER (WHERE status = 'failed' AND breaks = 0 AND newest_marker))[1] AS failed_at
            FROM (
                SELECT id, day, marker, status, error, started_at, finished_at,
                    count(*) FILTER (WHERE status <> 'failed') OVER (PARTITION BY day, marker ORDER BY id DESC) AS breaks,
                    marker IS NOT DISTINCT FROM first_value(marker) OVER (PARTITION BY day ORDER BY id DESC) AS newest_marker
                FROM ingest_runs
                WHERE source = ? AND status <> 'skipped_unchanged'
            ) runs
            GROUP BY day
            HAVING (array_agg(status ORDER BY id DESC))[1] IN ('refused', 'failed') OR bool_or(status = 'running')
            ORDER BY day
            SQL, [PricingConfig::source()], false);

        $days = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_string($row->day) || ! is_string($row->status) || ! is_numeric($row->id)) {
                continue;
            }
            $days[] = [
                'day' => $row->day,
                'id' => (int) $row->id,
                'status' => $row->status,
                'marker' => is_string($row->marker) ? CarbonImmutable::parse($row->marker)->utc() : null,
                'error' => is_string($row->error) ? $row->error : null,
                'running_id' => is_numeric($row->running_id) ? (int) $row->running_id : null,
                'running_started_at' => is_string($row->running_started_at) ? CarbonImmutable::parse($row->running_started_at)->utc() : null,
                'failures' => is_numeric($row->failures) ? (int) $row->failures : 0,
                'failed_at' => is_string($row->failed_at) ? CarbonImmutable::parse($row->failed_at)->utc() : null,
            ];
        }

        return $days;
    }

    /**
     * @param  'late'|'missing_day'|'refused'|'stuck'  $kind
     * @return array{kind: 'late'|'missing_day'|'refused'|'stuck', day: string|null, key: string, sentence: string}
     */
    private static function problem(string $kind, ?string $day, string $key, string $sentence): array
    {
        return ['kind' => $kind, 'day' => $day, 'key' => $key, 'sentence' => $sentence];
    }
}
