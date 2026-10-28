<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Deletes a person's account: in one transaction, every sessions row with the user's id, the
 * password_reset_tokens row of the email and the user row, whose watchlist_items and portfolio_holdings rows go with
 * it (ON DELETE CASCADE). Nothing of anyone else is touched. Signing the current browser out is the caller's.
 */
final class AccountDeletion
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function delete(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            $this->db->delete('DELETE FROM sessions WHERE user_id = ?', [$user->id]);
            $this->db->delete('DELETE FROM password_reset_tokens WHERE lower(email) = lower(?)', [$user->email]);
            $this->db->delete('DELETE FROM users WHERE id = ?', [$user->id]);
        });
    }
}
