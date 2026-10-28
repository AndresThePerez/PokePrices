<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A holding in a person's portfolio: portfolio_holdings, one row per (user, variant) with a quantity
 * of 1 to 9999, deleted with the user. variant_key has no foreign key to card_variants. Rows are written by
 * Portfolio, under the per-user lock.
 *
 * @property int $id
 * @property int $user_id
 * @property string $variant_key
 * @property int $quantity
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Table('portfolio_holdings', timestamps: false)]
final class PortfolioHolding extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
