<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * A variant in words, the server-side twin of the web's variantLabel (web/src/format.ts): the finish,
 * edition and treatment of the variant key (<card_id>:<finish>:<edition>:<treatment>), '-' and '' meaning none,
 * joined by ', '; the key itself when none is left.
 */
final class VariantLabel
{
    public static function of(string $variantKey): string
    {
        $parts = array_slice(explode(':', $variantKey), 1, 3);
        $words = array_values(array_filter($parts, fn (string $part): bool => $part !== '' && $part !== '-'));

        return $words === [] ? $variantKey : implode(', ', $words);
    }
}
