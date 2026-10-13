<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\AccountEmail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one email normalisation step on Fortify's POST routes /register, /login, /forgot-password, /reset-password and
 * /email/verification-notification (plan P4-i1 D1, review F-P4-04): a string email is trimmed and lower-cased
 * before validation and the password broker; a non-string email is left for validation to reject (422).
 */
final class NormalizeAccountEmail
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has(AccountEmail::FIELD)) {
            $request->merge([AccountEmail::FIELD => AccountEmail::normalize($request->input(AccountEmail::FIELD))]);
        }

        return $next($request);
    }
}
