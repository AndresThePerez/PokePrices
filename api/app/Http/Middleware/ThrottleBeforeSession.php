<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * A ThrottleRequests that runs before any session starts (plan P4-i1 D6, D7, review F-P4-07). The framework's
 * middleware priority would sort a plain throttle after StartSession and the authenticators, so an anonymous request
 * could start (and store) a session before being counted. FortifyServiceProvider puts this class first in the
 * middleware priority, ahead of Sanctum's EnsureFrontendRequestsAreStateful.
 *
 * Used as ThrottleBeforeSession::class.':account-guest' on the /v1 account group, /sanctum/csrf-cookie,
 * /forgot-password and /reset-password.
 */
final class ThrottleBeforeSession extends ThrottleRequests {}
