<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Jobs;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\DayRefused;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PriceSnapshotReader;
use App\Domain\Pricing\Replay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * The hourly pending scan (plan P2-i1 T08 item 4; scheduled at :40, R177(5)). It is a no-op unless a collected day
 * needs work. It skips its whole pass while another session holds the `pricing:ingest` lock (a replay or a start),
 * then walks the day folders holding SUCCESS, oldest first:
 *
 * - a day whose marker has an `ok` run is done (a `superseded` run does not count, so the days a crashed replay wiped
 *   are re-ingested; review F6);
 * - a day whose marker has a `running` run younger than 2 h is left alone; one older than 2 h is a stalled run and is
 *   resumed (only its chunks that are not done are re-dispatched);
 * - a day whose newest run is `refused` within the last 24 h, for the same marker and the same error, is skipped, so a
 *   permanently bad folder writes one refused row a day; a changed folder (another error or marker) is retried;
 * - a marker whose newest runs are k consecutive `failed` runs (newest first, up to the first run that is not `failed`;
 *   `skipped_unchanged` runs are ignored) is retried at the next scan when k is 1, held 6 h after the newest failed
 *   run ended when k is 2, and 24 h when k is 3 or more; at k >= 3 it logs `ingest_failed_repeatedly` once per newest
 *   failed run (plan Q1-i3 T05). `--resume` and `--queued` by hand do not consult the hold;
 * - every other day gets a StartDayIngest, whose start re-validates the whole folder (D1, including the manifest
 *   cross-check) before anything is written.
 *
 * It never dispatches the same marker twice in one pass. The lock is only probed for the plan: it is released before
 * anything is dispatched, and each start takes it again for itself. The plan reads ingest_runs with two set-based
 * statements (one per marker, one per day) whatever the number of collected days.
 *
 * ShouldBeUnique (plan Q1-i1 T02, review b b2): at most one scan is queued or running, so scans queued behind a busy
 * worker never plan the same day twice; uniqueFor (50 min) stays below the hourly schedule. Timeout 80 s, below the
 * redis queue's retry_after (90 s); three tries, as the worker's --tries gave it before.
 */
