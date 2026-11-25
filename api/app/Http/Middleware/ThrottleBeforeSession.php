<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Accounts\AccountRateLimits;
use App\Domain\Accounts\SignedInSession;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
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
 * Used as ThrottleBeforeSession::class.':account-guest' on the /v1 account group, on every Fortify route behind
 * Fortify's sign-in middleware, and on /sanctum/csrf-cookie, /forgot-password and /reset-password.
 *
 * On /sanctum/csrf-cookie, /forgot-password and /reset-password every request is counted per client address, signed
 * in or not.
 *
 * On the routes that need a signed-in person (the /v1 account group and Fortify's signed-in routes) account-guest
 * counts per address only requests without a signed-in session (App\Domain\Accounts\SignedInSession: one cookie
 * decryption and one primary-key read, no session started). A signed-in request is counted here too, at the account
 * tier's number, in a bucket keyed by the user and the client address: the first-party check, the CSRF check and the
 * authenticator all answer before the account tier, so a request they refuse would otherwise be counted by nothing.
 * That bucket is one for the /v1 account group and another for Fortify's signed-in routes; neither is the guest's
 * bucket or the account tier's own.
 *
 * A request without a signed-in session to one of Fortify's signed-in routes is answered with the sign-in check's 401
 * right after it is counted, before a session starts, so it stores no sessions row; one that carries the guard's
 * remember-me cookie goes on to the sign-in check, which may sign it in from that cookie.
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
        $route = $request->route();
        $middleware = $route instanceof Route ? $route->gatherMiddleware() : [];
        $group = match (true) {
            in_array(self::ACCOUNT, $middleware, true) => 'account:',
            in_array(self::fortifySignIn(), $middleware, true) => 'fortify:',
            default => null,
        };
        $userId = $maxAttempts === self::GUEST && $group !== null ? SignedInSession::userId($request) : null;
        if ($userId === null) {
            // The arguments go on as given: ThrottleRequests reads a named limiter only when it gets exactly three.
            $arguments = func_get_args();
            if ($maxAttempts === self::GUEST && $group === 'fortify:' && $this->answersBeforeSession($request)) {
                $arguments[1] = static fn (): never => throw new AuthenticationException('Unauthenticated.', [self::fortifyGuard()]);
            }

            return parent::handle(...$arguments);
        }

        return $this->handleRequest($request, $next, [(object) [
            'key' => md5(self::GUEST.':signed-in:'.($group === 'account:' ? $group : '').$userId.'|'.$request->ip()),
            'maxAttempts' => AccountRateLimits::tiers(config(AccountRateLimits::CONFIG))['account'],
            'decaySeconds' => 60,
            'afterCallback' => null,
            'responseCallback' => null,
        ]]);
    }

    /** The sign-in middleware Fortify puts on its signed-in routes, as the route list holds it. */
    public static function fortifySignIn(): string
    {
        $middleware = config('fortify.auth_middleware', 'auth');

        return (is_string($middleware) ? $middleware : 'auth').':'.self::fortifyGuard();
    }

    private static function fortifyGuard(): string
    {
        $guard = config('fortify.guard');

        return is_string($guard) ? $guard : 'web';
    }

    /**
     * Whether a request without a signed-in session can be refused here: only with sessions in the database (the only
     * store whose sign-in this class can read) and without the guard's remember-me cookie.
     */
    private function answersBeforeSession(Request $request): bool
    {
        $recaller = 'remember_'.self::fortifyGuard().'_'.sha1(SessionGuard::class);

        return config('session.driver') === 'database' && ! $request->cookies->has($recaller);
    }
}
