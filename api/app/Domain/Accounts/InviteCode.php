<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Validation\ValidationException;

/**
 * Invite-only registration. The code is configuration (INVITE_CODE), never a committed value.
 *
 * A submitted value that is not a non-empty string fails without a comparison; otherwise hash_equals against the
 * configured code, and only a non-empty configured string can match. The check runs before anything else of the
 * registration, so a request without a valid code learns nothing about which emails exist.
 */
final class InviteCode
{
    public const string CONFIG = 'accounts.invite_code';

    public const string FIELD = 'invite_code';

    public const string MESSAGE = 'The invite code is not valid.';

    public static function matches(mixed $submitted): bool
    {
        $configured = config(self::CONFIG);

        return is_string($submitted) && $submitted !== ''
            && is_string($configured) && $configured !== ''
            && hash_equals($configured, $submitted);
    }

    /** @throws ValidationException with exactly errors.invite_code when the submitted value does not match */
    public static function check(mixed $submitted): void
    {
        if (! self::matches($submitted)) {
            throw ValidationException::withMessages([self::FIELD => [self::MESSAGE]]);
        }
    }
}
