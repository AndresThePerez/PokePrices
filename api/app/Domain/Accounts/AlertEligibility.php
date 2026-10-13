<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;

/**
 * Verification before alerts (plan P4-i1 D15): only a verified, non-demo account may create alerts. The Gate
 * create-alerts delegates here; P5 attaches it to alert creation, and no P4 route uses it.
 */
final class AlertEligibility
{
    public const string GATE = 'create-alerts';

    public static function allows(User $user): bool
    {
        return $user->hasVerifiedEmail() && ! $user->is_demo;
    }
}
