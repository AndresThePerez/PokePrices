<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\TestMessage;
use App\Domain\Alerts\WebhookSecret;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;

// Plan P5-i1 T02 criterion 8 (D12): the standalone signature-verifying sink, run with PHP's built-in server on a
// free loopback port, receives REAL requests from the sender (the only non-faked HTTP in the suite: stray requests
// stay prevented for every other URL). Its secret file and log live under .gates/tmp/ and are removed afterwards.

/**
 * Starts the sink with a fresh directory, secret file and log; allow-lists its loopback host:port.
 *
 * @return array{process: InvokedProcess, dir: string, log: string, url: string}
 */
function p5Sink(int $failFirst): array
{
    $dir = base_path('../.gates/tmp/P5-T02-sink-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/secret', AlertRows::secret());
    $server = stream_socket_server('tcp://127.0.0.1:0');
    assert(is_resource($server));
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $process = Process::path($dir)
        ->env(['SINK_SECRET_FILE' => $dir.'/secret', 'SINK_LOG' => $dir.'/log.jsonl', 'SINK_FAIL_FIRST' => (string) $failFirst])
        ->start(['php', '-S', "127.0.0.1:{$port}", base_path('../scripts/webhook-sink/sink.php')]);
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            break;
        }
        usleep(50_000);
    }

    config(['alerts.webhooks.private_allow' => ["127.0.0.1:{$port}"]]);
    Http::preventStrayRequests();
    Http::allowStrayRequests(["http://127.0.0.1:{$port}/*"]);

    return ['process' => $process, 'dir' => $dir, 'log' => $dir.'/log.jsonl', 'url' => "http://127.0.0.1:{$port}/hook"];
}

/** @param array{process: InvokedProcess, dir: string, log: string, url: string} $sink */
function p5StopSink(array $sink): void
{
    $sink['process']->stop();
    foreach (['secret', 'log.jsonl'] as $file) {
        if (is_file($sink['dir'].'/'.$file)) {
            unlink($sink['dir'].'/'.$file);
        }
    }
    rmdir($sink['dir']);
}

/**
 * @param  array{process: InvokedProcess, dir: string, log: string, url: string}  $sink
 * @return list<array<string, mixed>>
 */
