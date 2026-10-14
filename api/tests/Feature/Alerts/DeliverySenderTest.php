<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\WebhookSecret;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils as PsrUtils;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T02 criteria 1, 2, 4 and 9 (D5, D6, D7): one attempt per send through the guard, signed with fresh
// timestamps over a byte-identical body, the outcome table, attempts equal to requests made, the request options,
// the response read cap, the log lines, and containment of every exception a send can throw.

beforeEach(function (): void {
    Http::preventStrayRequests();
    DeliveryRows::resolver();
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = AlertRows::user('sender@example.com');
    $this->endpoint = DeliveryRows::endpoint($this->user);
});

/** Verifies the three Standard Webhooks headers of a recorded request independently of the signer. */
function p5Verified(ClientRequest $request): bool
{
    $key = base64_decode(substr(AlertRows::secret(), strlen('whsec_')), true);
    $id = $request->header('webhook-id')[0] ?? '';
    $ts = $request->header('webhook-timestamp')[0] ?? '';
    $sig = $request->header('webhook-signature')[0] ?? '';

    return hash_equals('v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.".$request->body(), (string) $key, true)), $sig);
}

/** @return list<ClientRequest> */
function p5Requests(): array
{
    return Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
}

it('signs a delivery with the three headers, the JSON content type and the PokéPrices user agent', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    [$code, $summary] = DeliveryRows::sweep();

    $requests = p5Requests();
    expect($code)->toBe(0)
        ->and($summary)->toBe(['retired' => 0, 'claimed' => 1, 'delivered' => 1, 'retry' => 0, 'failed' => 0, 'released' => 0])
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->url())->toBe(DeliveryRows::URL)
        ->and($requests[0]->method())->toBe('POST')
        ->and($requests[0]->body())->toBe(DeliveryRows::BODY)
        ->and($requests[0]->header('Content-Type'))->toBe(['application/json'])
        ->and($requests[0]->header('User-Agent'))->toBe(['PokePrices-Webhooks/1'])
        ->and($requests[0]->header('webhook-id'))->toBe([DeliveryRows::row($id)->message_id])
        ->and($requests[0]->header('webhook-timestamp'))->toBe([(string) CarbonImmutable::now()->getTimestamp()])
        ->and(p5Verified($requests[0]))->toBeTrue();
});

it('carries the pinned address, no redirects, the timeouts and a streamed response in the request options', function (): void {
    DeliveryRows::delivery($this->endpoint);
    $seen = null;
    Http::fake(function (ClientRequest $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('', 204);
    });

    DeliveryRows::sweep();

    expect($seen)->not->toBeNull()
        ->and($seen['stream'])->toBeTrue()
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['connect_timeout'])->toBe(2)
        ->and($seen['timeout'])->toBe(5)
        ->and($seen['proxy'])->toBe(['no' => ['*']])
        ->and($seen['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:'.DeliveryRows::PUBLIC_IP]);
});

it('resends the same body and webhook-id on every attempt, with a fresh timestamp and a valid signature each time', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::sequence()
        ->push('', 503)
        ->pushFailedConnection('cURL error 7: Failed to connect to hooks.example.com port 443')
        ->push('', 204)]);

    DeliveryRows::sweep();
    $this->travel(31)->seconds();
    DeliveryRows::sweep();
    $this->travel(121)->seconds();
    DeliveryRows::sweep();

    $requests = p5Requests();
    $row = DeliveryRows::row($id);
    $timestamps = array_map(fn (ClientRequest $r): string => $r->header('webhook-timestamp')[0], $requests);
    $start = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC')->getTimestamp();

    expect($requests)->toHaveCount(3)
        ->and(array_unique(array_map(fn (ClientRequest $r): string => $r->body(), $requests)))->toBe([DeliveryRows::BODY])
        ->and(array_unique(array_map(fn (ClientRequest $r): string => $r->header('webhook-id')[0], $requests)))->toBe([$row->message_id])
        ->and($timestamps)->toBe([(string) $start, (string) ($start + 31), (string) ($start + 152)])
        ->and(array_map('p5Verified', $requests))->toBe([true, true, true])
        ->and(count(array_unique(array_map(fn (ClientRequest $r): string => $r->header('webhook-signature')[0], $requests))))->toBe(3)
        ->and($row->status)->toBe('delivered')
        ->and($row->attempts)->toBe(3)
        ->and($row->last_status)->toBe(204);
});

it('records a 2xx as delivered', function (int $status): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('ok', $status)]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($row->status)->toBe('delivered')
        ->and($row->attempts)->toBe(1)
        ->and($row->last_status)->toBe($status)
        ->and($row->last_error)->toBeNull()
        ->and($row->delivered_at)->not->toBeNull()
        ->and(Http::recorded())->toHaveCount(1);
})->with([200, 202, 204]);

