<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * The card-number key both sides of the map compare on. The same rule runs on
 * the dataset's `number` and on TCGplayer's `Number` value: the printed total after `/` is dropped, a letter prefix
 * (RC, SV, TG, GG, SWSH, ...) and a letter suffix (98a) are kept, and leading zeros leave the numeric part only.
 * Tokens without digits (`!`, `?`, Unown letters, Mew `R`) are kept as they are, so `!` and `?` never merge.
 */
final class CardNumber
{
    /** Prefixes whose cards TCGplayer lists in a set's secondary group (Radiant Collection, Shiny Vault, galleries). */
    private const array SECONDARY_PREFIXES = ['RC', 'SV', 'TG', 'GG'];

    private const string SHAPE = '/\A([A-Za-z]*)(\d+)([A-Za-z]*)\z/';

    public static function normalise(string $raw): string
    {
        $number = trim(explode('/', trim($raw), 2)[0]);
        if (preg_match(self::SHAPE, $number, $m) !== 1) {
            return $number;
        }
        $digits = ltrim($m[2], '0');

        return $m[1].($digits === '' ? '0' : $digits).$m[3];
    }

    /** True when the number carries one of the RC/SV/TG/GG prefixes that route to the secondary group. */
    public static function isSecondary(string $raw): bool
    {
        $number = self::normalise($raw);
        if (preg_match(self::SHAPE, $number, $m) !== 1) {
            return false;
        }

        return in_array(strtoupper($m[1]), self::SECONDARY_PREFIXES, true);
    }

    /**
     * True for `_A1`-style dataset ids (cel25c-15_A1 … 15_A4, cel25c-17_A): several cards share one printed number,
     * so a number match alone is not enough and the name has to agree too.
     */
    public static function comparesOnName(string $cardId): bool
    {
        return preg_match('/_A\d*\z/', $cardId) === 1;
    }
}