function p5SinkLog(array $sink): array
{
    $lines = is_file($sink['log']) ? file($sink['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_map(fn (string $l): array => (array) json_decode($l, true), $lines === false ? [] : $lines);
}

/**
 * @param  array{process: InvokedProcess, dir: string, log: string, url: string}  $sink
 * @return array<string, mixed>
 */
function p5SinkSummary(array $sink): array
{
    $result = Process::run(['php', base_path('../scripts/webhook-sink/sink.php'), '--summary', $sink['log']]);

    return (array) json_decode(trim($result->output()), true);
}

function p5SinkEndpoint(string $url, ?string $secret = null): WebhookEndpoint
{
    $user = AlertRows::user('sink-'.bin2hex(random_bytes(3)).'@example.com');

    return WebhookEndpoint::query()->findOrFail(DeliveryRows::endpoint($user, $url, $secret));
}

beforeEach(function (): void {
    DeliveryRows::jitter('min');
});

it('reads no application class', function (): void {
    $source = (string) file_get_contents(base_path('../scripts/webhook-sink/sink.php'));

    expect($source)->toContain('declare(strict_types=1);')
        ->and($source)->not->toContain('App\\')
        ->and($source)->not->toContain('vendor/autoload');
});

it('verifies a signed delivery end to end and answers 204', function (): void {
    $sink = p5Sink(0);
    try {
        $delivery = TestMessage::queue(p5SinkEndpoint($sink['url']));
        [, $summary] = DeliveryRows::sweep();

        $row = DeliveryRows::row($delivery->id);
        expect($summary['delivered'])->toBe(1)
            ->and([$row->status, $row->last_status, $row->attempts])->toBe(['delivered', 204, 1])
            ->and(p5SinkLog($sink))->toBe([['id' => $delivery->message_id, 'attempt' => 1, 'verified' => true, 'answered' => 204, 'duplicate_after_success' => false]]);
    } finally {
        p5StopSink($sink);
    }
});

it('succeeds on attempt 3 after two injected 503s, with every request verified', function (): void {
    $sink = p5Sink(2);
    try {
        $delivery = TestMessage::queue(p5SinkEndpoint($sink['url']));
        DeliveryRows::sweep();
        $this->travel(15)->seconds();
        DeliveryRows::sweep();
        $this->travel(60)->seconds();
        DeliveryRows::sweep();

        $row = DeliveryRows::row($delivery->id);
        $summary = p5SinkSummary($sink);
        expect([$row->status, $row->attempts, $row->last_status])->toBe(['delivered', 3, 204])
            ->and(array_column(p5SinkLog($sink), 'answered'))->toBe([503, 503, 204])
            ->and($summary)->toBe([
                'requests' => 3, 'verified' => 3, 'unique_ids' => 1, 'delivered_ids' => 1, 'invalid' => 0,
                'duplicates_after_success' => 0, 'max_attempts' => 3,
            ]);
    } finally {
        p5StopSink($sink);
    }
});

it('answers 401 to a delivery signed with another key, and the delivery fails', function (): void {
    $sink = p5Sink(0);
    try {
        $wrong = WebhookSecret::PREFIX.base64_encode(str_repeat("\1", 32));
        $delivery = TestMessage::queue(p5SinkEndpoint($sink['url'], $wrong));
        DeliveryRows::sweep();

        $row = DeliveryRows::row($delivery->id);
        expect([$row->status, $row->last_status, $row->last_error])->toBe(['failed', 401, 'http_status'])
            ->and(p5SinkLog($sink)[0]['verified'])->toBeFalse()
            ->and(p5SinkSummary($sink)['invalid'])->toBe(1)
            ->and(p5SinkSummary($sink)['delivered_ids'])->toBe(0);
    } finally {
        p5StopSink($sink);
    }
});

it('counts a resend after a lost acknowledgement as a duplicate it drops', function (): void {
    $sink = p5Sink(0);
    try {
        $delivery = TestMessage::queue(p5SinkEndpoint($sink['url']));
        DeliveryRows::sweep();
        // The acknowledgement was lost: the row is pending again, so the sweep resends the same webhook-id.
        DB::update("UPDATE deliveries SET status = 'pending', delivered_at = NULL, next_attempt_at = ?::timestamptz WHERE id = ?", [now()->utc()->format('Y-m-d H:i:sP'), $delivery->id]);
        DeliveryRows::sweep();

        $log = p5SinkLog($sink);
        expect(array_column($log, 'id'))->toBe([$delivery->message_id, $delivery->message_id])
            ->and(array_column($log, 'answered'))->toBe([204, 204])
            ->and(array_column($log, 'duplicate_after_success'))->toBe([false, true])
            ->and(p5SinkSummary($sink))->toMatchArray(['requests' => 2, 'unique_ids' => 1, 'delivered_ids' => 1, 'duplicates_after_success' => 1, 'invalid' => 0]);
    } finally {
        p5StopSink($sink);
    }
});

it('rejects a request missing a header or with a stale timestamp, and never logs the secret, signature or body', function (): void {
    $sink = p5Sink(0);
    try {
        $body = '{"type":"webhook.test","data":{"message":"body-marker"}}';
        $id = 'msg_01J00000000000000000000000';
        $key = base64_decode(substr(AlertRows::secret(), strlen('whsec_')), true);
        $stale = (string) (time() - 400);
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$stale}.{$body}", (string) $key, true));

        $missing = Http::withBody($body, 'application/json')->withHeaders(['webhook-id' => $id])->post($sink['url']);
        $old = Http::withBody($body, 'application/json')
            ->withHeaders(['webhook-id' => $id, 'webhook-timestamp' => $stale, 'webhook-signature' => $signature])->post($sink['url']);

        $raw = (string) file_get_contents($sink['log']);
        expect($missing->status())->toBe(401)
            ->and($old->status())->toBe(401)
            ->and($raw)->not->toContain('body-marker')
            ->and($raw)->not->toContain('v1,')
            ->and($raw)->not->toContain('whsec_');
    } finally {
        p5StopSink($sink);
    }
});
