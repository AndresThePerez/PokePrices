<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Database\ConnectionInterface;

/**
 * The per-user write lock of the watchlist and the portfolio (plan P4-i1 D5, review F-P4-06): a Postgres advisory
 * lock held until the end of the caller's transaction, keyed by (NAMESPACE, user id). Two writers of one user's lists
 * take turns, so a count-then-insert can never let a list grow past its limit; different users never wait on each
 * other. The two-key form keeps clear of the one-key price ingest lock (App\Domain\Pricing\IngestLock).
 */
final class AccountLock
{
    /** crc32('accounts:user') & 0x7fffffff, the first key of every per-user lock. */
    public const int NAMESPACE = 1199915603;

    /** Waits for the user's lock; call inside a transaction (it is released when the transaction ends). */
    public static function forUser(ConnectionInterface $db, int $userId): void
    {
        $db->select('SELECT pg_advisory_xact_lock(?::integer, (?::bigint % 2147483647)::integer)', [self::NAMESPACE, $userId], false);
    }
}
