<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * A person's portfolio (plan P4-i1 D5, D6, D9): the variants they hold with a quantity, read with their latest closes
 * and valued in one SQL statement, set and removed by variant key. All money arithmetic happens in Postgres numeric:
 * a holding's value is quantity × close as numeric(20,2) text, the total sums the priced holdings only, and
 * first_day/last_day are the oldest and newest close days it rests on. A holding without a close has no value and is
 * never counted as zero. Setting counts then inserts under the per-user lock (AccountLock), so the portfolio never
 * holds more than accounts.limits.portfolio rows.
 */
final class Portfolio
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The user's holdings ordered by card id, then variant key (each row: variant_key, card_id, finish, edition,
     * treatment, quantity, value and the price_<column> values of its latest close), with the total value and the
     * first and last close day over the priced holdings (all null when none is priced), in one statement.
     *
     * @return array{rows: list<array<string, mixed>>, total_value: string|null, first_day: string|null, last_day: string|null}
     */
    public function holdings(int $userId): array
    {
        $sql = 'SELECT '.VariantRows::selectAndJoin('h').', h.quantity,'
            .' (h.quantity * p.close)::numeric(20,2)::text AS value,'
            .' (sum(h.quantity * p.close) OVER ())::numeric(20,2)::text AS total_value,'
            ." to_char(min(p.day) OVER (), 'YYYY-MM-DD') AS first_day,"
            ." to_char(max(p.day) OVER (), 'YYYY-MM-DD') AS last_day"
            .' FROM portfolio_holdings h '.VariantRows::joins('h')
            .' WHERE h.user_id = ? '.VariantRows::orderBy('h');
        $rows = VariantRows::arrays($this->db->select($sql, [(string) config('pricing.source'), $userId], false));
        $first = $rows[0] ?? [];

        return [
            'rows' => $rows,
            'total_value' => is_string($first['total_value'] ?? null) ? $first['total_value'] : null,
            'first_day' => is_string($first['first_day'] ?? null) ? $first['first_day'] : null,
            'last_day' => is_string($first['last_day'] ?? null) ? $first['last_day'] : null,
        ];
    }

    /**
     * Sets the quantity the user holds of the variant (never adds to it). The quantity is validated by the caller
     * (1 to accounts.limits.quantity_max; the database checks 1 to 9999 too).
     *
     * @throws ValidationException errors.variant_key when the key names no variant, or the portfolio is full
     */
    public function set(int $userId, string $variantKey, int $quantity): void
    {
        if (! VariantKey::isValid($variantKey)) {
            throw self::refused(AccountLimits::UNKNOWN_VARIANT);
        }

        $this->db->transaction(function () use ($userId, $variantKey, $quantity): void {
            AccountLock::forUser($this->db, $userId);
            if (! Variants::exist($this->db, $variantKey)) {
                throw self::refused(AccountLimits::UNKNOWN_VARIANT);
            }
            $updated = $this->db->update(
                'UPDATE portfolio_holdings SET quantity = ?, updated_at = now() WHERE user_id = ? AND variant_key = ?',
                [$quantity, $userId, $variantKey],
            );
            if ($updated > 0) {
                return;
            }
            if (Variants::count($this->db, 'portfolio_holdings', $userId) >= AccountLimits::portfolio()) {
                throw self::refused(AccountLimits::portfolioFull());
            }
            $this->db->insert('INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, ?, ?)', [$userId, $variantKey, $quantity]);
        });
    }

    /** Removes the holding; nothing happens when it is not there or the key is malformed. */
    public function remove(int $userId, string $variantKey): void
    {
        if (VariantKey::isValid($variantKey)) {
            $this->db->delete('DELETE FROM portfolio_holdings WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey]);
        }
    }

    private static function refused(string $message): ValidationException
    {
        return ValidationException::withMessages(['variant_key' => [$message]]);
    }
}
