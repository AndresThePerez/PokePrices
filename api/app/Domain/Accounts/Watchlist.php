<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Database\ConnectionInterface;

/**
 * A person's watchlist (plan P4-i1 D5, D6): the variants they watch, read with their latest closes in one SQL
 * statement, added and removed by variant key. Every write is idempotent in effect, and adding counts then inserts
 * under the per-user lock (AccountLock), so the list never holds more than accounts.limits.watchlist rows.
 */
final class Watchlist
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The user's items ordered by card id, then variant key: variant_key, card_id, finish, edition, treatment and
     * the price_<column> values of its latest close (null columns without one), in one statement.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $userId): array
    {
        $sql = 'SELECT '.VariantRows::selectAndJoin('w').' FROM watchlist_items w '.VariantRows::joins('w')
            .' WHERE w.user_id = ? '.VariantRows::orderBy('w');

        return VariantRows::arrays($this->db->select($sql, [(string) config('pricing.source'), $userId], false));
    }

    /**
     * Puts the variant on the user's watchlist. Null when it is there afterwards (added now or already there),
     * otherwise the 422 message: the key names no variant, or the list is full.
     */
    public function add(int $userId, string $variantKey): ?string
    {
        if (! VariantKey::isValid($variantKey)) {
            return AccountLimits::UNKNOWN_VARIANT;
        }

        return $this->db->transaction(function () use ($userId, $variantKey): ?string {
            AccountLock::forUser($this->db, $userId);
            if (! Variants::exist($this->db, $variantKey)) {
                return AccountLimits::UNKNOWN_VARIANT;
            }
            $present = $this->db->selectOne('SELECT 1 AS present FROM watchlist_items WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey], false);
            if ($present !== null) {
                return null;
            }
            if (Variants::count($this->db, 'watchlist_items', $userId) >= AccountLimits::watchlist()) {
                return AccountLimits::watchlistFull();
            }
            $this->db->insert('INSERT INTO watchlist_items (user_id, variant_key) VALUES (?, ?) ON CONFLICT (user_id, variant_key) DO NOTHING', [$userId, $variantKey]);

            return null;
        });
    }

    /** Takes the variant off the user's watchlist; nothing happens when it is not there or the key is malformed. */
    public function remove(int $userId, string $variantKey): void
    {
        if (VariantKey::isValid($variantKey)) {
            $this->db->delete('DELETE FROM watchlist_items WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey]);
        }
    }
}
