<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\AlertEligibility;
use App\Http\Problems\EmailNotVerified;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verification before alerts (plan P5-i1 D9, attaching P4's D15 seam; System Design §7): unless the Gate
 * create-alerts allows the signed-in user (verified and not the demo), the request answers 403 email-not-verified
 * before the route runs. Only on the alert-creating routes; the demo meets DemoReadOnly (403 demo-read-only) first.
 */
final class RequireAlertEligibility
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::forUser($request->user())->allows(AlertEligibility::GATE)) {
            throw new EmailNotVerified;
        }

        return $next($request);
    }
}