it('keeps a retryable failure pending inside the jitter bounds of attempt 1', function (Closure $fake, ?int $status, string $error): void {
    $id = DeliveryRows::delivery($this->endpoint);
    DeliveryRows::jitter('min');
    $requests = 0;
    Http::fake(['*' => function () use (&$requests, $fake) {
        $requests++;

        return $fake();
    }]);

    DeliveryRows::sweep();
    $low = DeliveryRows::dueIn($id);
    $row = DeliveryRows::row($id);

    expect($row->status)->toBe('pending')
        ->and($row->last_error)->toBe($error)
        ->and($row->last_status)->toBe($status)
        ->and($row->attempts)->toBe(1)
        ->and($requests)->toBe(1)
        ->and($low)->toBe(15.0);
})->with([
    '408' => [fn () => Http::response('', 408), 408, 'http_status'],
    '429' => [fn () => Http::response('', 429), 429, 'http_status'],
    '500' => [fn () => Http::response('', 500), 500, 'http_status'],
    '503' => [fn () => Http::response('', 503), 503, 'http_status'],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'), null, 'timeout'],
    'connection refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect to hooks.example.com port 443: Connection refused'), null, 'connection'],
]);

it('waits up to the top of the jitter bound with the maximum randomness', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 500)]);

    DeliveryRows::sweep();

    expect(DeliveryRows::dueIn($id))->toBe(30.0);
});

it('keeps an unresolvable host pending without a request', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user, 'https://gone.example.com/hook');
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($row->status)->toBe('pending')
        ->and($row->last_error)->toBe('unresolvable')
        ->and(DeliveryRows::dueIn($id))->toBe(30.0)
        ->and(Http::recorded())->toHaveCount(0);
});

it('fails a blocked address at send time without a request and without retrying', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user, 'https://inside.example.com/hook');
    FakeHostResolver::install(['inside.example.com' => ['127.0.0.1']]);
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($row->status)->toBe('failed')
        ->and($row->last_error)->toBe('blocked_address')
        ->and(Http::recorded())->toHaveCount(0);
});

it('honours Retry-After on a 503 and a 429, capped at an hour', function (int $status, string $header, float $wait): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', $status, ['Retry-After' => $header])]);

    DeliveryRows::sweep();

    expect(DeliveryRows::dueIn($id))->toBe($wait);
})->with([
    '503 ninety seconds' => [503, '90', 90.0],
    '429 ninety seconds' => [429, '90', 90.0],
    '503 above the cap' => [503, '86400', 3600.0],
    '503 below the jitter' => [503, '5', 30.0],
]);

it('fails a non-retryable 4xx at once', function (int $status): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', $status)]);

    DeliveryRows::sweep();
    $this->travel(2)->hours();
    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($row->status)->toBe('failed')
        ->and($row->last_error)->toBe('http_status')
        ->and($row->last_status)->toBe($status)
        ->and($row->attempts)->toBe(1)
        ->and(Http::recorded())->toHaveCount(1);
})->with([400, 401, 403, 404, 422]);

it('turns the endpoint off on a 410 and fails its other pending rows with no_channel and no request', function (): void {
    $first = DeliveryRows::delivery($this->endpoint);
    $this->travel(1)->seconds();
    $second = DeliveryRows::delivery($this->endpoint);
    $third = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 410)]);

    DeliveryRows::sweep();

    $a = DeliveryRows::row($first);
    $b = DeliveryRows::row($second);
    $c = DeliveryRows::row($third);
    expect($a->status)->toBe('failed')
        ->and($a->last_status)->toBe(410)
        ->and($a->last_error)->toBe('http_status')
        ->and(DB::table('webhook_endpoints')->where('id', $this->endpoint)->value('disabled_at'))->not->toBeNull()
        ->and([$b->status, $b->last_error, $b->attempts])->toBe(['failed', 'no_channel', 0])
        ->and([$c->status, $c->last_error, $c->attempts])->toBe(['failed', 'no_channel', 0])
        ->and(Http::recorded())->toHaveCount(1);
});

it('fails a redirect and never requests its Location', function (int $status): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake([
        'hooks.example.com/*' => Http::response('', $status, ['Location' => 'https://elsewhere.example.com/x']),
        '*' => Http::response('', 204),
    ]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($row->status)->toBe('failed')
        ->and($row->last_error)->toBe('redirect')
        ->and($row->last_status)->toBe($status)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(p5Requests()[0]->url())->toBe(DeliveryRows::URL);
})->with([301, 302, 307, 308]);

it('fails after the sixth failed attempt', function (): void {
    $id = DeliveryRows::delivery($this->endpoint, ['attempts' => 5]);
    Http::fake(['*' => Http::response('', 503)]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect([$row->status, $row->attempts, $row->last_error, $row->last_status])->toBe(['failed', 6, 'http_status', 503]);
});

it('makes six attempts in all across a run of failures, then stops', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 500)]);

    for ($i = 0; $i < 8; $i++) {
        DeliveryRows::sweep();
        $this->travel(3601)->seconds();
    }

    $row = DeliveryRows::row($id);
    expect([$row->status, $row->attempts])->toBe(['failed', 6])
        ->and(Http::recorded())->toHaveCount(6)
        ->and(AlertRows::logged('delivery_attempt'))->toHaveCount(6);
});

