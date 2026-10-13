<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problems\DemoReadOnly as DemoReadOnlyProblem;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo account is read-only on the server (plan P4-i1 D8, R209): when the signed-in user is the demo, every
 * request whose method is not GET, HEAD or OPTIONS answers 403 demo-read-only before the route runs, except sign-out
 * (the route named logout). On the /v1 account group and appended to the web group, so every Fortify write is covered
 * too; as group middleware it runs before the guest routes' JsonGuest, so the demo gets 403, not 409.
 */
final class DemoReadOnly
{
    private const array READS = ['GET', 'HEAD', 'OPTIONS'];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $signOut = $route instanceof Route && $route->getName() === 'logout';
        if (! $signOut && ! in_array($request->getMethod(), self::READS, true)) {
            $user = $request->user();
            if ($user instanceof User && $user->is_demo) {
                throw new DemoReadOnlyProblem;
            }
        }

        return $next($request);
    }
}
