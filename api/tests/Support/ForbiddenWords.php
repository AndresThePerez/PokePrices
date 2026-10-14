<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The PHP copy of the forbidden-word list (web/src/test/fixtures.ts FORBIDDEN_WORDS; P4 D14, plan P5-i1 D11).
 * Human text (UI, email subject and body, Discord content) runs all eight patterns. JSON payloads run the seven word
 * patterns without /20:05/: their timestamps are the data's own RFC 3339 as_of (Amendment 1, review A06).
 */
final class ForbiddenWords
{
    /** The seven word patterns. */
    public const array WORDS = [
        '/\blive\b/i',
        '/real-?time/i',
        '/as of today/i',
        '/\btoday\b/i',
        '/\bnow\b/i',
        '/\b1D\b/i',
        '/\b24 ?h/i',
    ];

    /** The clock-time pattern, for human text only. */
    public const string CLOCK = '/20:05/';

    /**
     * The payload patterns (the seven word patterns) that match $text.
     *
     * @return list<string>
     */
    public static function payloadMatches(string $text): array
    {
        return array_values(array_filter(self::WORDS, fn (string $p): bool => preg_match($p, $text) === 1));
    }

    /**
     * The human-text patterns (all eight) that match $text.
     *
     * @return list<string>
     */
    public static function textMatches(string $text): array
    {
        return array_values(array_filter([...self::WORDS, self::CLOCK], fn (string $p): bool => preg_match($p, $text) === 1));
    }
}
