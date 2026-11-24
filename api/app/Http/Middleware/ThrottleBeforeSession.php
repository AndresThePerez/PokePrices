<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\AccountRateLimits;
use App\Domain\Accounts\SignedInSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A ThrottleRequests that runs before any session starts. The framework's
 * middleware priority would sort a plain throttle after StartSession and the authenticators, so an anonymous request
 * could start (and store) a session before being counted. FortifyServiceProvider puts this class first in the
 * middleware priority, ahead of Sanctum's EnsureFrontendRequestsAreStateful.
 *
 * Used as ThrottleBeforeSession::class.':account-guest' on the /v1 account group, /sanctum/csrf-cookie,
 * /forgot-password and /reset-password.
 *
 * account-guest counts only requests without a signed-in session (App\Domain\Accounts\SignedInSession: one cookie
 * decryption and one primary-key read, no session started). A signed-in request is counted here too, at the account
 * tier's number, in a bucket keyed by the user and the client address: the first-party check, the CSRF check and the
 * authenticator all answer before the account tier, so a request they refuse would otherwise be counted by nothing.
 * That bucket is one for the routes the account tier follows (the /v1 account group) and another for the routes it
 * does not; neither is the guest's bucket or the account tier's own.
 */
final class ThrottleBeforeSession extends ThrottleRequests
{
    private const string GUEST = 'account-guest';

    private const string ACCOUNT = 'throttle:account';

    /**
     * @param  Request  $request
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        $userId = $maxAttempts === self::GUEST ? SignedInSession::userId($request) : null;
        if ($userId === null) {
            return parent::handle(...func_get_args());
        }
        $route = $request->route();
        $group = $route instanceof Route && in_array(self::ACCOUNT, $route->gatherMiddleware(), true) ? 'account:' : '';

        return $this->handleRequest($request, $next, [(object) [
            'key' => md5(self::GUEST.':signed-in:'.$group.$userId.'|'.$request->ip()),
            'maxAttempts' => AccountRateLimits::tiers(config(AccountRateLimits::CONFIG))['account'],
            'decaySeconds' => 60,
            'afterCallback' => null,
            'responseCallback' => null,
        ]]);
    }
}
