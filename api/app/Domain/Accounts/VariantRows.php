<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Pricing\PriceColumns;

/**
 * The shared shape of a watchlist or portfolio read: each row's variant fields from card_variants
 * and its latest close from price_latest, joined in the same statement. card_variants is append-only, so the join
 * always finds the variant; should it not, the fields come from the key itself (a card id holds no colon).
 */
final class VariantRows
{
    /**
     * The select list and joins for the list table aliased $alias (w or h): variant fields, then every price_latest
     * column (PriceColumns::LATEST) as price_<column> for the HTTP layer's PriceBlock::fromRow().
     */
    public static function selectAndJoin(string $alias): string
    {
        $price = implode(', ', array_map(fn (string $c): string => "p.{$c} AS price_{$c}", PriceColumns::LATEST));

        return <<<SQL
            {$alias}.variant_key,
            COALESCE(v.card_id, split_part({$alias}.variant_key, ':', 1)) AS card_id,
            COALESCE(v.finish, split_part({$alias}.variant_key, ':', 2)) AS finish,
            COALESCE(v.edition, split_part({$alias}.variant_key, ':', 3)) AS edition,
            COALESCE(v.treatment, split_part({$alias}.variant_key, ':', 4)) AS treatment,
            {$price}
            SQL;
    }

    /** The joins after FROM <table> <alias>; the one binding is the price source. */
    public static function joins(string $alias): string
    {
        return <<<SQL
            LEFT JOIN card_variants v ON v.variant_key = {$alias}.variant_key
            LEFT JOIN price_latest p ON p.variant_key = {$alias}.variant_key AND p.source = ?
            SQL;
    }

    /** Ordered by card id, then variant key, byte-wise. */
    public static function orderBy(string $alias): string
    {
        return "ORDER BY COALESCE(v.card_id, split_part({$alias}.variant_key, ':', 1)) COLLATE \"C\", {$alias}.variant_key COLLATE \"C\"";
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function arrays(array $rows): array
    {
        $arrays = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $arrays[] = get_object_vars($row);
            }
        }

        return $arrays;
    }
}
