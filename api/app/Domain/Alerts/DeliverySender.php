<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One attempt of one claimed delivery (plan P5-i1 D7; channel webhook here, T03 adds discord and T06 email).
 *
 * send() acts only on a row that is still pending AND whose next_attempt_at is the lease its claim set (compare and
 * set), so a delivered or failed row, or a row another sweep re-claimed, is never sent again. A row whose endpoint
 * is gone or turned off fails with no_channel and no request. Otherwise it makes one attempt: the address guard
 * (D6), then one signed POST (D5) pinned to the approved address, redirects off, 2 s connect and 5 s total
 * timeouts, at most 64 KiB of the response read. The UPDATE that records the outcome is the one that raises
 * attempts, under the same compare and set.
 *
 * Containment (review A05, A08): everything after loading the row runs inside catch (Throwable). An exception is
 * recorded as last_error internal (retryable, so it still ends failed after the last attempt) and logged as its
 * class short name only: never rethrown, never reported, its message and trace never logged. No log line carries a
 * URL, url_hint, secret, signature, body or email address.
 */
final class DeliverySender
{
    public const string USER_AGENT = 'PokePrices-Webhooks/1';

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly RetryPolicy $retry,
    ) {}

    /**
     * @return array{outcome: 'delivered'|'retry'|'failed'|'skipped', error: string|null}
     */
    public function send(int $deliveryId, CarbonImmutable $lease): array
    {
        $leaseText = self::timestamp($lease);
        $delivery = Delivery::query()
            ->whereKey($deliveryId)
            ->where('status', 'pending')
            ->whereRaw('next_attempt_at = ?::timestamptz', [$leaseText])
            ->first();
        if (! $delivery instanceof Delivery) {
            return ['outcome' => 'skipped', 'error' => null];
        }

        $started = hrtime(true);
        $status = null;
        $error = null;
        $exception = null;
        $retryAfter = null;
        $attempted = true;
        $disable = false;

        try {
            $endpoint = $delivery->endpoint_id === null ? null : WebhookEndpoint::query()->find($delivery->endpoint_id);
            if (! $endpoint instanceof WebhookEndpoint || $endpoint->disabled_at !== null) {
                $attempted = false;
                $error = 'no_channel';
            } else {
                [$status, $error, $retryAfter] = $this->webhook($delivery, $endpoint);
                $disable = $status === 410;
            }
        } catch (Throwable $e) {
            $status = null;
            $error = 'internal';
            $exception = class_basename($e);
        }

        $now = CarbonImmutable::now();
        $attempts = $delivery->attempts + ($attempted ? 1 : 0);
        $outcome = match (true) {
            $error === null && $status !== null && $status >= 200 && $status < 300 => 'delivered',
            $attempted && self::retryable($status, $error) && $attempts < (int) config('alerts.delivery.max_attempts', 6) => 'retry',
            default => 'failed',
        };
        $next = $outcome === 'retry' ? self::timestamp($now->addSeconds($this->retry->delay($attempts, $retryAfter))) : $leaseText;

        DB::transaction(function () use ($delivery, $leaseText, $attempted, $outcome, $next, $status, $error, $now, $disable): void {
            DB::update(
                'UPDATE deliveries SET attempts = attempts + ?, status = ?, next_attempt_at = ?::timestamptz, last_status = ?, last_error = ?, delivered_at = ?::timestamptz
                 WHERE id = ? AND status = \'pending\' AND next_attempt_at = ?::timestamptz',
                [
                    $attempted ? 1 : 0,
                    $outcome === 'retry' ? 'pending' : $outcome,
                    $next,
                    $status,
                    $error,
                    $outcome === 'delivered' ? self::timestamp($now) : null,
                    $delivery->id,
                    $leaseText,
                ],
            );
            if ($disable && $delivery->endpoint_id !== null) {
                DB::update('UPDATE webhook_endpoints SET disabled_at = ?::timestamptz WHERE id = ? AND disabled_at IS NULL', [self::timestamp($now), $delivery->endpoint_id]);
            }
        });

        $context = [
            'delivery_id' => $delivery->id,
            'kind' => $delivery->kind,
            'channel' => $delivery->channel,
            'endpoint_id' => $delivery->endpoint_id,
            'attempt' => $attempts,
            'outcome' => $outcome,
            'status' => $status,
            'error' => $error,
        ];
        if ($error === 'internal') {
            $context['exception'] = $exception;
        }
        $context['duration_ms'] = intdiv(hrtime(true) - $started, 1_000_000);
        Log::info('delivery_attempt', $context);

        return ['outcome' => $outcome, 'error' => $error];
    }

    /**
     * One signed POST through the guard: [HTTP status or null, last_error or null, Retry-After seconds or null].
     *
     * @return array{0: int|null, 1: string|null, 2: int|null}
     */
    private function webhook(Delivery $delivery, WebhookEndpoint $endpoint): array
    {
        $url = $endpoint->url;
        $check = $this->guard->check($url);
        if (! $check->allowed()) {
            return [null, $check->error === 'unresolvable' ? 'unresolvable' : 'blocked_address', null];
        }

        $options = [
            'stream' => true,
            'allow_redirects' => false,
            'connect_timeout' => (int) config('alerts.webhooks.connect_timeout_seconds', 2),
            'timeout' => (int) config('alerts.webhooks.timeout_seconds', 5),
            'http_errors' => false,
            // No proxy from the environment: the pinned address below must be the one connected to.
            'proxy' => ['no' => ['*']],
        ];
        $pin = $check->curlResolve();
        if ($pin !== []) {
            $options['curl'] = [CURLOPT_RESOLVE => $pin];
        }

        $headers = WebhookSigner::headers((string) $endpoint->secret, $delivery->message_id, CarbonImmutable::now()->getTimestamp(), $delivery->body);

        try {
            // The curl handler for every request: Guzzle's default sends "stream" requests through PHP streams,
            // which would ignore CURLOPT_RESOLVE and resolve the host again.
            $response = Http::withOptions($options)
                ->setHandler(new CurlHandler)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders($headers)
                ->withBody($delivery->body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            return [null, self::timedOut($e) ? 'timeout' : 'connection', null];
        }

        $status = $response->status();
        $this->drain($response);

        if ($status >= 200 && $status < 300) {
            return [$status, null, null];
        }
        if ($status >= 300 && $status < 400) {
            return [$status, 'redirect', null];
        }
        $retryAfter = in_array($status, [429, 503], true) ? RetryPolicy::retryAfter($response->header('Retry-After') ?: null, CarbonImmutable::now()) : null;

        return [$status, 'http_status', $retryAfter];
    }

    /** Reads at most max_response_bytes of the response body, then closes it. */
    private function drain(Response $response): void
    {
        $max = (int) config('alerts.webhooks.max_response_bytes', 65536);
        $body = $response->toPsrResponse()->getBody();
        $read = 0;
        while ($read < $max && ! $body->eof()) {
            $chunk = $body->read(min(8192, $max - $read));
            if ($chunk === '') {
                break;
            }
            $read += strlen($chunk);
        }
        $body->close();
    }

    private static function retryable(?int $status, ?string $error): bool
    {
        return match ($error) {
            'connection', 'timeout', 'unresolvable', 'internal' => true,
            'http_status' => $status === 408 || $status === 429 || ($status !== null && $status >= 500 && $status < 600),
            default => false,
        };
    }

    /** curl error 28 (operation timed out): Guzzle's typed timeout exceptions, or the error code in the message. */
    private static function timedOut(ConnectionException $e): bool
    {
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof ConnectTimeoutException || $previous instanceof NetworkTimeoutException || $previous instanceof ResponseTimeoutException) {
                return true;
            }
        }

        return preg_match('/\bcURL error 28\b/', $e->getMessage()) === 1;
    }

    private static function timestamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.uP');
    }
}
