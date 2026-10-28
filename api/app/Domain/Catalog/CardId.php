<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * The card id rule PokéSearch enforces (^[A-Za-z0-9][A-Za-z0-9._!?-]{0,127}$). An id that fails it never
 * reaches PokéSearch; callers map the InvalidArgumentException to a 404.
 */
final class CardId
{
    /** \A and \z, not ^ and $: PCRE's $ also matches before a trailing newline. */
    public const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._!?-]{0,127}\z/';

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /** @throws InvalidArgumentException when the id does not match the PokéSearch card id pattern */
    public static function assertValid(string $id): void
    {
        if (! self::isValid($id)) {
            throw new InvalidArgumentException('Card id does not match the PokéSearch card id pattern.');
        }
    }
}
