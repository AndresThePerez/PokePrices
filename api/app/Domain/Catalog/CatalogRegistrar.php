<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registers the catalog's Http::pokesearch() macro; AppServiceProvider only delegates here (plan T34).
 *
 * Timeouts are per attempt: connect 1 s, total 2 s. R130 (F-P1-R1-16): retry once, 100 ms later, and only
 * when the first attempt failed fast (connection refused, or HTTP 502/503/504 within 0.9 s); never after
 * a timeout and never on a 4xx, so a failing call stays near the 2 s readiness bound.
 */
final class CatalogRegistrar
{
    /** An attempt that failed within this many seconds failed fast and may be retried once. */
    public const float FAST_FAILURE_SECONDS = 0.9;

    /** cURL's CURLE_COULDNT_CONNECT: the peer refused (or could not be routed to) before any timeout. */
    private const int CURL_COULDNT_CONNECT = 7;

    private const array RETRYABLE_STATUSES = [502, 503, 504];

    public static function register(): void
    {
        // Macroable rebinds the closure to the Http factory, so the class is named, never self::.
        Http::macro('pokesearch', fn (): PendingRequest => CatalogRegistrar::pendingRequest());
    }

    public static function pendingRequest(): PendingRequest
    {
        $attemptStartedAt = hrtime(true);

        $request = Http::baseUrl(self::configString('services.pokesearch.url'))
            ->acceptJson()
            ->connectTimeout(1)
            ->timeout(2)
            ->withUserAgent('pokeprices-api/'.self::configString('app.version'))
            ->beforeSending(function () use (&$attemptStartedAt): void {
                $attemptStartedAt = hrtime(true);
            })
            ->retry(2, 100, when: function (Throwable $e) use (&$attemptStartedAt): bool {
                return self::failedFast($e, (hrtime(true) - $attemptStartedAt) / 1e9);
            }, throw: false);

        $requestId = Context::get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $request->withHeader('X-Request-Id', $requestId);
        }

        $apiKey = config('services.pokesearch.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $request->withHeader('X-Api-Key', $apiKey);
        }

        return $request;
    }

    /** The R130 retry predicate: a connection refusal or a 502/503/504, each only when it came back fast. */
    public static function failedFast(Throwable $e, float $elapsedSeconds): bool
    {
        if ($elapsedSeconds >= self::FAST_FAILURE_SECONDS) {
            return false;
        }

        if ($e instanceof RequestException) {
            return in_array($e->response->status(), self::RETRYABLE_STATUSES, true);
        }

        if ($e instanceof ConnectionException) {
            return self::curlErrno($e) === self::CURL_COULDNT_CONNECT;
        }

        return false;
    }

    /** The cURL error number behind a connection failure; Guzzle carries it only in the message ("cURL error 7: ..."). */
    private static function curlErrno(ConnectionException $e): ?int
    {
        return preg_match('/\AcURL error (\d+):/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
    }

    private static function configString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