#[Tries(3)]
#[Timeout(80)]
final class IngestPending implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3000;

    public const int STALLED_AFTER_MINUTES = 120;

    public const int REFUSED_QUIET_HOURS = 24;

    /** The hold after two consecutive failed runs of a marker, from the newest one's finished_at. */
    public const int FAILED_TWICE_HOLD_HOURS = 6;

    /** The hold after three or more consecutive failed runs of a marker, from the newest one's finished_at. */
    public const int FAILED_REPEATEDLY_HOLD_HOURS = 24;

    public function __construct(
        public readonly ?string $snapshots = null,
        public readonly string $queueConnection = StartDayIngest::CONNECTION,
        public readonly ?string $database = null,
    ) {}

    public function handle(): void
    {
        $this->scan();
    }

    /**
     * @return array{skipped: bool, dispatched: list<string>, resumed: list<int>}
     */
    public function scan(): array
    {
        $db = DB::connection($this->database);
        $root = $this->snapshotsRoot();
        if (! IngestLock::tryAcquire($db)) {
            Log::info(IngestLock::HELD_MESSAGE, ['job' => 'IngestPending']);

            return ['skipped' => true, 'dispatched' => [], 'resumed' => []];
        }
        try {
            [$start, $resume] = $this->plan($db, $root);
        } finally {
            IngestLock::release($db);
        }

        foreach ($start as $day) {
            try {
                Bus::dispatch(new StartDayIngest($day, $root, false, $this->queueConnection, $this->database));
            } catch (Throwable $e) {
                // Only an inline (sync queue) start can throw here; the other days still get their turn.
                report($e);
            }
        }
        $resumed = [];
        foreach ($resume as $day) {
            $result = (new DayIngest($db, $root))->resume($day, $this->queueConnection, $this->database);
            if ($result['status'] === 'resumed' && $result['run_id'] !== null) {
                $resumed[] = $result['run_id'];
            }
        }

        return ['skipped' => false, 'dispatched' => $start, 'resumed' => $resumed];
    }

    /**
     * The days to start and the days whose stalled run to resume, oldest first.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function plan(ConnectionInterface $db, string $root): array
    {
        $source = (string) config('pricing.source');
        $now = CarbonImmutable::now('UTC');
        [$start, $resume, $seen] = [[], [], []];

        $days = (new Replay($db, $root))->collectedDays();
        [$markers, $newest] = $days === [] ? [[], []] : [$this->markerStates($db, $source, $days), $this->newestRuns($db, $source, $days)];

        foreach ($days as $day) {
            $reader = PriceSnapshotReader::at($root, $day);
            try {
                $marker = $reader->marker()->format('Y-m-d H:i:sP');
            } catch (DayRefused) {
                $marker = null;
            }

            // A marker on its own folder's date: the run checks key on (source, marker).
            if ($marker !== null && str_starts_with($marker, $day.' ')) {
                if (isset($seen[$marker])) {
                    continue;
                }
                $seen[$marker] = true;
                $state = $markers[$marker] ?? null;
                if ($state !== null && $state['ok']) {
                    continue;
                }
                if ($state !== null && $state['running_started_at'] !== null) {
                    if ($state['running_started_at']->greaterThan($now->subMinutes(self::STALLED_AFTER_MINUTES))) {
                        continue;
                    }
                    $resume[] = $day;

                    continue;
                }
                if ($state !== null && $this->heldAfterFailures($day, $marker, $state, $now)) {
                    continue;
                }
            }

            if ($this->refusedRecently($newest[$day] ?? null, $marker, $reader, $now)) {
                continue;
            }
            $start[] = $day;
        }

        return [$start, $resume];
    }

    /**
     * Per marker of the collected days (one statement): whether an `ok` run exists, the newest `running` run's start,
     * and the consecutive `failed` runs, newest first, up to the first run that is not `failed` (`skipped_unchanged`
     * ignored), with the newest failed run's id, error and end (its start when it has none).
     *
     * @param  list<string>  $days
     * @return array<string, array{ok: bool, running_started_at: CarbonImmutable|null, failures: int, failed_id: int|null, failed_error: string|null, failed_at: CarbonImmutable|null}>
     */
    private function markerStates(ConnectionInterface $db, string $source, array $days): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT marker,
                bool_or(status = 'ok') AS ok,
                (array_agg(started_at ORDER BY id DESC) FILTER (WHERE status = 'running'))[1] AS running_started_at,
                count(*) FILTER (WHERE status = 'failed' AND breaks = 0) AS failures,
                (array_agg(id ORDER BY id DESC) FILTER (WHERE status = 'failed' AND breaks = 0))[1] AS failed_id,
                (array_agg(error ORDER BY id DESC) FILTER (WHERE status = 'failed' AND breaks = 0))[1] AS failed_error,
                (array_agg(COALESCE(finished_at, started_at) ORDER BY id DESC) FILTER (WHERE status = 'failed' AND breaks = 0))[1] AS failed_at
            FROM (
                SELECT id, marker, status, started_at, finished_at, error,
                    count(*) FILTER (WHERE status <> 'failed') OVER (PARTITION BY marker ORDER BY id DESC) AS breaks
                FROM ingest_runs
                WHERE source = ? AND day = ANY(?::date[]) AND marker IS NOT NULL AND status <> 'skipped_unchanged'
            ) runs
            GROUP BY marker
            SQL, [$source, '{'.implode(',', $days).'}'], false);

        $states = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $states[CarbonImmutable::parse((string) $row->marker)->utc()->format('Y-m-d H:i:sP')] = [
                'ok' => (bool) $row->ok,
                'running_started_at' => $row->running_started_at === null ? null : CarbonImmutable::parse((string) $row->running_started_at),
                'failures' => (int) $row->failures,
                'failed_id' => $row->failed_id === null ? null : (int) $row->failed_id,
                'failed_error' => $row->failed_error === null ? null : (string) $row->failed_error,
                'failed_at' => $row->failed_at === null ? null : CarbonImmutable::parse((string) $row->failed_at),
            ];
        }

        return $states;
    }

    /**
     * Per collected day (one statement): its newest run that is not `skipped_unchanged`, for the refused rule.
     *
     * @param  list<string>  $days
     * @return array<string, stdClass>
     */
    private function newestRuns(ConnectionInterface $db, string $source, array $days): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT DISTINCT ON (day) to_char(day, 'YYYY-MM-DD') AS day, status, marker, error, started_at
            FROM ingest_runs
            WHERE source = ? AND day = ANY(?::date[]) AND status <> 'skipped_unchanged'
            ORDER BY day, id DESC
            SQL, [$source, '{'.implode(',', $days).'}'], false);

        $newest = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $newest[(string) $row->day] = $row;
            }
        }

        return $newest;
    }

    /**
     * Whether the marker's consecutive failed runs hold it: k = 2 until 6 h after the newest one ended, k >= 3 until
     * 24 h after. At k >= 3 the first scan that sees the newest failed run logs `ingest_failed_repeatedly` (the cache key
     * makes it once per run; a cache store that throws only means the warning may repeat).
     *
     * @param  array{ok: bool, running_started_at: CarbonImmutable|null, failures: int, failed_id: int|null, failed_error: string|null, failed_at: CarbonImmutable|null}  $state
     */
    private function heldAfterFailures(string $day, string $marker, array $state, CarbonImmutable $now): bool
    {
        if ($state['failures'] < 2 || $state['failed_at'] === null) {
            return false;
        }
        $heldUntil = $state['failed_at']->utc()->addHours(
            $state['failures'] === 2 ? self::FAILED_TWICE_HOLD_HOURS : self::FAILED_REPEATEDLY_HOLD_HOURS,
        );

        if ($state['failures'] >= 3) {
            try {
                $first = Cache::add('pricing:ingest_failed_repeatedly:'.$state['failed_id'], true, now()->addDays(2));
            } catch (Throwable) {
                $first = true;
            }
            if ($first) {
                Log::warning('ingest_failed_repeatedly', [
                    'day' => $day,
                    'marker' => $marker,
                    'failures' => $state['failures'],
                    'error' => $state['failed_error'],
                    'held_until' => $heldUntil->format('Y-m-d\TH:i:s\Z'),
                ]);
            }
        }

        return $now->lessThan($heldUntil);
    }

    /** Whether the day's newest run is a refusal of the last 24 h for the same marker and the same error as now. */
    private function refusedRecently(?stdClass $newest, ?string $marker, PriceSnapshotReader $reader, CarbonImmutable $now): bool
    {
        if (! $newest instanceof stdClass || $newest->status !== 'refused'
            || CarbonImmutable::parse((string) $newest->started_at)->lessThanOrEqualTo($now->subHours(self::REFUSED_QUIET_HOURS))) {
            return false;
        }
        $sameMarker = $newest->marker === null || $marker === null
            ? $newest->marker === $marker
            : CarbonImmutable::parse((string) $newest->marker)->equalTo(CarbonImmutable::parse($marker));
        if (! $sameMarker) {
            return false;
        }

        try {
            $reader->validate();
            $error = null;
        } catch (DayRefused $e) {
            $error = $e->error;
        }

        return $error === $newest->error;
    }

    private function snapshotsRoot(): string
    {
        $configured = config('services.tcgcsv.snapshots');

        return $this->snapshots ?? (is_string($configured) ? $configured : '');
    }
}
