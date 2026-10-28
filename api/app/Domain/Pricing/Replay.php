<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The deterministic replay of every collected day (plan P2-i1 T07 item 3, D11, D12, R180; review F5, F6).
 *
 * - It holds the `pricing:ingest` advisory lock for its whole run and stops before anything else when another session
 *   holds it.
 * - Preflight, before any wipe: it lists the days once (every <root>/<YYYY-MM-DD>/ with SUCCESS, or the days named),
 *   validates each with the ingest's own start checks (SUCCESS, marker, marker/folder date, manifest, every group file
 *   present and decodable, money per D5) and pins that list and each day's marker for all three passes. An empty list,
 *   a refused day or a dispatched price_events row stops it with nothing written.
 * - Pass 1 and pass 2 each wipe the derived tables, then ingest every pinned day in the chosen order (mode `replay`);
 *   pass 3 ingests again without wiping. Each pass ends with a PriceDigest and, beside it, the movers check of
 *   price_movers (T12; outside the digest by R180b, R181(1)).
 * - The wipe truncates price_points, price_daily, price_latest and price_movers and, in the same transaction, marks
 *   the `ok` and `running` runs of the source `superseded` (D12). That covers the runs of every pinned day; with a --days subset it
 *   also covers the runs of the other days, whose rows the truncate removes too, so the pending scan re-ingests them.
 *   Chunks read their run FOR SHARE, so a chunk either commits before the wipe or finds its run superseded.
 * - It never writes, updates, deletes or truncates price_events (R180a), never writes the map tables, and only reads
 *   the raw snapshot files.
 *
 * Identical means the three digests and the three movers checks are equal, all taken in the same order, at a fixed
 * (day set, map build, code sha) (R180c): the replay normalizes with the current map build and epochs.
 */
final class Replay
{
    /** The derived tables a fresh replay truncates; never price_events (R180a). */
    public const array WIPED_TABLES = ['price_points', 'price_daily', 'price_latest', 'price_movers', 'movement_volume'];

    public const string NO_DAYS = 'no collected days';

    public function __construct(private readonly ConnectionInterface $db, private readonly string $snapshotsRoot) {}

    /**
     * Every collected day under the root: a YYYY-MM-DD folder holding SUCCESS, ascending.
     *
     * @return list<string>
     */
    public function collectedDays(): array
    {
        $root = rtrim($this->snapshotsRoot, '/');
        $entries = is_dir($root) ? scandir($root) : false;
        $days = [];
        foreach ($entries === false ? [] : $entries as $entry) {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $entry) === 1 && is_file("{$root}/{$entry}/SUCCESS")) {
                $days[] = $entry;
            }
        }
        sort($days, SORT_STRING);

        return $days;
    }

    /**
     * Runs the replay: lock, preflight, three passes.
     *
     * @param  list<string>|null  $days  the days to replay; null for every collected day
     * @param  'asc'|'desc'  $order
     * @return array{status: 'ok'|'locked'|'refused'|'failed', error: string|null, days: list<string>, map_build_id: int|null, passes: list<array{pass: int, digests: array<string, array{rows: int, sha256: string}>, movers_check: array{rows: int, sha256: string}, seconds: float}>, identical: bool}
     */
    public function run(?array $days, string $order): array
    {
        $result = ['status' => 'refused', 'error' => null, 'days' => [], 'map_build_id' => null, 'passes' => [], 'identical' => false];
        if (! IngestLock::tryAcquire($this->db)) {
            return ['status' => 'locked', 'error' => IngestLock::HELD_MESSAGE] + $result;
        }

        try {
            $pinned = $this->preflight($days ?? $this->collectedDays());
            if (is_string($pinned)) {
                return ['error' => $pinned] + $result;
            }
            $result['days'] = array_keys($pinned);
            $sequence = $order === 'desc' ? array_reverse($result['days']) : $result['days'];

            $ingest = new DayIngest($this->db, $this->snapshotsRoot);
            $full = [];
            foreach ([1, 2, 3] as $pass) {
                $started = hrtime(true);
                if ($pass < 3) {
                    $this->wipe();
                }
                foreach ($sequence as $day) {
                    $run = $ingest->sync($day, true, 'replay');
                    if ($run['status'] !== 'ok') {
                        return ['status' => 'failed', 'error' => "pass {$pass}, day {$day}: {$run['status']}: {$run['error']}"] + $result;
                    }
                    if ($run['marker'] === null || ! $run['marker']->equalTo($pinned[$day])) {
                        return ['status' => 'failed', 'error' => "pass {$pass}, day {$day}: the marker changed since preflight"] + $result;
                    }
                }
                $digest = PriceDigest::compute($this->db);
                $movers = PriceDigest::movers($this->db);
                $full[] = [$digest, $movers];
                $result['map_build_id'] ??= $digest['map_build_id'];
                $result['passes'][] = ['pass' => $pass, 'digests' => $digest['tables'], 'movers_check' => $movers, 'seconds' => round((hrtime(true) - $started) / 1e9, 3)];
            }

            $result['status'] = 'ok';
            $result['identical'] = $full[0] === $full[1] && $full[1] === $full[2];

            return $result;
        } finally {
            IngestLock::release($this->db);
        }
    }

    /**
     * Validates every day before anything is wiped and pins each day's marker; returns why not as a string.
     *
     * @param  list<string>  $days
     * @return array<string, CarbonImmutable>|string
     */
    private function preflight(array $days): array|string
    {
        $days = array_values(array_unique($days));
        sort($days, SORT_STRING);
        if ($days === []) {
            return self::NO_DAYS;
        }

        $pinned = [];
        foreach ($days as $day) {
            try {
                $pinned[$day] = PriceSnapshotReader::at($this->snapshotsRoot, $day)->validate()['marker'];
            } catch (DayRefused $e) {
                return "day {$day} refused: {$e->error}";
            }
        }

        $events = $this->db->selectOne("SELECT to_regclass('price_events') IS NOT NULL AS present", [], false);
        if ($events instanceof stdClass && $events->present === true) {
            $dispatched = $this->db->selectOne('SELECT EXISTS (SELECT 1 FROM price_events WHERE dispatched_at IS NOT NULL) AS any', [], false);
            if ($dispatched instanceof stdClass && $dispatched->any === true) {
                return 'price_events holds dispatched rows: replay --fresh refuses (R180a)';
            }
        }

        return $pinned;
    }

    /** Marks the source's ok and running runs superseded and truncates the derived tables, in one transaction (D12). */
    private function wipe(): void
    {
        $this->db->transaction(function (): void {
            $this->db->update(
                "UPDATE ingest_runs SET status = 'superseded' WHERE source = ? AND status IN ('ok', 'running')",
                [PricingConfig::source()],
            );
            $this->db->statement('TRUNCATE '.implode(', ', self::WIPED_TABLES));
        });
    }
}
