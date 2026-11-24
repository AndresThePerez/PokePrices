<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problems\PasswordConfirmationRequired;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * The password.confirm middleware, aliased under that name. Laravel's RequirePassword answers a JSON request with a
 * plain {"message": …} 423 and anything else with a redirect; here a session whose password confirmation is missing
 * or older than auth.password_timeout seconds gets the 423 problem password-confirmation-required and the route never
 * runs. The check is the framework's: the instant POST /user/confirm-password stored in this session.
 */
final class RequirePasswordConfirmation
{
    /** The framework's default when auth.password_timeout is not a positive number of seconds: three hours. */
    private const int DEFAULT_TIMEOUT = 10800;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);
        $age = Date::now()->unix() - (is_numeric($confirmedAt) ? (int) $confirmedAt : 0);
        if ($age > self::timeout()) {
            throw new PasswordConfirmationRequired;
        }

        return $next($request);
    }

    private static function timeout(): int
    {
        $timeout = config('auth.password_timeout');

        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : self::DEFAULT_TIMEOUT;
    }
}
