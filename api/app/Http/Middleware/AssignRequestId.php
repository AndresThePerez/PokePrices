<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** One request id per request: nginx passes the client's or its own; Laravel validates or replaces it (F-P0-064). */
final class AssignRequestId
{
    private const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $id = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1 ? $incoming : (string) Str::ulid();
        $request->headers->set('X-Request-Id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        $response->headers->set('X-PokePrices-Origin', 'api');

        return $response;
    }
}
