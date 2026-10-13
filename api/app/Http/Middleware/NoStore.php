<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account answers are never stored by a cache (plan P4-i1 D1, review F-P4-09): Cache-Control no-store, private and
 * no ETag. On the /v1 account group and appended to the web group, so Fortify's two-factor secret GETs carry it too.
 * Public /v1 reads never pass it.
 */
final class NoStore
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('ETag');

        return $response;
    }
}
