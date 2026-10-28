<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\GuardResult;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use App\Domain\Alerts\UrlGuard;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\ProxyEnv;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils as PsrUtils;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Psr\Http\Message\StreamInterface;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T02 review round 1: edge cases the committed tests do not reach. The transport pin (no environment
// proxy, curl handler only, CURLOPT_RESOLVE honoured), a sweep clock with microseconds, mixed outcome sequences,
// URL forms and allow-list abuse against the address guard, tampered requests against the sink, and secret leaks on
// outcome paths the sentinel tests skip. Real HTTP goes to loopback only.

/**
 * A loopback listener on a free port: PHP's built-in server for $script with $env, started under .gates/tmp/.
 *
 * @param  array<string, string>  $env
 * @return array{process: InvokedProcess, dir: string, port: int}
 */
function p5eServe(string $script, array $env = [], string $host = '127.0.0.1'): array
{
    $dir = base_path('../.gates/tmp/P5-T02-edge-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    $server = stream_socket_server("tcp://{$host}:0");
    assert(is_resource($server));
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $process = Process::path($dir)->env($env + ['SINK_DIR' => $dir])->start(['php', '-S', "{$host}:{$port}", $script]);
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            break;
        }
        usleep(50_000);
    }

    return ['process' => $process, 'dir' => $dir, 'port' => $port];
}

