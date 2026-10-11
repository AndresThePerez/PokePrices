<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
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
        if ($e instanceof ValidationException) {
            return self::response(422, 'validation-failed', 'Validation Failed', $request, null, ['errors' => $e->errors()]);
        }
        if ($e instanceof NotFoundHttpException) {
            return self::response(404, 'not-found', 'Not Found', $request);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return self::response(405, 'method-not-allowed', 'Method Not Allowed', $request);
        }
        if ($e instanceof AuthenticationException) {
            return self::response(401, 'unauthenticated', 'Unauthenticated', $request);
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::response($status, 'http-'.$status, Response::$statusTexts[$status] ?? 'Error', $request);
        }

        return self::response(500, 'server-error', 'Server Error', $request);
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
