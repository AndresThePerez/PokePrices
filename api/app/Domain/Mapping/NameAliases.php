<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Normalizer;

/**
 * Card-name agreement between a PokéSearch card and a TCGplayer product base name. Both names are folded the same way: accents dropped, case ignored, `★` read as `Star`, `♂`/`♀` as
 * `M`/`F`, the Impostor/Imposter and Drowzee/Drowsee spellings merged, and the type letters before `Energy`
 * (R W G L P F D M) read as the type name. Delta is one-directional: a card marked `δ` may meet a product without
 * the tag (Eevee - 068/113 against Eevee δ), but a `(Delta Species)` product never stands for a plain card.
 */
final class NameAliases
{
    private const array ENERGY_LETTERS = [
        'r' => 'fire',
        'w' => 'water',
        'g' => 'grass',
        'l' => 'lightning',
        'p' => 'psychic',
        'f' => 'fighting',
        'd' => 'darkness',
        'm' => 'metal',
    ];

    private const array SPELLINGS = [
        '/\bimposter\b/u' => 'impostor',
        '/\bdrowsee\b/u' => 'drowzee',
    ];

    public static function equal(string $cardName, string $productBaseName): bool
    {
        [$card, $cardDelta] = self::canonical($cardName);
        [$product, $productDelta] = self::canonical($productBaseName);

        if ($productDelta && ! $cardDelta) {
            return false;
        }

        return $card === $product;
    }

    /**
     * The folded name without its delta marker, and whether a delta marker (`δ` or a `(Delta Species…)` tag) was
     * present.
     *
     * @return array{string, bool}
     */
    private static function canonical(string $name): array
    {
        $folded = Normalizer::normalize($name, Normalizer::FORM_KD);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $folded === false ? $name : $folded);
        $s = str_replace(['’', '‘', '★', '♂', '♀'], ["'", "'", ' star ', ' m ', ' f '], $s);
        $s = mb_strtolower($s);

        $delta = false;
        $s = (string) preg_replace('/\(\s*delta species\b[^)]*\)/u', ' ', $s, -1, $tags);
        if ($tags > 0 || str_contains($s, 'δ')) {
            $delta = true;
            $s = str_replace('δ', ' ', $s);
        }

        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        foreach (self::SPELLINGS as $pattern => $canonical) {
            $s = (string) preg_replace($pattern, $canonical, $s);
        }
        $s = (string) preg_replace_callback(
            '/\b([rwglpfdm]) energy\b/u',
            fn (array $m): string => self::ENERGY_LETTERS[$m[1]].' energy',
            $s,
        );

        return [$s, $delta];
    }
}
