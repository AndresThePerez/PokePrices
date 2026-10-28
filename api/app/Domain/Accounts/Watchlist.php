<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * A person's watchlist (plan P4-i1 D5, D6): the variants they watch, read with their latest closes in one SQL
 * statement, added and removed by variant key. Every write is idempotent in effect, and adding counts then inserts
 * under the per-user lock (AccountLock), so the list never holds more than accounts.limits.watchlist rows.
 */
final class Watchlist
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The user's items ordered by card id, then variant key: variant_key, card_id, finish, edition, treatment, the
     * price_<column> values of its latest close (null columns without one) and its alert_pct (plan P5-i1 T05), in one
     * statement.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $userId): array
    {
        $sql = 'SELECT '.VariantRows::selectAndJoin('w').', w.alert_pct FROM watchlist_items w '.VariantRows::joins('w')
            .' WHERE w.user_id = ? '.VariantRows::orderBy('w');

        return VariantRows::arrays($this->db->select($sql, [(string) config('pricing.source'), $userId], false));
    }

    /**
     * Puts the variant on the user's watchlist; nothing changes when it is already there.
     *
     * @throws ValidationException errors.variant_key when the key names no variant, or the list is full
     */
    public function add(int $userId, string $variantKey): void
    {
        if (! VariantKey::isValid($variantKey)) {
            throw self::refused(AccountLimits::UNKNOWN_VARIANT);
        }

        $this->db->transaction(function () use ($userId, $variantKey): void {
            AccountLock::forUser($this->db, $userId);
            if (! Variants::exist($this->db, $variantKey)) {
                throw self::refused(AccountLimits::UNKNOWN_VARIANT);
            }
            $present = $this->db->selectOne('SELECT 1 AS present FROM watchlist_items WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey], false);
            if ($present !== null) {
                return;
            }
            if (Variants::count($this->db, 'watchlist_items', $userId) >= AccountLimits::watchlist()) {
                throw self::refused(AccountLimits::watchlistFull());
            }
            $this->db->insert('INSERT INTO watchlist_items (user_id, variant_key) VALUES (?, ?) ON CONFLICT (user_id, variant_key) DO NOTHING', [$userId, $variantKey]);
        });
    }

    /** Takes the variant off the user's watchlist; nothing happens when it is not there or the key is malformed. */
    public function remove(int $userId, string $variantKey): void
    {
        if (VariantKey::isValid($variantKey)) {
            $this->db->delete('DELETE FROM watchlist_items WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey]);
        }
    }

    private static function refused(string $message): ValidationException
    {
        return ValidationException::withMessages(['variant_key' => [$message]]);
    }
}
