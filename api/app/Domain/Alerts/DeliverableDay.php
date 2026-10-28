<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Crash-repair delivery semantics: the one predicate that decides
 * which price_events may ever be delivered. Everything that fans events out reads them through eventsCte().
 *
 * - newest: the newest day of an ok run (the newest finalized day, DayIngest's expression);
 * - floor: the newest day of an ok or superseded run (the newest day ever finalized; it survives Replay's wipe,
 *   which only re-labels runs superseded), so while a crash repair has re-finalized only older days, newest < floor;
 * - watermark: the newest day any dispatch ran for (dispatch_days).
 *
 * Every term ignores days after the app clock's UTC date: no legitimate close is dated after the day it was
 * collected (ADR 0001). The day is deliverable when allows(newest, floor, watermark) holds. At its first dispatch
 * dispatch_days records the highest price_events id of that day (the ceiling); only events of the day with an id up
 * to it are deliverable, so an event written for an already-dispatched day is never delivered. price_events is
 * never written here (dispatched_at stays null, so Replay's dispatched-rows interlock never trips).
 */
final class DeliverableDay
{
    /** Pure: dates as YYYY-MM-DD strings, which compare as dates. */
    public static function allows(?string $newest, ?string $floor, ?string $watermark): bool
    {
        return $newest !== null
            && ($floor === null || strcmp($newest, $floor) >= 0)
            && ($watermark === null || strcmp($newest, $watermark) >= 0);
    }

    /**
     * The predicate's terms for $source at $now, and the ok or superseded run days after $now's UTC date.
     *
     * @return array{today: string, newest: string|null, floor: string|null, watermark: string|null, future: list<string>}
     */
    public static function terms(ConnectionInterface $db, string $source, CarbonImmutable $now): array
    {
        $today = $now->utc()->format('Y-m-d');
        $row = $db->selectOne(<<<'SQL'
            SELECT
                (SELECT max(day)::text FROM ingest_runs WHERE source = ? AND status = 'ok' AND day <= ?::date) AS newest,
                (SELECT max(day)::text FROM ingest_runs WHERE source = ? AND status IN ('ok', 'superseded') AND day <= ?::date) AS floor,
                (SELECT max(day)::text FROM dispatch_days WHERE source = ? AND day <= ?::date) AS watermark
            SQL, [$source, $today, $source, $today, $source, $today], false);
        $future = $db->select(
            "SELECT DISTINCT day::text AS day FROM ingest_runs WHERE source = ? AND status IN ('ok', 'superseded') AND day > ?::date ORDER BY 1",
            [$source, $today],
            false,
        );

        return [
            'today' => $today,
            'newest' => self::text($row, 'newest'),
            'floor' => self::text($row, 'floor'),
            'watermark' => self::text($row, 'watermark'),
            'future' => self::days($future),
        ];
    }

    /**
     * The deliverable day of $source at $now, or null; records the day's ceiling at its first dispatch.
     */
    public static function resolve(ConnectionInterface $db, string $source, CarbonImmutable $now): ?Deliverable
    {
        return self::decide($db, $source, self::terms($db, $source, $now));
    }

    /**
     * The deliverable day for terms() already read, or null when allows() does not hold. At the day's first
     * dispatch, in one transaction, dispatch_days gets (source, day, max(price_events.id) of that source and day);
     * a later dispatch keeps the stored ceiling (ON CONFLICT DO NOTHING) and returns it.
     *
     * @param  array{today: string, newest: string|null, floor: string|null, watermark: string|null, future: list<string>}  $terms
     */
    public static function decide(ConnectionInterface $db, string $source, array $terms): ?Deliverable
    {
        $day = $terms['newest'];
        if ($day === null || ! self::allows($day, $terms['floor'], $terms['watermark'])) {
            return null;
        }

        $ceiling = $db->transaction(function () use ($db, $source, $day): ?int {
            $db->insert(<<<'SQL'
                INSERT INTO dispatch_days (source, day, event_id_ceiling)
                SELECT ?, ?::date, (SELECT max(id) FROM price_events WHERE source = ? AND day = ?::date)
                ON CONFLICT (source, day) DO NOTHING
                SQL, [$source, $day, $source, $day]);
            $row = $db->selectOne('SELECT event_id_ceiling FROM dispatch_days WHERE source = ? AND day = ?::date', [$source, $day], false);

            return $row instanceof stdClass && $row->event_id_ceiling !== null ? (int) $row->event_id_ceiling : null;
        });

        return new Deliverable($day, $ceiling);
    }

    /**
     * The CTE `deliverable_events`: the price_events of the deliverable day with an id up to its ceiling (none when
     * the ceiling is null). The only selection of events for delivery; bind eventsBindings() first.
     */
    public static function eventsCte(): string
    {
        return 'deliverable_events AS (SELECT e.* FROM price_events e WHERE e.source = ? AND e.day = ?::date AND e.id <= ?::bigint)';
    }

    /** @return array{0: string, 1: string, 2: int|null} */
    public static function eventsBindings(string $source, Deliverable $deliverable): array
    {
        return [$source, $deliverable->day, $deliverable->ceiling];
    }

    /**
     * The ok days strictly between the watermark and the deliverable day: a catch-up passes over them and they are
     * never dispatched (D2 (iii)). None without a watermark (a first start is not a catch-up).
     *
     * @param  array{today: string, newest: string|null, floor: string|null, watermark: string|null, future: list<string>}  $terms
     * @return list<string>
     */
    public static function passedOver(ConnectionInterface $db, string $source, array $terms): array
    {
        if ($terms['watermark'] === null || $terms['newest'] === null) {
            return [];
        }

        return self::days($db->select(
            "SELECT DISTINCT day::text AS day FROM ingest_runs WHERE source = ? AND status = 'ok' AND day > ?::date AND day < ?::date ORDER BY 1",
            [$source, $terms['watermark'], $terms['newest']],
            false,
        ));
    }

    /** Deletes the source's dispatch_days rows dated after $day (the operator reset, D2); returns how many. */
    public static function resetAfter(ConnectionInterface $db, string $source, string $day): int
    {
        return $db->delete('DELETE FROM dispatch_days WHERE source = ? AND day > ?::date', [$source, $day]);
    }

    private static function text(mixed $row, string $column): ?string
    {
        $value = $row instanceof stdClass ? ($row->{$column} ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<string>
     */
    private static function days(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->day ?? null)) {
                $days[] = $row->day;
            }
        }

        return $days;
    }
}
