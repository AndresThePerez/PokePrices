<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Jobs;

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\RetryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The delivery sweep (scheduled every minute as pokeprices-deliveries; pokeprices:alerts:
 * deliver runs one in-line). One worker serves ingest too, so a sweep is short and bounded:
 *
 * 1. Retire: pending rows claimed 2 x max_attempts times fail with internal (a row whose sends never finish cannot
 *    loop); delivered and failed rows created more than retention_days ago are deleted, except the read-only demo's
 *    samples.
 * 2. Claim: at most batch due rows, at most per_endpoint per endpoint, oldest first, FOR UPDATE SKIP LOCKED in one
 *    short transaction; each gets claims + 1 and a lease (next_attempt_at = now + lease_seconds) that only a crashed
 *    sweep ever waits out.
 * 3. Send in-line, one row after another, while the sweep is inside sweep_budget_seconds. When the budget is spent,
 *    the rest are released due at once; after a timeout or connection failure on an endpoint, the rest of that
 *    endpoint's rows are released due after RetryPolicy::delay(1). A release gives the claim back and makes no
 *    attempt.
 *
 * ShouldBeUnique (120 s): at most one sweep is queued or running. The send path never throws (DeliverySender
 * contains every exception, the outcome write included), so a sweep never ends in failed_jobs because of a row.
 * Every statement of a sweep, the sender's included, runs on the connection sweep() is given.
 *
 * Timeout 80 s with FailOnTimeout: above the 60 s send budget plus one bounded send, below the redis
 * queue's retry_after (90 s), so a hung sweep is stopped and failed by its worker before the queue hands it out again.
 */
#[Timeout(80)]
#[FailOnTimeout]
final class DeliverDue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(DeliverySender $sender, RetryPolicy $retry): void
    {
        self::sweep(DB::connection(), $sender, $retry, CarbonImmutable::now());
    }

    /**
     * One sweep at $now; returns the counts the deliver command prints.
     *
     * @return array{retired: int, claimed: int, delivered: int, retry: int, failed: int, released: int}
     */
    public static function sweep(ConnectionInterface $db, DeliverySender $sender, RetryPolicy $retry, CarbonImmutable $now): array
    {
        $maxAttempts = (int) config('alerts.delivery.max_attempts', 6);
        $retired = $db->update(
            "UPDATE deliveries SET status = 'failed', last_error = 'internal' WHERE status = 'pending' AND claims >= ?",
            [2 * $maxAttempts],
        );
        // The read-only demo's delivered samples (App\Domain\Alerts\DemoSamples) are kept: they are dated by the close
        // they are about, and nothing else ever writes a delivery for the demo.
        $db->delete(
            "DELETE FROM deliveries WHERE status IN ('delivered', 'failed') AND created_at < ?::timestamptz
             AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = deliveries.user_id AND u.is_demo)",
            [self::timestamp($now->subDays((int) config('alerts.delivery.retention_days', 30)))],
        );

        $claimed = $db->transaction(fn (): array => self::claimRows($db, $now, (int) config('alerts.delivery.batch', 10)));
        $lease = $now->addSeconds((int) config('alerts.delivery.lease_seconds', 300));
        $budget = (int) config('alerts.delivery.sweep_budget_seconds', 60);

        $counts = ['retired' => $retired, 'claimed' => count($claimed), 'delivered' => 0, 'retry' => 0, 'failed' => 0, 'released' => 0];
        $slow = [];
        foreach ($claimed as $id => $endpointId) {
            if ($now->diffInSeconds(CarbonImmutable::now()) >= $budget) {
                $counts['released'] += self::release($db, $id, $lease, $now);

                continue;
            }
            if ($endpointId !== null && isset($slow[$endpointId])) {
                $counts['released'] += self::release($db, $id, $lease, $now->addSeconds($retry->delay(1)));

                continue;
            }
            $result = $sender->send($id, $lease, $db);
            if ($result['outcome'] !== 'skipped') {
                $counts[$result['outcome']]++;
            }
            if ($endpointId !== null && in_array($result['error'], ['timeout', 'connection'], true)) {
                $slow[$endpointId] = true;
            }
        }

        return $counts;
    }

    /**
     * Claims due pending rows on $db: at most $limit, at most per_endpoint per endpoint, ordered by
     * next_attempt_at then id, skipping rows another session holds. Each claimed row gets claims + 1 and the lease
     * $now + lease_seconds. Run it inside a transaction: the row locks last until that transaction ends.
     *
     * @return list<int>
     */
    public static function claim(ConnectionInterface $db, CarbonImmutable $now, int $limit): array
    {
        return array_keys($db->transaction(fn (): array => self::claimRows($db, $now, $limit)));
    }

    /**
     * @return array<int, int|null> claimed delivery id => endpoint id, in claim order
     */
    private static function claimRows(ConnectionInterface $db, CarbonImmutable $now, int $limit): array
    {
        $perEndpoint = (int) config('alerts.delivery.per_endpoint', 3);
        $claimed = [];
        $perCount = [];
        $full = [];
        while (count($claimed) < $limit) {
            $sql = "SELECT id, endpoint_id FROM deliveries WHERE status = 'pending' AND next_attempt_at <= ?::timestamptz";
            $bindings = [self::timestamp($now)];
            if ($claimed !== []) {
                $sql .= ' AND id NOT IN ('.implode(', ', array_fill(0, count($claimed), '?')).')';
                array_push($bindings, ...array_keys($claimed));
            }
            if ($full !== []) {
                $sql .= ' AND (endpoint_id IS NULL OR endpoint_id NOT IN ('.implode(', ', array_fill(0, count($full), '?')).'))';
                array_push($bindings, ...array_keys($full));
            }
            $row = $db->selectOne($sql.' ORDER BY next_attempt_at, id LIMIT 1 FOR UPDATE SKIP LOCKED', $bindings);
            if (! $row instanceof stdClass) {
                break;
            }
            $endpointId = $row->endpoint_id === null ? null : (int) $row->endpoint_id;
            $claimed[(int) $row->id] = $endpointId;
            if ($endpointId !== null) {
                $perCount[$endpointId] = ($perCount[$endpointId] ?? 0) + 1;
                if ($perCount[$endpointId] >= $perEndpoint) {
                    $full[$endpointId] = true;
                }
            }
        }

        if ($claimed !== []) {
            $db->update(
                'UPDATE deliveries SET claims = claims + 1, next_attempt_at = ?::timestamptz WHERE id IN ('.implode(', ', array_fill(0, count($claimed), '?')).')',
                [self::timestamp($now->addSeconds((int) config('alerts.delivery.lease_seconds', 300))), ...array_keys($claimed)],
            );
        }

        return $claimed;
    }

    /** Gives a claimed, unsent row back: due at $due, its claim returned, no attempt. Returns 1 when released. */
    private static function release(ConnectionInterface $db, int $id, CarbonImmutable $lease, CarbonImmutable $due): int
    {
        return $db->update(
            "UPDATE deliveries SET next_attempt_at = ?::timestamptz, claims = claims - 1
             WHERE id = ? AND status = 'pending' AND next_attempt_at = ?::timestamptz",
            [self::timestamp($due), $id, self::timestamp($lease)],
        );
    }

    private static function timestamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.uP');
    }
}
