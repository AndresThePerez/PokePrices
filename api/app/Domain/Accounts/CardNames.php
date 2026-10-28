<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Catalog\CatalogRepository;

/**
 * Names watchlist and portfolio rows from the PokéSearch catalog: one batched, cached
 * cardNames() read of the rows' distinct card ids; it never throws. degraded is its flag.
 */
final class CardNames
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, degraded: bool}
     */
    public static function attach(array $rows, CatalogRepository $catalog): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['card_id'] ?? null;
            if (is_string($id)) {
                $ids[$id] = $id;
            }
        }
        $names = $catalog->cardNames(array_values($ids));
        foreach ($rows as $i => $row) {
            $id = $row['card_id'] ?? null;
            $rows[$i]['card'] = is_string($id) ? ($names['cards'][$id] ?? null) : null;
        }

        // Compared, not copied, so the exported schema types meta.degraded as a boolean (as MoversController does).
        return ['rows' => $rows, 'degraded' => $names['degraded'] === true];
    }
}
