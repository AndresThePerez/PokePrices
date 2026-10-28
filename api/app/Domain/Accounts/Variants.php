<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/** The two reads every list write makes under the per-user lock: does the variant exist, and how full is the list. */
final class Variants
{
    /** Whether card_variants holds the key (read only: P4 never writes card_variants). */
    public static function exist(ConnectionInterface $db, string $variantKey): bool
    {
        return $db->selectOne('SELECT 1 AS present FROM card_variants WHERE variant_key = ?', [$variantKey], false) !== null;
    }

    /** The number of rows the user holds in $table (watchlist_items or portfolio_holdings). */
    public static function count(ConnectionInterface $db, string $table, int $userId): int
    {
        $table = $table === 'portfolio_holdings' ? 'portfolio_holdings' : 'watchlist_items';
        $row = $db->selectOne("SELECT count(*) AS n FROM {$table} WHERE user_id = ?", [$userId], false);

        return $row instanceof stdClass && is_numeric($row->n ?? null) ? (int) $row->n : 0;
    }
}
