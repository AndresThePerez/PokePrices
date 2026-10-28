<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Jobs;

use App\Domain\Alerts\Deliverable;
use App\Domain\Alerts\DeliverableDay;
use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\NotificationText;
use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Catalog\CatalogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;

/**
 * Watchlist-scoped fan-out of movement events (plan P5-i1 T01, D2, D3, D4, D8; scheduled every five minutes as
 * pokeprices-alerts-dispatch; pokeprices:alerts:dispatch runs it in-line). T06 adds alerts and the daily digest.
 *
 * Only the events DeliverableDay allows are read. Each becomes one price.moved delivery (channel webhook) per active
 * standard endpoint of each eligible user (verified, not the demo) holding its variant on the watchlist or the
 * portfolio; Discord endpoints never receive price.moved. Each also becomes one alert.triggered delivery per eligible
 * user whose watchlist row for its variant has a threshold the absolute change reaches; and each eligible user with
 * the digest on whose watchlist or portfolio holds at least one deliverable event gets one digest for the day.
 * Alerts and digests go to the user's channel: their active Discord endpoint when they chose Discord and have one at
 * fan-out time, else email (the address is read when the email is sent). Creation is INSERT ... ON CONFLICT DO
 * NOTHING on deliveries_moved_unique, deliveries_alert_unique and deliveries_digest_unique, so any number of runs
 * creates each delivery once, and a watcher added later gets the day's deliveries on the next run. Card names are
 * read once, only when a delivery is missing; while that read is degraded within config('alerts.catalog_grace_minutes')
 * of the day's newest ok run, nothing is created this run.
 *
 * Writes deliveries and dispatch_days only. Logs alerts_dispatch (info, the summary the command prints) and an
 * alerts_dispatch_skipped warning for every skip: repair in progress, newest older than the last dispatched day,
 * days passed over, a future-dated run ignored, catalog degraded.
 *
 * ShouldBeUnique (plan Q1-i1 T02, review b b2): at most one dispatch is queued or running; uniqueFor (240 s) stays
 * below the five-minute schedule. Timeout 80 s, below the redis queue's retry_after (90 s); three tries, as the
 * worker's --tries gave it before.
 */
