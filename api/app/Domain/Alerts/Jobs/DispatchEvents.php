<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Jobs;

use App\Domain\Alerts\Deliverable;
use App\Domain\Alerts\DeliverableDay;
use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Catalog\CatalogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;

/**
 * Watchlist-scoped fan-out of movement events (plan P5-i1 T01, D2, D3, D4, D8; scheduled every five minutes as
 * pokeprices-alerts-dispatch; pokeprices:alerts:dispatch runs it in-line).
 *
 * Only the events DeliverableDay allows are read. Each becomes one price.moved delivery (channel webhook) per active
 * standard endpoint of each eligible user (verified, not the demo) holding its variant on the watchlist or the
 * portfolio; Discord endpoints never receive price.moved. Creation is INSERT ... ON CONFLICT DO NOTHING on
 * deliveries_moved_unique, so any number of runs creates each delivery once, and a watcher added later gets the
 * day's deliveries on the next run. Card names are read once, only when a delivery is missing; while that read is
 * degraded within config('alerts.catalog_grace_minutes') of the day's newest ok run, nothing is created this run.
 *
 * Writes deliveries and dispatch_days only. Logs alerts_dispatch (info, the summary the command prints) and an
 * alerts_dispatch_skipped warning for every skip: repair in progress, newest older than the last dispatched day,
 * days passed over, a future-dated run ignored, catalog degraded.
 */
final class DispatchEvents implements ShouldQueue
{
    use Queueable;

    /** Delivery rows per INSERT statement. */
    private const int INSERT_CHUNK = 500;

    private const string WATCHED = <<<'SQL'
        watched AS (
            SELECT h.user_id, h.variant_key FROM watchlist_items h
            UNION
            SELECT h.user_id, h.variant_key FROM portfolio_holdings h
        ),
        eligible_watched AS (
            SELECT w.user_id, w.variant_key
            FROM watched w
            JOIN users u ON u.id = w.user_id
            WHERE u.email_verified_at IS NOT NULL AND NOT u.is_demo
        )
        SQL;

    public function handle(CatalogRepository $catalog): void
    {
        self::run(DB::connection(), $catalog, CarbonImmutable::now());
    }

    /**
     * One dispatch run at $now; returns the summary line (D8).
     *
     * @return array{source: string, deliverable_day: string|null, newest_day: string|null, floor: string|null, watermark: string|null, ceiling: int|null, events: int, measured: int|null, scoped_events: int, created: array{'price.moved': int}, skipped: string|null, passed_over: list<string>}
     */
    public static function run(ConnectionInterface $db, CatalogRepository $catalog, CarbonImmutable $now): array
    {
        $source = (string) config('pricing.source');
        $terms = DeliverableDay::terms($db, $source, $now);
        $newest = $terms['newest'];

        if ($terms['future'] !== []) {
            self::skip($source, 'future-dated run ignored', $terms, $terms['future']);
        }

        $skipped = null;
        if ($newest === null) {
            $skipped = 'no finalized day';
        } elseif ($terms['floor'] !== null && strcmp($newest, $terms['floor']) < 0) {
            $skipped = "repair in progress: newest finalized day {$newest} is older than {$terms['floor']}";
            self::skip($source, 'repair in progress', $terms, [$newest, $terms['floor']]);
        } elseif ($terms['watermark'] !== null && strcmp($newest, $terms['watermark']) < 0) {
            $skipped = "newest finalized day {$newest} is older than the last dispatched day {$terms['watermark']}";
            self::skip($source, 'newest older than the last dispatched day', $terms, [$newest, $terms['watermark']]);
        }

        $deliverable = $skipped === null ? DeliverableDay::decide($db, $source, $terms) : null;
        $passed = [];
        $events = 0;
        $measured = null;
        $scoped = 0;
        $created = 0;
        if ($deliverable !== null) {
            $passed = DeliverableDay::passedOver($db, $source, $terms);
            if ($passed !== []) {
                self::skip($source, 'days passed over', $terms, $passed);
            }
            [$events, $scoped] = self::counts($db, $source, $deliverable);
            $measured = self::measured($db, $source, $deliverable->day);

            $missing = self::missing($db, $source, $deliverable);
            if ($missing !== []) {
                $names = self::names($catalog, $missing);
                if ($names['degraded'] && self::withinGrace($db, $source, $deliverable->day, $now)) {
                    $skipped = 'catalog degraded';
                    self::skip($source, 'catalog degraded', $terms, [$deliverable->day]);
                } else {
                    $created = self::create($db, $missing, $names['cards'], $now);
                }
            }
        }

        $summary = [
            'source' => $source,
            'deliverable_day' => $deliverable?->day,
            'newest_day' => $newest,
            'floor' => $terms['floor'],
            'watermark' => $terms['watermark'],
            'ceiling' => $deliverable?->ceiling,
            'events' => $events,
            'measured' => $measured,
            'scoped_events' => $scoped,
            'created' => ['price.moved' => $created],
            'skipped' => $skipped,
            'passed_over' => $passed,
        ];
        Log::info('alerts_dispatch', $summary);

        return $summary;
    }

