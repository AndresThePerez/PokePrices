<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;

/**
 * Verification before alerts: only a verified, non-demo account may create alerts. The Gate
 * create-alerts delegates here; the alert-creating routes attach it, and no account route uses it.
 */
final class AlertEligibility
{
    public const string GATE = 'create-alerts';

    /** allows() as a SQL predicate on the users table aliased u (the alert dispatch's joins). */
    public const string SQL = 'u.email_verified_at IS NOT NULL AND NOT u.is_demo';

    public static function allows(User $user): bool
    {
        return $user->hasVerifiedEmail() && ! $user->is_demo;
    }
}
