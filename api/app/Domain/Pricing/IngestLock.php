<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * One writer of the price tables at a time: the Postgres advisory lock keyed
 * `pricing:ingest`. The sync ingest takes it with pg_try_advisory_lock for its whole run and exits 1 with
 * `pricing ingest lock held` when another session holds it. The lock is per session, so it is taken and released on
 * the connection the run writes through.
 */
final class IngestLock
{
    /** crc32('pricing:ingest'), derived once; a test pins the derivation. */
    public const int KEY = 1085361408;

    public const string HELD_MESSAGE = 'pricing ingest lock held';

    /** Takes the lock for this session unless another session holds it. */
    public static function tryAcquire(ConnectionInterface $db): bool
    {
        $row = $db->selectOne('SELECT pg_try_advisory_lock(?) AS taken', [self::KEY], false);

        return $row instanceof stdClass && ($row->taken ?? false) === true;
    }

    public static function release(ConnectionInterface $db): void
    {
        $db->select('SELECT pg_advisory_unlock(?)', [self::KEY], false);
    }
}