    /**
     * The deliverable events of the day, and how many of them have at least one eligible watcher or holder.
     *
     * @return array{0: int, 1: int}
     */
    private static function counts(ConnectionInterface $db, string $source, Deliverable $deliverable): array
    {
        $row = $db->selectOne(
            'WITH '.DeliverableDay::eventsCte().', '.self::WATCHED.' SELECT count(*) AS events, '
            .'count(*) FILTER (WHERE EXISTS (SELECT 1 FROM eligible_watched w WHERE w.variant_key = e.variant_key)) AS scoped '
            .'FROM deliverable_events e',
            DeliverableDay::eventsBindings($source, $deliverable),
            false,
        );

        return $row instanceof stdClass ? [(int) $row->events, (int) $row->scoped] : [0, 0];
    }

    /** movement_volume's up + down of the day for the movement rule (the P2 measurement), or null without a row. */
    private static function measured(ConnectionInterface $db, string $source, string $day): ?int
    {
        $row = $db->selectOne(
            'SELECT up + down AS n FROM movement_volume WHERE day = ?::date AND source = ? AND rule = ?',
            [$day, $source, (string) config('pricing.movement.rule')],
            false,
        );

        return $row instanceof stdClass ? (int) $row->n : null;
    }

    /**
     * Every (event, endpoint) pair that should have a price.moved delivery and has none, with the event's payload
     * columns and the endpoint's user, ordered by event and endpoint.
     *
     * @return list<stdClass>
     */
    private static function missing(ConnectionInterface $db, string $source, Deliverable $deliverable): array
    {
        $rows = $db->select(
            'WITH '.DeliverableDay::eventsCte().', '.self::WATCHED.' SELECT '.PriceMovedPayload::COLUMNS.', '
            .'p.id AS endpoint_id, p.user_id AS endpoint_user_id '.<<<'SQL'
                FROM deliverable_events e
                JOIN (SELECT DISTINCT user_id, variant_key FROM eligible_watched) w ON w.variant_key = e.variant_key
                JOIN webhook_endpoints p ON p.user_id = w.user_id AND p.kind = 'standard' AND p.disabled_at IS NULL
                WHERE NOT EXISTS (
                    SELECT 1 FROM deliveries d WHERE d.kind = 'price.moved' AND d.event_id = e.id AND d.endpoint_id = p.id
                )
                ORDER BY e.id, p.id
                SQL,
            DeliverableDay::eventsBindings($source, $deliverable),
            false,
        );

        return array_values(array_filter($rows, fn (mixed $r): bool => $r instanceof stdClass));
    }

    /**
     * One batched catalog read of the missing events' card ids.
     *
     * @param  list<stdClass>  $missing
     * @return array{cards: array<string, array{name: string, set_name: string, number: string}|null>, degraded: bool}
     */
    private static function names(CatalogRepository $catalog, array $missing): array
    {
        $ids = [];
        foreach ($missing as $row) {
            $ids[(string) $row->card_id] = true;
        }

        return $catalog->cardNames(array_keys($ids));
    }

    /** Whether $now is less than the grace after the day's newest ok run finished. */
    private static function withinGrace(ConnectionInterface $db, string $source, string $day, CarbonImmutable $now): bool
    {
        $row = $db->selectOne(
            "SELECT max(finished_at) AS finished FROM ingest_runs WHERE source = ? AND status = 'ok' AND day = ?::date",
            [$source, $day],
            false,
        );
        $finished = $row instanceof stdClass && is_string($row->finished) ? CarbonImmutable::parse($row->finished) : null;

        return $finished !== null && $now->lessThan($finished->addMinutes((int) config('alerts.catalog_grace_minutes')));
    }

    /**
     * Inserts the missing deliveries (ON CONFLICT DO NOTHING on deliveries_moved_unique); returns how many it created.
     *
     * @param  list<stdClass>  $missing
     * @param  array<string, array{name: string, set_name: string, number: string}|null>  $cards
     */
    private static function create(ConnectionInterface $db, array $missing, array $cards, CarbonImmutable $now): int
    {
        $due = $now->utc()->format('Y-m-d H:i:sP');
        $bodies = [];
        $created = 0;
        foreach (array_chunk($missing, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $eventId = (int) $row->id;
                $bodies[$eventId] ??= PriceMovedPayload::encode($row, $cards[(string) $row->card_id] ?? null);
                $values[] = "(?, ?, 'price.moved', 'webhook', ?, ?, ?::date, ?, ?::timestamptz)";
                array_push($bindings, 'msg_'.Str::ulid(), (int) $row->endpoint_user_id, (int) $row->endpoint_id, $eventId, (string) $row->day, $bodies[$eventId], $due);
            }
            $created += $db->affectingStatement(
                'INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, day, body, next_attempt_at) VALUES '
                .implode(', ', $values)
                ." ON CONFLICT (event_id, endpoint_id) WHERE kind = 'price.moved' DO NOTHING",
                $bindings,
            );
        }

        return $created;
    }

    /**
     * One alerts_dispatch_skipped warning: the reason, the days it concerns and the predicate's terms.
     *
     * @param  array{today: string, newest: string|null, floor: string|null, watermark: string|null, future: list<string>}  $terms
     * @param  list<string>  $days
     */
    private static function skip(string $source, string $reason, array $terms, array $days): void
    {
        Log::warning('alerts_dispatch_skipped', [
            'source' => $source,
            'reason' => $reason,
            'days' => $days,
            'newest_day' => $terms['newest'],
            'floor' => $terms['floor'],
            'watermark' => $terms['watermark'],
        ]);
    }
}
