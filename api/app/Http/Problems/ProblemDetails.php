<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use RedisException;
use Stringable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** RFC 9457 problem details for every PokéPrices API error. */
final class ProblemDetails
{
    public const string BASE = 'https://pokeprices.andrestheperez.com/problems/';

    /** Retry-After of the 503 a Redis outage answers, in seconds. */
    public const int STORE_RETRY_AFTER_SECONDS = 5;

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException && $e->status === 429) {
            // Fortify's lockout throws a ValidationException with status 429: it is a 429, never a 422.
            $retryAfter = $e->response?->headers->get('Retry-After');

            return self::withHeaders(self::response(429, 'too-many-requests', 'Too Many Requests', $request), is_string($retryAfter) ? ['Retry-After' => $retryAfter] : []);
        }
        if ($e instanceof ValidationException) {
            return self::response(422, 'validation-failed', 'Validation Failed', $request, null, ['errors' => (object) $e->errors()]);
        }
        if ($e instanceof AuthenticationException) {
            return self::response(401, 'unauthenticated', 'Unauthenticated', $request);
        }
        if ($e instanceof HttpExceptionInterface) {
            return self::withHeaders(self::fromHttpException($e, $request), $e->getHeaders());
        }
        if (self::isStoreOutage($e)) {
            // A Redis role (the rate limiters and locks, or the catalogue cache) did not answer: a 503 worth retrying,
            // never a bare 500. A limit that cannot be counted is refused, not waved through.
            return self::withHeaders(
                self::response(503, 'http-503', 'Service Unavailable', $request, 'A store this route counts or caches in did not answer.'),
                ['Retry-After' => (string) self::STORE_RETRY_AFTER_SECONDS],
            );
        }

        return self::response(500, 'server-error', 'Server Error', $request);
    }

    /** Whether the failure is, or was caused by, Redis not answering. */
    private static function isStoreOutage(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RedisException) {
                return true;
            }
        }

        return false;
    }

    /** A problem type first (it names its own slug, title and extra members), then the P0 404/405 slugs, the P4 419 slug and 429, then http-<n> for every other status. */
    private static function fromHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        if ($e instanceof ProblemType) {
            return self::response($status, $e->slug(), $e->title(), $request, null, $e->extra());
        }

        return match (true) {
            $e instanceof NotFoundHttpException => self::response(404, 'not-found', 'Not Found', $request),
            $e instanceof MethodNotAllowedHttpException => self::response(405, 'method-not-allowed', 'Method Not Allowed', $request),
            // A CSRF failure reaches the renderer as HttpException(419): prepareException runs first.
            $status === 419 => self::response(419, 'csrf-token-mismatch', 'CSRF Token Mismatch', $request),
            $status === 429 => self::response(429, 'too-many-requests', 'Too Many Requests', $request),
            default => self::response($status, 'http-'.$status, Response::$statusTexts[$status] ?? 'Error', $request),
        };
    }

    /**
     * The exception's own headers (Retry-After, X-RateLimit-*, Allow) travel with the problem; its Content-Type never does.
     *
     * @param  array<mixed>  $headers
     */
    private static function withHeaders(JsonResponse $response, array $headers): JsonResponse
    {
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $values = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_scalar($item) || $item instanceof Stringable) {
                    $values[] = (string) $item;
                }
            }
            if ($values !== [] && strcasecmp($name, 'Content-Type') !== 0) {
                $response->headers->set($name, $values);
            }
        }
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /** @param array<string, mixed> $extra */
    public static function response(int $status, string $slug, string $title, Request $request, ?string $detail = null, array $extra = []): JsonResponse
    {
        $requestId = Context::get('request_id');
        $body = [
            'type' => self::BASE.$slug,
            'title' => $title,
            'status' => $status,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => is_string($requestId) ? $requestId : '',
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JsonResponse($body + $extra, $status, ['Content-Type' => 'application/problem+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
