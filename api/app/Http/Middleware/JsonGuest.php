<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problems\AlreadySignedIn;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guest middleware, aliased as guest. Laravel's RedirectIfAuthenticated always
 * redirects, and fetch would follow a 302 into the SPA's index.html; here a signed-in person gets the 409 problem
 * already-signed-in and the route never runs. A guest passes.
 */
final class JsonGuest
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards === [] ? [null] : $guards as $guard) {
            if (Auth::guard($guard)->check()) {
                throw new AlreadySignedIn;
            }
        }

        return $next($request);
    }
}
