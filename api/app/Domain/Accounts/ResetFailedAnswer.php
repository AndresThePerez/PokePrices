<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;

/**
 * The one answer to a failed POST /reset-password: always the invalid-token message, whatever the
 * broker said. Fortify echoes the broker status (INVALID_USER for an unregistered email, INVALID_TOKEN otherwise), so
 * answering with the status would tell a registered email from an unregistered one. The status is ignored on purpose.
 */
final class ResetFailedAnswer implements FailedPasswordResetResponse
{
    /** @param Request $request */
    public function toResponse(mixed $request): never
    {
        throw ValidationException::withMessages(['email' => [trans(Password::INVALID_TOKEN)]]);
    }
}
