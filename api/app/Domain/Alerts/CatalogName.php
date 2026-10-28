<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * A catalog name (or the "Card <card_id>" fallback) as a notification shows it (plan Q1-i3 T01, sec B1, HG-40 #14).
 * The name is PokéSearch's text, not template: email drops its control characters; Discord also backslash-escapes
 * the markdown metacharacters so the name cannot render a link, a heading, a list, a code span or a mention. Only the
 * label goes through here; template text, money, URLs and the honesty lines never do.
 */
final class CatalogName
{
    /** Every control character (\p{Cc}: C0 incl. CR, LF, TAB, ESC, BEL; DEL; C1) removed; nothing else changes. */
    public static function forEmail(string $name): string
    {
        return preg_replace('/\p{Cc}+/u', '', $name)
            // Not UTF-8 (the catalog sends JSON, so not expected): drop the single-byte controls.
            ?? (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
    }

    /**
     * forEmail(), then a backslash before each of \ * _ ~ ` | < > [ ] ( ) # @ :, before a leading '-' or '+', and
     * before the '.' of a leading "<digits>.". Leading horizontal whitespace (spaces, no-break spaces) does not hide
     * the marker: it is kept and the marker after it is escaped. A '-' anywhere else stays as it is ("base1-4").
     */
    public static function forDiscord(string $name): string
    {
        $escaped = (string) preg_replace('/[\\\\*_~`|<>\[\]()#@:]/', '\\\\$0', self::forEmail($name));

        $markers = ['/^(\h*)([-+])/' => '$1\\\\$2', '/^(\h*)(\d+)\./' => '$1$2\\\\.'];
        foreach ($markers as $pattern => $replacement) {
            // Not UTF-8: the byte-wise pattern still escapes a marker after spaces.
            $escaped = preg_replace($pattern.'u', $replacement, $escaped) ?? (string) preg_replace($pattern, $replacement, $escaped);
        }

        return $escaped;
    }
}