it('makes no request for a delivered or failed row, or for a row whose lease is not the caller\'s', function (string $status, int $leaseOffset): void {
    $id = DeliveryRows::delivery($this->endpoint, ['status' => $status]);
    Http::fake(['*' => Http::response('', 204)]);
    $next = CarbonImmutable::parse(DeliveryRows::row($id)->next_at.'Z');

    $result = app(DeliverySender::class)->send($id, $next->addSeconds($leaseOffset));

    expect($result['outcome'])->toBe('skipped')
        ->and(Http::recorded())->toHaveCount(0)
        ->and(DeliveryRows::row($id)->attempts)->toBe(0);
})->with([
    'delivered' => ['delivered', 0],
    'failed' => ['failed', 0],
    'pending, another lease' => ['pending', 1],
]);

it('reads at most 64 KiB of a large response body', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    $body = new class(PsrUtils::streamFor(str_repeat('x', 5 * 1024 * 1024))) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public int $bytesRead = 0;

        public bool $closed = false;

        public function read($length): string
        {
            $chunk = $this->stream->read($length);
            $this->bytesRead += strlen($chunk);

            return $chunk;
        }

        public function close(): void
        {
            $this->closed = true;
            $this->stream->close();
        }
    };
    Http::fake(['*' => fn () => Create::promiseFor(new PsrResponse(200, [], $body))]);

    DeliveryRows::sweep();

    expect(DeliveryRows::row($id)->status)->toBe('delivered')
        ->and($body->bytesRead)->toBeGreaterThan(0)
        ->and($body->bytesRead)->toBeLessThanOrEqual(65536)
        ->and($body->closed)->toBeTrue();
});

it('writes one delivery_attempt line per attempt with exactly the D7 keys and nothing secret', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::sequence()
        ->push('', 503)
        ->pushFailedConnection('cURL error 28: Operation timed out for '.DeliveryRows::URL)
        ->push('', 204)]);

    DeliveryRows::sweep();
    $this->travel(31)->seconds();
    DeliveryRows::sweep();
    $this->travel(121)->seconds();
    DeliveryRows::sweep();

    $lines = AlertRows::logged('delivery_attempt');
    $keys = ['delivery_id', 'kind', 'channel', 'endpoint_id', 'attempt', 'outcome', 'status', 'error', 'duration_ms'];
    expect($lines)->toHaveCount(3)
        ->and(array_map(fn (array $l): array => array_keys($l['context']), $lines))->toBe([$keys, $keys, $keys])
        ->and(array_map(fn (array $l): array => [$l['context']['delivery_id'], $l['context']['kind'], $l['context']['channel'], $l['context']['endpoint_id']], $lines))
        ->toBe(array_fill(0, 3, [$id, 'price.moved', 'webhook', $this->endpoint]))
        ->and(array_map(fn (array $l): array => [$l['context']['attempt'], $l['context']['outcome'], $l['context']['status'], $l['context']['error']], $lines))
        ->toBe([[1, 'retry', 503, 'http_status'], [2, 'retry', null, 'timeout'], [3, 'delivered', 204, null]])
        ->and(array_map(fn (array $l): bool => is_int($l['context']['duration_ms']), $lines))->toBe([true, true, true]);

    $all = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach (['hooks.example.com', '/pokeprices/test-0000', 'https://', 'whsec_', 'v1,', '"close"', 'evt_1', '@example.com', DeliveryRows::row($id)->message_id] as $needle) {
        expect($all)->not->toContain($needle);
    }
});

it('contains a sentinel exception from the send: recorded as a class code, never logged, reported or a failed job', function (Closure $throw, string $error, ?string $exception): void {
    Exceptions::fake();
    $endpoint = DeliveryRows::endpoint($this->user, 'https://hooks.example.com/SENTINELPATH');
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(['*' => $throw]);

    DeliveryRows::job();

    $row = DeliveryRows::row($id);
    $lines = AlertRows::logged('delivery_attempt');
    $all = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES).json_encode(DB::table('failed_jobs')->get());
    expect($row->status)->toBe('pending')
        ->and($row->last_error)->toBe($error)
        ->and($row->attempts)->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context']['error'])->toBe($error)
        ->and($lines[0]['context']['exception'] ?? null)->toBe($exception)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($all)->not->toContain('SENTINEL');
    Exceptions::assertNothingReported();
})->with([
    'runtime exception with a Discord URL' => [fn () => throw new RuntimeException('https://discord.com/api/webhooks/1/SENTINELTOKEN'), 'internal', 'RuntimeException'],
    'connection exception ending with the URL' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect for https://hooks.example.com/SENTINELPATH'), 'connection', null],
    'decrypt exception' => [fn () => throw new DecryptException('SENTINEL payload is invalid.'), 'internal', 'DecryptException'],
]);

it('verifies with the secret the endpoint stores, so a different key signs differently', function (): void {
    $other = WebhookSecret::PREFIX.base64_encode(str_repeat("\1", 32));
    $endpoint = DeliveryRows::endpoint($this->user, DeliveryRows::URL, $other);
    DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    expect(p5Verified(p5Requests()[0]))->toBeFalse();
});