#[Tries(3)]
#[Timeout(80)]
final class DispatchEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 240;

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
     * @return array{source: string, deliverable_day: string|null, newest_day: string|null, floor: string|null, watermark: string|null, ceiling: int|null, events: int, measured: int|null, scoped_events: int, created: array{'price.moved': int, 'alert.triggered': int, digest: int}, skipped: string|null, passed_over: list<string>}
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
        $created = ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0];
        if ($deliverable !== null) {
            $passed = DeliverableDay::passedOver($db, $source, $terms);
            if ($passed !== []) {
                self::skip($source, 'days passed over', $terms, $passed);
            }
            [$events, $scoped] = self::counts($db, $source, $deliverable);
            $measured = self::measured($db, $source, $deliverable->day);

            $missing = self::missing($db, $source, $deliverable);
            $alerts = self::missingAlerts($db, $source, $deliverable);
            $digests = self::missingDigests($db, $source, $deliverable);
            if ($missing !== [] || $alerts !== [] || $digests !== []) {
                $names = self::names($catalog, [...$missing, ...$alerts, ...$digests]);
                if ($names['degraded'] && self::withinGrace($db, $source, $deliverable->day, $now)) {
                    $skipped = 'catalog degraded';
                    self::skip($source, 'catalog degraded', $terms, [$deliverable->day]);
                } else {
                    $created = [
                        'price.moved' => self::create($db, $missing, $names['cards'], $now),
                        'alert.triggered' => self::createAlerts($db, $alerts, $names['cards'], $now),
                        'digest' => self::createDigests($db, $deliverable->day, $digests, $names['cards'], $now),
                    ];
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
            'created' => $created,
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
     * Every (event, user) pair that should have an alert.triggered delivery and has none: an eligible user whose
     * watchlist row for the event's variant has a threshold that abs(change_pct) reaches, with the event's payload
     * columns, the threshold and the user's Discord endpoint when Discord is their channel and it is active.
     *
     * @return list<stdClass>
     */
    private static function missingAlerts(ConnectionInterface $db, string $source, Deliverable $deliverable): array
    {
        $rows = $db->select(
            'WITH '.DeliverableDay::eventsCte().' SELECT '.PriceMovedPayload::COLUMNS.', '.<<<'SQL'
                w.user_id AS notify_user_id, w.alert_pct,
                CASE WHEN s.channel = 'discord' THEN d.id END AS discord_endpoint_id
                FROM deliverable_events e
                JOIN watchlist_items w ON w.variant_key = e.variant_key AND w.alert_pct IS NOT NULL AND abs(e.change_pct) >= w.alert_pct
                JOIN users u ON u.id = w.user_id AND u.email_verified_at IS NOT NULL AND NOT u.is_demo
                LEFT JOIN notification_settings s ON s.user_id = w.user_id
                LEFT JOIN webhook_endpoints d ON d.user_id = w.user_id AND d.kind = 'discord' AND d.disabled_at IS NULL
                WHERE NOT EXISTS (
                    SELECT 1 FROM deliveries x WHERE x.kind = 'alert.triggered' AND x.user_id = w.user_id AND x.event_id = e.id
                )
                ORDER BY e.id, w.user_id
                SQL,
            DeliverableDay::eventsBindings($source, $deliverable),
            false,
        );

        return array_values(array_filter($rows, fn (mixed $r): bool => $r instanceof stdClass));
    }

    /**
     * The deliverable events of every eligible user with the digest on and no digest for the day yet, one row per
     * (user, event) of their watchlist or portfolio (a variant on both once), with the user's Discord endpoint when
     * Discord is their channel and it is active; ordered by user. A user with no deliverable event has no row.
     *
     * @return list<stdClass>
     */
    private static function missingDigests(ConnectionInterface $db, string $source, Deliverable $deliverable): array
    {
        $rows = $db->select(
            'WITH '.DeliverableDay::eventsCte().', '.self::WATCHED.' SELECT '.PriceMovedPayload::COLUMNS.', '.<<<'SQL'
                s.user_id AS notify_user_id,
                CASE WHEN s.channel = 'discord' THEN d.id END AS discord_endpoint_id
                FROM notification_settings s
                JOIN eligible_watched w ON w.user_id = s.user_id
                JOIN deliverable_events e ON e.variant_key = w.variant_key
                LEFT JOIN webhook_endpoints d ON d.user_id = s.user_id AND d.kind = 'discord' AND d.disabled_at IS NULL
                WHERE s.digest AND NOT EXISTS (
                    SELECT 1 FROM deliveries x WHERE x.kind = 'digest' AND x.user_id = s.user_id AND x.day = ?::date
                )
                ORDER BY s.user_id, e.id
                SQL,
            [...DeliverableDay::eventsBindings($source, $deliverable), $deliverable->day],
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
     * Inserts the missing alerts (ON CONFLICT DO NOTHING on deliveries_alert_unique); returns how many it created.
     *
     * @param  list<stdClass>  $alerts
     * @param  array<string, array{name: string, set_name: string, number: string}|null>  $cards
     */
    private static function createAlerts(ConnectionInterface $db, array $alerts, array $cards, CarbonImmutable $now): int
    {
        $rows = [];
        foreach ($alerts as $row) {
            $card = $cards[(string) $row->card_id] ?? null;
            $discord = $row->discord_endpoint_id === null ? null : (int) $row->discord_endpoint_id;
            $body = $discord === null
                ? NotificationText::emailBody(NotificationText::alert($row, $card, (int) $row->alert_pct))
                : DiscordMessage::envelope(NotificationText::discordAlert($row, $card, (int) $row->alert_pct));
            $rows[] = [(int) $row->notify_user_id, $discord, (int) $row->id, (string) $row->day, $body];
        }

        return self::insertNotifications($db, 'alert.triggered', $rows, $now, '(user_id, event_id) WHERE kind = \'alert.triggered\'');
    }

    /**
     * Inserts one digest per user of $digests (ON CONFLICT DO NOTHING on deliveries_digest_unique); returns how many
     * it created.
     *
     * @param  list<stdClass>  $digests
     * @param  array<string, array{name: string, set_name: string, number: string}|null>  $cards
     */
    private static function createDigests(ConnectionInterface $db, string $day, array $digests, array $cards, CarbonImmutable $now): int
    {
        $byUser = [];
        foreach ($digests as $row) {
            $userId = (int) $row->notify_user_id;
            $byUser[$userId]['discord'] = $row->discord_endpoint_id === null ? null : (int) $row->discord_endpoint_id;
            $byUser[$userId]['moves'][] = ['event' => $row, 'card' => $cards[(string) $row->card_id] ?? null];
        }

        $rows = [];
        foreach ($byUser as $userId => $digest) {
            $body = $digest['discord'] === null
                ? NotificationText::emailBody(NotificationText::digest($day, $digest['moves'], (int) config('alerts.digest.max_lines', 25)))
                : DiscordMessage::envelope(NotificationText::discordDigest($day, $digest['moves']));
            $rows[] = [$userId, $digest['discord'], null, $day, $body];
        }

        return self::insertNotifications($db, 'digest', $rows, $now, '(user_id, day) WHERE kind = \'digest\'');
    }

    /**
     * Inserts alert or digest deliveries, channel discord with the endpoint when one is given, else email.
     *
     * @param  list<array{0: int, 1: int|null, 2: int|null, 3: string, 4: string}>  $rows  [user_id, discord endpoint id, event_id, day, body]
     */
    private static function insertNotifications(ConnectionInterface $db, string $kind, array $rows, CarbonImmutable $now, string $conflict): int
    {
        $due = $now->utc()->format('Y-m-d H:i:sP');
        $created = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $bindings = [];
            foreach ($chunk as [$userId, $endpointId, $eventId, $day, $body]) {
                $values[] = '(?, ?, ?, ?, ?::bigint, ?::bigint, ?::date, ?, ?::timestamptz)';
                array_push($bindings, 'msg_'.Str::ulid(), $userId, $kind, $endpointId === null ? 'email' : 'discord', $endpointId, $eventId, $day, $body, $due);
            }
            $created += $db->affectingStatement(
                'INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, day, body, next_attempt_at) VALUES '
                .implode(', ', $values)." ON CONFLICT {$conflict} DO NOTHING",
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