/** @param array{process: InvokedProcess, dir: string, port: int} $server */
function p5eStop(array $server): void
{
    $server['process']->stop();
    foreach (glob($server['dir'].'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($server['dir']);
}

/**
 * The real sink on loopback with the test secret. With $now set, the sink reads that Unix time as its clock
 * (SINK_NOW) instead of time(), so a case at the edge of its 300 s window does not depend on the wall clock.
 *
 * @return array{process: InvokedProcess, dir: string, port: int}
 */
function p5eSink(int $failFirst = 0, ?int $now = null): array
{
    $dir = base_path('../.gates/tmp/P5-T02-edge-seed-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/secret', AlertRows::secret());
    $env = ['SINK_SECRET_FILE' => $dir.'/secret', 'SINK_LOG' => $dir.'/log.jsonl', 'SINK_FAIL_FIRST' => (string) $failFirst];
    if ($now !== null) {
        $env['SINK_NOW'] = (string) $now;
    }
    $sink = p5eServe(base_path('../scripts/webhook-sink/sink.php'), $env);
    $sink['seed'] = $dir;
    Http::preventStrayRequests();
    Http::allowStrayRequests(["http://127.0.0.1:{$sink['port']}/*"]);

    return $sink;
}

/** @param array{process: InvokedProcess, dir: string, port: int, seed?: string} $sink */
function p5eStopSink(array $sink): void
{
    foreach (glob(($sink['seed'] ?? $sink['dir']).'/*') ?: [] as $file) {
        unlink($file);
    }
    if (isset($sink['seed'])) {
        rmdir($sink['seed']);
    }
    p5eStop($sink);
}

/** A signature of the Standard Webhooks scheme, computed here and nowhere in the application. */
function p5eSign(string $id, string $timestamp, string $body): string
{
    $key = (string) base64_decode(substr(AlertRows::secret(), strlen('whsec_')), true);

    return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
}

/** The decoded JSON-lines log the sink wrote. @return list<array<string, mixed>> */
function p5eSinkLog(string $dir): array
{
    $lines = is_file($dir.'/log.jsonl') ? file($dir.'/log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(fn (string $l): array => (array) json_decode($l, true), $lines === false ? [] : $lines);
}

beforeEach(function (): void {
    DeliveryRows::resolver();
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = AlertRows::user('edge@example.com');
    $this->endpoint = DeliveryRows::endpoint($this->user);
});

// ---------------------------------------------------------------------------------------------------------------
// Transport pin (D6: "never re-resolves"): no environment proxy, the curl handler, CURLOPT_RESOLVE honoured.

it('keeps an environment proxy out of the request options the sender passes, for http and https', function (): void {
    $vars = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
    $saved = [];
    foreach ($vars as $var) {
        $saved[$var] = getenv($var);
        putenv("{$var}=http://127.0.0.1:9");
    }
    try {
        $selections = [
            ProxyEnv::resolveProxySelection(new Uri('https://hooks.example.com/x'), ['no' => ['*']]),
            ProxyEnv::resolveProxySelection(new Uri('http://hooks.example.com/x'), ['no' => ['*']]),
        ];
        // Control: without the sender's option the same environment would route the request through the proxy.
        $control = ProxyEnv::resolveProxySelection(new Uri('https://hooks.example.com/x'), null);
    } finally {
        foreach ($saved as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
    }

    expect($control->hasProxy())->toBeTrue()
        ->and($selections[0]->hasProxy())->toBeFalse()
        ->and($selections[1]->hasProxy())->toBeFalse();
});

it('delivers over the curl handler and straight to the target while proxy variables point at a dead port', function (): void {
    $this->travelBack(); // the sink checks timestamps against the real clock
    $sink = p5eSink();
    $vars = ['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'];
    $saved = [];
    $handlerStats = [];
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$handlerStats): void {
        $handlerStats[] = $event->response->handlerStats();
    });
    foreach ($vars as $var) {
        $saved[$var] = getenv($var);
        putenv("{$var}=http://127.0.0.1:9");
    }
    try {
        config(['alerts.webhooks.private_allow' => ["127.0.0.1:{$sink['port']}"]]);
        $endpoint = DeliveryRows::endpoint($this->user, "http://127.0.0.1:{$sink['port']}/hook");
        $id = DeliveryRows::delivery($endpoint);

        [, $summary] = DeliveryRows::sweep();

        $log = p5eSinkLog($sink['seed']);
        expect($summary['delivered'])->toBe(1)
            ->and(DeliveryRows::row($id)->status)->toBe('delivered')
            ->and($log)->toHaveCount(1)
            ->and($log[0]['verified'])->toBeTrue()
            ->and($handlerStats)->toHaveCount(1)
            ->and($handlerStats[0]['primary_ip'] ?? null)->toBe('127.0.0.1');
    } finally {
        foreach ($saved as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
        p5eStopSink($sink);
    }
});

it('connects to the pinned address, not the one the name resolves to, through the curl handler', function (): void {
    $dir = base_path('../.gates/tmp/P5-T02-edge-router-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/router.php', "<?php\nhttp_response_code(204);\n");
    $server = p5eServe($dir.'/router.php', [], '127.0.0.2');
    try {
        // "localhost" is 127.0.0.1 in the hosts file; only the pin can reach the listener on 127.0.0.2.
        $pin = (new GuardResult(null, 'localhost', $server['port'], '127.0.0.2'))->curlResolve();
        Http::allowStrayRequests(["http://localhost:{$server['port']}/*"]);

        $response = Http::withOptions([
            'stream' => true, 'allow_redirects' => false, 'connect_timeout' => 2, 'timeout' => 5,
            'proxy' => ['no' => ['*']], 'curl' => [CURLOPT_RESOLVE => $pin],
        ])->setHandler(new CurlHandler)->post("http://localhost:{$server['port']}/hook");

        expect($pin)->toBe(["localhost:{$server['port']}:127.0.0.2"])
            ->and($response->status())->toBe(204)
            ->and($response->handlerStats()['primary_ip'] ?? null)->toBe('127.0.0.2');
    } finally {
        p5eStop($server);
        unlink($dir.'/router.php');
        rmdir($dir);
    }
});

// ---------------------------------------------------------------------------------------------------------------
// Clock with microseconds (production reads CarbonImmutable::now(); the committed tests travel to whole seconds).

it('claims, sends and records a delivery when the sweep clock carries microseconds', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00.987654', 'UTC');
    $this->travelTo($now);
    expect(CarbonImmutable::now()->micro)->toBe(987654);
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $now);

    $row = DeliveryRows::row($id);
    expect($summary)->toBe(['retired' => 0, 'claimed' => 1, 'delivered' => 1, 'retry' => 0, 'failed' => 0, 'released' => 0])
        ->and([$row->status, $row->attempts, $row->claims])->toBe(['delivered', 1, 1]);
});

it('gives a claimed row back exactly when the sweep clock carries microseconds', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00.987654', 'UTC');
    $this->travelTo($now);
    config(['alerts.delivery.sweep_budget_seconds' => 0]);
    $id = DeliveryRows::delivery($this->endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $now);

    $row = DeliveryRows::row($id);
    expect($summary['released'])->toBe(1)
        ->and($summary['delivered'])->toBe(0)
        ->and([$row->status, $row->attempts, $row->claims, $row->next_at])->toBe(['pending', 0, 0, '2026-10-15T12:00:00.987654'])
        ->and(Http::recorded())->toHaveCount(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Mixed outcomes: attempts, requests and the sixth-attempt retirement stay consistent.

it('ends failed on the sixth attempt across a mix of guard refusals, connection failures and statuses, then stays quiet', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    $script = ['503', 'dns', 'connection', '500', 'timeout', '503'];
    $step = 0;
    $requests = 0;
    Http::fake(['*' => function () use (&$script, &$step, &$requests) {
        $requests++;
        $kind = $script[$step - 1] ?? '503';
        if ($kind === 'connection') {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }
        if ($kind === 'timeout') {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response('', (int) $kind);
    }]);

    for ($i = 1; $i <= 9; $i++) {
        $step = $i;
        FakeHostResolver::install(($script[$i - 1] ?? '') === 'dns' ? [] : [DeliveryRows::HOST => [DeliveryRows::PUBLIC_IP]]);
        DeliveryRows::sweep();
        $this->travel(3601)->seconds();
    }

    $row = DeliveryRows::row($id);
    $lines = AlertRows::logged('delivery_attempt');
    expect([$row->status, $row->attempts])->toBe(['failed', 6])
        ->and($row->claims)->toBe(6)
        // One of the six attempts was a guard refusal: no request was made for it.
        ->and($requests)->toBe(5)
        ->and(array_map(fn (array $l): int => $l['context']['attempt'], $lines))->toBe([1, 2, 3, 4, 5, 6])
        ->and(array_map(fn (array $l): string => $l['context']['outcome'], $lines))->toBe(['retry', 'retry', 'retry', 'retry', 'retry', 'failed']);
});

it('does not spend an attempt on a no_channel row but does retire it failed', function (): void {
    $id = DeliveryRows::delivery($this->endpoint);
    DB::table('webhook_endpoints')->where('id', $this->endpoint)->update(['disabled_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.uP')]);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();
    $this->travel(3601)->seconds();
    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect([$row->status, $row->last_error, $row->attempts])->toBe(['failed', 'no_channel', 0])
        ->and(Http::recorded())->toHaveCount(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Response read cap.

it('stops reading a body that never reports its end, closes it, and gives up on a body that returns nothing', function (string $shape, int $reads, int $bytes): void {
    $id = DeliveryRows::delivery($this->endpoint);
    $body = new class(PsrUtils::streamFor(''), $shape) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public int $bytesRead = 0;

        public int $reads = 0;

        public bool $closed = false;

        public function __construct(StreamInterface $stream, private readonly string $shape)
        {
            $this->stream = $stream;
        }

        public function read($length): string
        {
            $this->reads++;
            // "greedy" ignores the requested length; "endless" always has more; "stall" returns nothing.
            $chunk = match ($this->shape) {
                'stall' => '',
                'greedy' => str_repeat('x', 70000),
                default => str_repeat('x', $length),
            };
            $this->bytesRead += strlen($chunk);

            return $chunk;
        }

        public function eof(): bool
        {
            return false;
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };
    Http::fake(['*' => fn () => Create::promiseFor(new PsrResponse(200, [], $body))]);

    DeliveryRows::sweep();

    expect(DeliveryRows::row($id)->status)->toBe('delivered')
        ->and($body->closed)->toBeTrue()
        ->and($body->reads)->toBe($reads)
        ->and($body->bytesRead)->toBe($bytes);
})->with([
    'endless: exactly the cap' => ['endless', 8, 65536],
    'greedy: one oversized read, then stop' => ['greedy', 1, 70000],
    'stall: one empty read, then stop' => ['stall', 1, 0],
]);

// ---------------------------------------------------------------------------------------------------------------
// Address guard: URL forms and allow-list abuse (D6).

it('refuses URL forms that could reach a private host, without resolving a literal', function (string $url): void {
    $resolver = FakeHostResolver::install([]);

    $result = app(UrlGuard::class)->check($url);

    expect($result->allowed())->toBeFalse()
        ->and($result->error)->not->toBeNull()
        ->and($resolver->asked)->toBe([]);
})->with([
    'mapped loopback dotted' => 'https://[::ffff:127.0.0.1]/h',
    'mapped loopback long form' => 'https://[0:0:0:0:0:ffff:7f00:1]/h',
    'compatible loopback' => 'https://[::127.0.0.1]/h',
    '6to4 loopback' => 'https://[2002:7f00:1::]/h',
    'unspecified' => 'https://[::]/h',
    'unique local upper case' => 'https://[FC00::1]/h',
    'multicast' => 'https://[ff02::1]/h',
    'nat64 private' => 'https://[64:ff9b::a00:1]/h',
    'zone id' => 'https://[fe80::1%25eth0]/h',
    'unclosed bracket' => 'https://[::1/h',
    'octal first octet' => 'https://0177.0.0.1/h',
    'hex octet' => 'https://0x7f.0.0.1/h',
    'zero padded octets' => 'https://127.000.000.001/h',
    'trailing dot literal' => 'https://127.0.0.1./h',
    'three part literal' => 'https://127.0.1/h',
    'all numeric name' => 'https://2130706433/h',
    'numeric last label' => 'https://example.123/h',
    'full width digits' => "https://\u{FF11}\u{FF12}\u{FF17}.0.0.1/h",
    'user info before a literal' => 'https://example.com@127.0.0.1/h',
    'user info with encoded slash' => 'https://example.com%2f@127.0.0.1/h',
    'empty user info' => 'https://:@example.com/h',
    'port above range' => 'https://example.com:65536/h',
    'port zero' => 'https://example.com:0/h',
    'empty port' => 'https://example.com:/h',
    'two ports' => 'https://example.com:443:80/h',
    'no host' => 'https:///h',
    'space in host' => 'https://exa mple.com/h',
    'tab in path' => "https://example.com/h\tx",
    'newline suffix' => "https://example.com/h\n",
    'nul byte' => "https://example.com/h\0",
    'backslash host trick' => 'https://example.com\\@127.0.0.1/h',
    'fragment only' => 'https://example.com#',
    'fragment hides user info' => 'https://127.0.0.1#@example.com/h',
    'scheme-relative' => '//example.com/h',
    'no slashes' => 'https:example.com',
    'ftp scheme' => 'ftp://example.com/h',
    'file scheme' => 'file:///etc/hosts',
    'gopher scheme' => 'gopher://example.com/h',
]);

it('allows the forms a public receiver may use and pins them in lower case on the canonical port', function (string $url, string $pin): void {
    FakeHostResolver::install([DeliveryRows::HOST => [DeliveryRows::PUBLIC_IP]]);

    $result = app(UrlGuard::class)->check($url);

    expect($result->allowed())->toBeTrue()
        ->and($result->curlResolve())->toBe($pin === '' ? [] : [$pin]);
})->with([
    'upper case host and scheme' => ['HTTPS://HOOKS.Example.COM:443/x', 'hooks.example.com:443:203.0.113.10'],
    'leading zero port' => ['https://hooks.example.com:0443/x', 'hooks.example.com:443:203.0.113.10'],
    'query only' => ['https://hooks.example.com?x=1', 'hooks.example.com:443:203.0.113.10'],
    'documentation IPv6 literal' => ['https://[2001:db8::1]/x', ''],
]);

it('refuses a DNS answer that holds any address outside the public ranges, however it is written', function (array $answer): void {
    FakeHostResolver::install([DeliveryRows::HOST => $answer]);

    expect(app(UrlGuard::class)->check(DeliveryRows::URL)->allowed())->toBeFalse();
})->with([
    'public then mapped private' => [['203.0.113.10', '::ffff:'.implode('.', [10, 0, 0, 1])]],
    'public then not an address' => [['203.0.113.10', 'not-an-ip']],
    'public then short IPv4' => [['203.0.113.10', '127.1']],
    'public then empty string' => [['203.0.113.10', '']],
    'compatible loopback' => [['203.0.113.10', '::7f00:1']],
]);

it('pins an IPv6 answer in brackets', function (): void {
    FakeHostResolver::install([DeliveryRows::HOST => ['2001:db8::7']]);

    expect(app(UrlGuard::class)->check(DeliveryRows::URL)->curlResolve())->toBe(['hooks.example.com:443:[2001:db8::7]']);
});

it('lets the allow-list through only for the exact host and port, and not by look-alike URL', function (string $url, bool $allowed): void {
    config(['alerts.webhooks.private_allow' => ['  API:8096 ']]);
    FakeHostResolver::install([]);

    expect(app(UrlGuard::class)->check($url)->allowed())->toBe($allowed);
})->with([
    'exact' => ['http://api:8096/hook', true],
    'upper case' => ['HTTP://API:8096/hook', true],
    'https on the allow-listed port' => ['https://api:8096/hook', true],
    'other port' => ['http://api:8097/hook', false],
    'default port' => ['http://api/hook', false],
    'user info naming the pair' => ['http://api:8096@evil.example.com/hook', false],
    'pair as a sub-domain' => ['http://api.evil.example.com:8096/hook', false],
    'pair as a prefix of the port' => ['http://api:80961/hook', false],
    'pair then a domain' => ['http://api:8096.evil.example.com/hook', false],
    'longer host' => ['http://apiapi:8096/hook', false],
    'pair in the path' => ['http://evil.example.com/api:8096', false],
    'pair in the query' => ['http://evil.example.com/?h=api:8096', false],
]);

it('does not allow a bare host in the allow-list to open port 80', function (): void {
    config(['alerts.webhooks.private_allow' => ['api']]);
    FakeHostResolver::install([]);

    expect(app(UrlGuard::class)->check('http://api/hook')->allowed())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------
// The sink: a tampered request never verifies (D12).

it('answers 401 to every tampered or malformed request, and 204 to the genuine forms, against the real sink', function (): void {
    // The sink's clock is pinned to the body's own timestamp (2026-10-15T12:00:00Z), so the 299 s and 301 s
    // cases are exact however long the requests before them take.
    $now = 1_792_065_600;
    $sink = p5eSink(now: $now);
    $url = "http://127.0.0.1:{$sink['port']}/hook";
    $body = '{"type":"webhook.test","timestamp":"2026-10-15T12:00:00Z","data":{"message":"PokéPrices test message. It carries no price."}}';
    $ts = (string) $now;
    $good = p5eSign('msg_edge', $ts, $body);
    $stale = fn (int $age): array => [(string) ($now - $age), p5eSign('msg_edge', (string) ($now - $age), $body)];
    $post = fn (string $id, string $timestamp, string $signature, string $payload): int => Http::withHeaders([
        'webhook-id' => $id, 'webhook-timestamp' => $timestamp, 'webhook-signature' => $signature,
    ])->withBody($payload, 'application/json')->post($url)->status();

    try {
        $answers = [
            'genuine' => $post('msg_edge', $ts, $good, $body),
            'timestamp one second later' => $post('msg_edge', (string) ($now + 1), $good, $body),
            'body cut by one byte' => $post('msg_edge', $ts, $good, substr($body, 0, -1)),
            'body with a trailing newline' => $post('msg_edge', $ts, $good, $body."\n"),
            'id changed' => $post('msg_edge2', $ts, $good, $body),
            'no version prefix' => $post('msg_edge', $ts, substr($good, 3), $body),
            'version v2' => $post('msg_edge', $ts, 'v2,'.substr($good, 3), $body),
            'v1 written v1a' => $post('msg_edge', $ts, 'v1a,'.substr($good, 3), $body),
            'garbage then genuine' => $post('msg_edge', $ts, 'v1,AAAA '.$good, $body),
            'genuine then garbage' => $post('msg_edge', $ts, $good.' v1,AAAA', $body),
            'wrong version then wrong v1' => $post('msg_edge', $ts, 'v2,'.substr($good, 3).' v1,AAAA', $body),
            'timestamp 299 s old' => $post('msg_edge', ...[...$stale(299), $body]),
            'timestamp 301 s old' => $post('msg_edge', ...[...$stale(301), $body]),
            'timestamp 301 s ahead' => $post('msg_edge', ...[...$stale(-301), $body]),
            'timestamp with a plus sign' => $post('msg_edge', '+'.$ts, p5eSign('msg_edge', '+'.$ts, $body), $body),
            'timestamp in exponent form' => $post('msg_edge', '1e9', p5eSign('msg_edge', '1e9', $body), $body),
            'empty signature' => $post('msg_edge', $ts, '', $body),
        ];

        $log = p5eSinkLog($sink['seed']);
        $raw = (string) file_get_contents($sink['seed'].'/log.jsonl');
        expect($answers)->toBe([
            'genuine' => 204,
            'timestamp one second later' => 401,
            'body cut by one byte' => 401,
            'body with a trailing newline' => 401,
            'id changed' => 401,
            'no version prefix' => 401,
            'version v2' => 401,
            'v1 written v1a' => 401,
            'garbage then genuine' => 204,
            'genuine then garbage' => 204,
            'wrong version then wrong v1' => 401,
            'timestamp 299 s old' => 204,
            'timestamp 301 s old' => 401,
            'timestamp 301 s ahead' => 401,
            'timestamp with a plus sign' => 401,
            'timestamp in exponent form' => 401,
            'empty signature' => 401,
        ])
            ->and(count($log))->toBe(count($answers))
            ->and($raw)->not->toContain('whsec_')
            ->and($raw)->not->toContain(substr($good, 3))
            ->and($raw)->not->toContain('PokéPrices');
    } finally {
        p5eStopSink($sink);
    }
});

it('refuses a request with no secret configured instead of accepting it', function (): void {
    $dir = base_path('../.gates/tmp/P5-T02-edge-nosecret-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    $server = p5eServe(base_path('../scripts/webhook-sink/sink.php'), ['SINK_SECRET_FILE' => $dir.'/absent', 'SINK_LOG' => $dir.'/log.jsonl']);
    Http::allowStrayRequests(["http://127.0.0.1:{$server['port']}/*"]);
    $body = '{}';
    $ts = (string) time();
    try {
        $status = Http::withHeaders(['webhook-id' => 'msg_x', 'webhook-timestamp' => $ts, 'webhook-signature' => p5eSign('msg_x', $ts, $body)])
            ->withBody($body, 'application/json')->post("http://127.0.0.1:{$server['port']}/hook")->status();

        expect($status)->toBeGreaterThanOrEqual(400);
    } finally {
        p5eStop($server);
        rmdir($dir);
    }
});

// ---------------------------------------------------------------------------------------------------------------
// Leaks on outcome paths the sentinel tests skip (D7: nothing but class names and codes leaves the sender).

it('keeps every sentinel out of logs, failed jobs and the exception handler on every other outcome path', function (Closure $fake, ?string $resolverHost, string $expectedError): void {
    Exceptions::fake();
    $endpoint = DeliveryRows::endpoint($this->user, 'https://hooks.example.com/SENTINELPATH?token=SENTINELTOKEN');
    $id = DeliveryRows::delivery($endpoint);
    if ($resolverHost !== null) {
        FakeHostResolver::install($resolverHost === '' ? [] : ['hooks.example.com' => [$resolverHost]]);
    }
    Http::fake(['*' => $fake]);

    DeliveryRows::job();

    $row = DeliveryRows::row($id);
    $logs = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stored = json_encode(DB::table('failed_jobs')->get()).json_encode([$row->last_error, $row->last_status]);
    expect($row->last_error)->toBe($expectedError)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($logs)->not->toContain('SENTINEL')
        ->and($logs)->not->toContain('whsec_')
        ->and($logs)->not->toContain('hooks.example.com')
        ->and($logs)->not->toContain('v1,')
        ->and($stored)->not->toContain('SENTINEL');
    Exceptions::assertNothingReported();
})->with([
    'redirect whose Location and body carry the sentinel' => [
        fn () => Http::response('SENTINELBODY', 302, ['Location' => 'https://discord.com/api/webhooks/1/SENTINELTOKEN']), null, 'redirect',
    ],
    '503 whose Retry-After and body carry the sentinel' => [
        fn () => Http::response('SENTINELBODY', 503, ['Retry-After' => 'SENTINELTOKEN']), null, 'http_status',
    ],
    '410 with a sentinel body' => [fn () => Http::response('SENTINELBODY', 410), null, 'http_status'],
    'timeout whose message ends with the URL' => [
        fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://hooks.example.com/SENTINELPATH?token=SENTINELTOKEN'), null, 'timeout',
    ],
    'request exception that quotes the response body' => [
        fn () => throw new RequestException(new Response(new PsrResponse(500, [], 'SENTINELBODY https://hooks.example.com/SENTINELPATH'))), null, 'internal',
    ],
    'an Error raised by the transport' => [fn () => throw new Error('SENTINELTOKEN https://hooks.example.com/SENTINELPATH'), null, 'internal'],
    'unresolvable host' => [fn () => Http::response('', 204), '', 'unresolvable'],
    'blocked host' => [fn () => Http::response('', 204), implode('.', [10, 0, 0, 1]), 'blocked_address'],
]);

// ---------------------------------------------------------------------------------------------------------------
// Sweep accounting at the claim ceiling (D7: claims >= 2 x max_attempts retire a row, 11 does not).

it('sends a row at 11 claims and retires rows at 12 claims, due or not, without a request', function (): void {
    $eleven = DeliveryRows::delivery($this->endpoint, ['claims' => 11]);
    $twelve = DeliveryRows::delivery($this->endpoint, ['claims' => 12]);
    $future = DeliveryRows::delivery($this->endpoint, ['claims' => 13, 'next_attempt_at' => '2099-01-01 00:00:00+00']);
    Http::fake(['*' => Http::response('', 204)]);

    [, $summary] = DeliveryRows::sweep();

    expect($summary['retired'])->toBe(2)
        ->and($summary['delivered'])->toBe(1)
        ->and(Http::recorded())->toHaveCount(1)
        ->and([DeliveryRows::row($eleven)->status, DeliveryRows::row($eleven)->claims])->toBe(['delivered', 12])
        ->and([DeliveryRows::row($twelve)->status, DeliveryRows::row($twelve)->last_error, DeliveryRows::row($twelve)->attempts])->toBe(['failed', 'internal', 0])
        ->and([DeliveryRows::row($future)->status, DeliveryRows::row($future)->last_error])->toBe(['failed', 'internal']);
});
