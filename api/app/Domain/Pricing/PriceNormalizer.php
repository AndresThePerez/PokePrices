<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use UnexpectedValueException;

/**
 * Money conversion and normalization of decoded price rows.
 *
 * money() is the one place a decoded JSON number becomes a price: `sprintf('%.2f')`, accepted only when the string
 * reads back as the same float. After it every price is a decimal string and all arithmetic happens in SQL numeric.
 * normalize() joins (productId, subTypeName) to the current product map; an unmapped row is counted, never kept.
 */
final class PriceNormalizer
{
    /** A value at or above this does not fit numeric(12,2). */
    private const float MAX_EXCLUSIVE = 10000000000.0;

    /**
     * Converts a decoded JSON price to its two-decimal string, or null for a JSON null.
     *
     * @throws UnexpectedValueException when the value is not a JSON number or null, is negative (a negative zero
     *                                  included), is 10000000000 or more, or does not survive the round trip
     */
    public static function money(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $f = (float) $value;
        } elseif (is_float($value)) {
            $f = $value;
        } else {
            throw new UnexpectedValueException('not a JSON number or null');
        }

        $string = sprintf('%.2f', $f);
        // sprintf drops the sign of -0.0; fdiv(1, -0.0) is -INF.
        if ($f < 0 || ($f === 0.0 && fdiv(1.0, $f) < 0)) {
            throw new UnexpectedValueException('negative');
        }
        if ($f >= self::MAX_EXCLUSIVE) {
            throw new UnexpectedValueException('10000000000 or more');
        }
        if ((float) $string !== $f) {
            throw new UnexpectedValueException('more than two decimals');
        }

        return $string;
    }

    /**
     * Joins de-duplicated rows to the map. null_market counts the mapped rows whose market is null: they are stored
     * raw but are no observation (D1).
     *
     * @param  list<array{productId: int, subTypeName: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>  $rows
     * @param  array<string, array{variant_key: string, series_epoch: int}>  $map  keyed "<productId>|<subTypeName>"
     * @return array{points: list<array{variant_key: string, series_epoch: int, source_product_id: int, source_subtype: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>, mapped: int, unmapped: int, null_market: int}
     */
    public static function normalize(array $rows, array $map): array
    {
        $points = [];
        $unmapped = 0;
        $nullMarket = 0;
        foreach ($rows as $row) {
            $target = $map[$row['productId'].'|'.$row['subTypeName']] ?? null;
            if ($target === null) {
                $unmapped++;

                continue;
            }
            if ($row['market'] === null) {
                $nullMarket++;
            }
            $points[] = [
                'variant_key' => $target['variant_key'],
                'series_epoch' => $target['series_epoch'],
                'source_product_id' => $row['productId'],
                'source_subtype' => $row['subTypeName'],
                'market' => $row['market'],
                'low' => $row['low'],
                'mid' => $row['mid'],
                'high' => $row['high'],
                'direct_low' => $row['direct_low'],
            ];
        }

        return ['points' => $points, 'mapped' => count($points), 'unmapped' => $unmapped, 'null_market' => $nullMarket];
    }
}
