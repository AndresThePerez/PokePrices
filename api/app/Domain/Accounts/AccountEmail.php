<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * The one email normalisation step: a string email is trimmed and lower-cased before
 * validation, the password broker and the limiter key, so letter case never splits an account or a throttle bucket.
 * Anything that is not a string is returned unchanged, for validation to reject.
 */
final class AccountEmail
{
    public const string FIELD = 'email';

    public static function normalize(mixed $email): mixed
    {
        return is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : $email;
    }

    /** The normalised email for a limiter key: '' for anything that is not a string (D7). */
    public static function key(mixed $email): string
    {
        $normalized = self::normalize($email);

        return is_string($normalized) ? $normalized : '';
    }
}
