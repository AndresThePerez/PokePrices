<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validators and cache policy for public reads (plan T44, review-1 recs 5 and 10), alias conditional-get.
 *
 * A 200 to a GET or HEAD gets a strong ETag over its body (xxh128) and the route's Cache-Control policy, named
 * as the middleware parameter: conditional-get (or conditional-get:search) sends public, max-age=60,
 * s-maxage=300; conditional-get:card sends public, max-age=60, stale-while-revalidate=300 (T43, review-1
 * rec 12); conditional-get:history sends public, max-age=300, stale-while-revalidate=600 for the daily price reads
 * cards.prices and movers (P2-i1 T11 and T12, review F9), whose bodies change at most once per ingested day. A matching If-None-Match turns it into a 304 that keeps the ETag and drops the body. A body whose
 * meta.degraded is true is a stale copy: it gets Cache-Control no-store, no ETag, and is never answered 304.
 * Any other status passes through untouched. The ETag only holds because these bodies are deterministic: no
 * request id and no upstream timing in a 200.
 */
final class ConditionalGet
{
    public const string CACHE_CONTROL = 'public, max-age=60, s-maxage=300';

    public const string CARD_CACHE_CONTROL = 'public, max-age=60, stale-while-revalidate=300';

    public const string HISTORY_CACHE_CONTROL = 'public, max-age=300, stale-while-revalidate=600';

    /** The Cache-Control of a fresh 200, by the policy name a route passes as the middleware parameter. */
    public const array POLICIES = ['search' => self::CACHE_CONTROL, 'card' => self::CARD_CACHE_CONTROL, 'history' => self::HISTORY_CACHE_CONTROL];

    /** @throws LogicException when the route names a policy that does not exist */
    public function handle(Request $request, Closure $next, string $policy = 'search'): Response
    {
        $cacheControl = self::POLICIES[$policy] ?? throw new LogicException("Unknown conditional-get policy {$policy}.");
        $response = $next($request);
        if (! $response instanceof Response || $response->getStatusCode() !== 200 || ! $request->isMethodCacheable()) {
            return $response;
        }

        if (self::degraded($response)) {
            $response->headers->remove('ETag');
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $body = self::body($response);
        if ($body === null) {
            return $response;
        }

        $response->headers->set('Cache-Control', $cacheControl);
        $response->setEtag(hash('xxh128', $body));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The representation the ETag covers: the body a GET carries. For HEAD the router has already emptied the
     * content (Response::prepare), so a JSON response is hashed over its encoded data, which prepare leaves in
     * place and which is byte for byte the GET's body. Any other response with an empty body (every non-JSON
     * HEAD among them) has nothing to hash and gets no validator (null).
     */
    private static function body(Response $response): ?string
    {
        if ($response instanceof SymfonyJsonResponse) {
            $data = (new ReflectionProperty(SymfonyJsonResponse::class, 'data'))->getValue($response);

            return is_string($data) ? $data : null;
        }
        $content = $response->getContent();

        return is_string($content) && $content !== '' ? $content : null;
    }

    private static function degraded(Response $response): bool
    {
        $body = $response instanceof JsonResponse
            ? $response->getData(true)
            : json_decode((string) $response->getContent(), true);
        $meta = is_array($body) ? ($body['meta'] ?? null) : null;

        return is_array($meta) && ($meta['degraded'] ?? false) === true;
    }
}
