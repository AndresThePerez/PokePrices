<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\CachePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account answers are never stored by a cache: Cache-Control no-store, private and
 * no ETag. On the /v1 account group and appended to the web group, so Fortify's two-factor secret GETs carry it too.
 * Public /v1 reads never pass it. FortifyServiceProvider puts it first in the middleware priority, ahead of
 * ThrottleBeforeSession, so a throttled (429) account or demo-session answer is no-store too.
 */
final class NoStore
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', CachePolicy::ACCOUNT);
        $response->headers->remove('ETag');

        return $response;
    }
}
