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
 * - every other day gets a StartDayIngest, whose start re-validates the whole folder (D1, including the manifest
 *   cross-check) before anything is written.
 *
 * It never dispatches the same marker twice in one pass. The lock is only probed for the plan: it is released before
 * anything is dispatched, and each start takes it again for itself.
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

        foreach ((new Replay($db, $root))->collectedDays() as $day) {
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
                $runs = $db->table('ingest_runs')->where('source', $source)->where('marker', $marker)
                    ->whereIn('status', ['ok', 'running'])->orderByDesc('id')->get(['status', 'started_at']);
                if ($runs->contains('status', 'ok')) {
                    continue;
                }
                $running = $runs->first();
                if ($running instanceof stdClass) {
                    if (CarbonImmutable::parse((string) $running->started_at)->greaterThan($now->subMinutes(self::STALLED_AFTER_MINUTES))) {
                        continue;
                    }
                    $resume[] = $day;

                    continue;
                }
            }

            if ($this->refusedRecently($db, $source, $day, $marker, $reader, $now)) {
                continue;
            }
            $start[] = $day;
        }

        return [$start, $resume];
    }

    /** Whether the day's newest run is a refusal of the last 24 h for the same marker and the same error as now. */
    private function refusedRecently(ConnectionInterface $db, string $source, string $day, ?string $marker, PriceSnapshotReader $reader, CarbonImmutable $now): bool
    {
        $newest = $db->table('ingest_runs')->where('source', $source)->where('day', $day)->where('status', '<>', 'skipped_unchanged')
            ->orderByDesc('id')->first(['status', 'marker', 'error', 'started_at']);
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
