<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * The enumeration-safe answer to POST /forgot-password: the same 200 body whatever the broker says
 * (sent, unknown user, throttled), so a reset request never reveals whether an account exists. A malformed email
 * still fails validation (422) before the broker runs.
 */
final class ResetLinkAnswer implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const string MESSAGE = 'If that address has a PokéPrices account, a reset link is on its way.';

    /** @param Request $request */
    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse(['message' => self::MESSAGE], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
