<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * The alert threshold of one of a person's watchlist rows (plan P5-i1 T05, D9): a whole percentage from
 * MovementRule::alertFloor() to MAX, or none. The caller has validated the key's shape and the percentage.
 */
final class AlertThresholds
{
    public const int MAX = 100;

    public const string NOT_WATCHED = 'Add this variant to your watchlist first.';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @throws ValidationException errors.variant_key when the variant is not on the person's watchlist */
    public function set(int $userId, string $variantKey, int $pct): void
    {
        $updated = $this->db->update('UPDATE watchlist_items SET alert_pct = ? WHERE user_id = ? AND variant_key = ?', [$pct, $userId, $variantKey]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['variant_key' => [self::NOT_WATCHED]]);
        }
    }

    /** Clears the threshold; a row without one, or no row, is left as it is. */
    public function clear(int $userId, string $variantKey): void
    {
        $this->db->update('UPDATE watchlist_items SET alert_pct = NULL WHERE user_id = ? AND variant_key = ? AND alert_pct IS NOT NULL', [$userId, $variantKey]);
    }
}
