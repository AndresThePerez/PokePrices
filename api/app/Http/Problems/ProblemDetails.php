<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Stringable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** RFC 9457 problem details for every PokéPrices API error (R16). */
final class ProblemDetails
{
    public const string BASE = 'https://pokeprices.andrestheperez.com/problems/';

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException && $e->status === 429) {
            // Fortify's lockout throws a ValidationException with status 429 (plan P4-i1 D4): it is a 429, never a 422.
            $retryAfter = $e->response?->headers->get('Retry-After');

            return self::withHeaders(self::response(429, 'too-many-requests', 'Too Many Requests', $request), is_string($retryAfter) ? ['Retry-After' => $retryAfter] : []);
        }
        if ($e instanceof ValidationException) {
            return self::response(422, 'validation-failed', 'Validation Failed', $request, null, ['errors' => $e->errors()]);
        }
        if ($e instanceof AuthenticationException) {
            return self::response(401, 'unauthenticated', 'Unauthenticated', $request);
        }
        if ($e instanceof HttpExceptionInterface) {
            return self::withHeaders(self::fromHttpException($e, $request), $e->getHeaders());
        }

        return self::response(500, 'server-error', 'Server Error', $request);
    }

    /** The P1 slugs first (F-P1-027), the P4 demo slugs, the P5 alert slugs, then the P0 404/405 slugs, the P4 409/419 slugs, then http-<n> for every other status. */
    private static function fromHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();

        return match (true) {
            $e instanceof UpstreamUnavailable => self::response(503, 'upstream-unavailable', 'Upstream Unavailable', $request),
            $e instanceof UpstreamContract => self::response(502, 'upstream-contract', 'Upstream Contract Violation', $request),
            $e instanceof CardNotFound => self::response(404, 'card-not-found', 'Card Not Found', $request, null, ['catalog_missing' => $e->catalogMissing]),
            $e instanceof DemoReadOnly => self::response(403, 'demo-read-only', 'Demo Account Is Read-Only', $request),
            $e instanceof DemoUnavailable => self::response(404, 'demo-unavailable', 'Demo Account Unavailable', $request),
            $e instanceof EmailNotVerified => self::response(403, 'email-not-verified', 'Verify Your Email First', $request),
            $e instanceof WebhookNotFound => self::response(404, 'webhook-not-found', 'Webhook Not Found', $request),
            $e instanceof WebhookDisabled => self::response(409, 'webhook-disabled', 'Webhook Turned Off', $request),
            $e instanceof NotFoundHttpException => self::response(404, 'not-found', 'Not Found', $request),
            $e instanceof MethodNotAllowedHttpException => self::response(405, 'method-not-allowed', 'Method Not Allowed', $request),
            $e instanceof AlreadySignedIn => self::response(409, 'already-signed-in', 'Already Signed In', $request),
            // A CSRF failure reaches the renderer as HttpException(419): prepareException runs first (plan P4-i1 D4).
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
