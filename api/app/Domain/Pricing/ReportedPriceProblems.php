<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The price-job problems that were reported (table reported_price_problems), so the hourly report posts a problem
 * once when it starts and once when it clears, whatever restarts in between. A problem is one of
 * PriceJobProblems::all(); its key is its identity. A key has at most one open report (the unique index on key where
 * cleared_at is null decides it, not a read before the write); the same problem starting again after it was cleared
 * is a new report.
 */
final class ReportedPriceProblems
{
    /** crc32('pricing:problem-report'): the advisory lock one report run holds from its first read to its last write. */
    public const int LOCK_KEY = 4253178751;

    /** Takes the report lock for this session unless another session holds it. */
    public static function tryLock(ConnectionInterface $db): bool
    {
        $row = $db->selectOne('SELECT pg_try_advisory_lock(?) AS taken', [self::LOCK_KEY], false);

        return $row instanceof stdClass && ($row->taken ?? false) === true;
    }

    public static function unlock(ConnectionInterface $db): void
    {
        $db->select('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY], false);
    }

    /**
     * The reports not yet cleared, oldest first.
     *
     * @return list<array{id: int, key: string, kind: string, day: string|null, sentence: string, reported_at: CarbonImmutable}>
     */
    public static function open(?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $rows = $db->select(
            "SELECT id, key, kind, to_char(day, 'YYYY-MM-DD') AS day, sentence, reported_at
             FROM reported_price_problems WHERE cleared_at IS NULL ORDER BY id",
            [],
            false,
        );

        $open = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_numeric($row->id) || ! is_string($row->key) || ! is_string($row->kind)
                || ! is_string($row->sentence) || ! is_string($row->reported_at)) {
                continue;
            }
            $open[] = [
                'id' => (int) $row->id,
                'key' => $row->key,
                'kind' => $row->kind,
                'day' => is_string($row->day) ? $row->day : null,
                'sentence' => $row->sentence,
                'reported_at' => CarbonImmutable::parse($row->reported_at)->utc(),
            ];
        }

        return $open;
    }

    /**
     * Records that $problem was reported at $at. False, and nothing written, when its key already has an open report.
     *
     * @param  array{kind: string, day: string|null, key: string, sentence: string}  $problem
     */
    public static function report(array $problem, CarbonImmutable $at, ?ConnectionInterface $db = null): bool
    {
        $db ??= DB::connection();

        return $db->affectingStatement(
            'INSERT INTO reported_price_problems (key, kind, day, sentence, reported_at)
             VALUES (?, ?, ?::date, ?, ?::timestamptz)
             ON CONFLICT (key) WHERE cleared_at IS NULL DO NOTHING',
            [$problem['key'], $problem['kind'], $problem['day'], $problem['sentence'], self::timestamp($at)],
        ) === 1;
    }

    /** Records that the open report $id was reported cleared at $at. False when it is gone or already cleared. */
    public static function clear(int $id, CarbonImmutable $at, ?ConnectionInterface $db = null): bool
    {
        $db ??= DB::connection();

        return $db->affectingStatement(
            'UPDATE reported_price_problems SET cleared_at = greatest(?::timestamptz, reported_at) WHERE id = ? AND cleared_at IS NULL',
            [self::timestamp($at), $id],
        ) === 1;
    }

    private static function timestamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.uP');
    }
}
