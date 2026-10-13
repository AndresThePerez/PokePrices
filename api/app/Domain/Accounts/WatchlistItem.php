<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A variant on a person's watchlist (plan P4-i1 D5): watchlist_items, one row per (user, variant), deleted with the
 * user. variant_key has no foreign key to card_variants (D5). Rows are written by Watchlist, under the per-user lock.
 *
 * @property int $id
 * @property int $user_id
 * @property string $variant_key
 * @property CarbonImmutable $created_at
 */
#[Table('watchlist_items', timestamps: false)]
final class WatchlistItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
