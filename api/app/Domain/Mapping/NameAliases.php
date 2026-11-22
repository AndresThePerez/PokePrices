<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Normalizer;

/**
 * Card-name agreement between a PokéSearch card and a TCGplayer product base name. Both names are folded the same way: accents dropped, case ignored, `★` read as `Star`, `♂`/`♀` as
 * `M`/`F`, the Impostor/Imposter and Drowzee/Drowsee spellings merged, and the type letters before `Energy`
 * (R W G L P F D M) read as the type name. Delta is one-directional: a card marked `δ` may meet a product without
 * the tag (Eevee - 068/113 against Eevee δ), but a `(Delta Species)` product never stands for a plain card. For energy
 * names only, a leading `Delta ` word is the same marker: the product `Delta Rainbow Energy` is the card `δ Rainbow
 * Energy` and never the plain `Rainbow Energy`. A name must read `Delta <name> Energy` as a whole for this, so sealed
 * products (`Delta Species Booster Pack`) and a bare `Delta Energy` keep the word.
 *
 * Three catalogue spellings meet the product's: the hyphen of an upper-case `-EX` or `-GX` suffix is a space
 * (`Charizard-GX` is `Charizard GX`, `Ash-Greninja-EX` is `Ash-Greninja EX`; `Porygon-Z` keeps its hyphen), the Prism
 * Star diamond `◇` reads as `Prism Star`, and an Unown letter in either bracket (`Unown [J]`, `Unown (J)`) is one
 * form. Unown letters stay apart; a plain `Unown` card, whose letter is its number (EX Unseen Forces), meets a
 * product that names a letter, but a lettered card never meets a product without one.
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

    /** The catalogue's hyphenated suffixes; only these two, upper case, at the end of a word. */
    private const string HYPHEN_SUFFIX = '/-(EX|GX)(?=\s|\z)/u';

    private const string UNOWN_LETTER = '/\Aunown [\[(]([a-z!?])[\])]\z/u';

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
        if ($card === $product) {
            return true;
        }

        return $card === 'unown' && preg_match('/\Aunown \[[a-z!?]\]\z/u', $product) === 1;
    }

    /**
     * The folded name without its delta marker, and whether a delta marker (`δ`, a `(Delta Species…)` tag, or a
     * leading `Delta` on a `Delta <name> Energy` name) was present.
     *
     * @return array{string, bool}
     */
    private static function canonical(string $name): array
    {
        $name = (string) preg_replace(self::HYPHEN_SUFFIX, ' $1', $name);
        $folded = Normalizer::normalize($name, Normalizer::FORM_KD);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $folded === false ? $name : $folded);
        $s = str_replace(['’', '‘', '★', '◇', '♂', '♀'], ["'", "'", ' star ', ' prism star ', ' m ', ' f '], $s);
        $s = mb_strtolower($s);

        $delta = false;
        $s = (string) preg_replace('/\(\s*delta species\b[^)]*\)/u', ' ', $s, -1, $tags);
        if ($tags > 0 || str_contains($s, 'δ')) {
            $delta = true;
            $s = str_replace('δ', ' ', $s);
        }

        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if (preg_match('/\Adelta (.+ energy)\z/u', $s, $m) === 1) {
            $delta = true;
            $s = $m[1];
        }
        foreach (self::SPELLINGS as $pattern => $canonical) {
            $s = (string) preg_replace($pattern, $canonical, $s);
        }
        $s = (string) preg_replace(self::UNOWN_LETTER, 'unown [$1]', $s);
        $s = (string) preg_replace_callback(
            '/\b([rwglpfdm]) energy\b/u',
            fn (array $m): string => self::ENERGY_LETTERS[$m[1]].' energy',
            $s,
        );

        return [$s, $delta];
    }
}
