<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\FinalizeDay;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\AlertRows;

// Plan Q1-i4 T07 review round 1: the webhook sink's test-only SINK_NOW clock, the repository-wide stray-request
// re-arm after a bare factory swap, and the guarded FinalizeDay paths that must neither fail, delete nor release
// the job. Real HTTP goes to loopback only, against the real sink script.

/**
 * The real sink on a free loopback port, with SINK_NOW set only when $env says so.
 *
 * @param  array<string, string>  $env
 * @return array{process: InvokedProcess, dir: string, port: int}
 */
function q1t7rSink(array $env = []): array
{
    $dir = base_path('../.gates/tmp/Q1-i4-T07-rev-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/secret', AlertRows::secret());
    $server = stream_socket_server('tcp://127.0.0.1:0');
    assert(is_resource($server));
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $process = Process::path($dir)
        ->env($env + ['SINK_SECRET_FILE' => $dir.'/secret', 'SINK_LOG' => $dir.'/log.jsonl', 'SINK_FAIL_FIRST' => '0'])
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
    Http::preventStrayRequests();
    Http::allowStrayRequests(["http://127.0.0.1:{$port}/*"]);

    return ['process' => $process, 'dir' => $dir, 'port' => $port];
}

/** @param array{process: InvokedProcess, dir: string, port: int} $sink */
function q1t7rStop(array $sink): void
{
    $sink['process']->stop();
    foreach (glob($sink['dir'].'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($sink['dir']);
}

/** The sink's answer to a request signed at $timestamp (a Unix time as a string). @param array{port: int} $sink */
function q1t7rPost(array $sink, string $timestamp): int
{
    $body = '{"type":"webhook.test"}';
    $key = (string) base64_decode(substr(AlertRows::secret(), strlen('whsec_')), true);
    $signature = 'v1,'.base64_encode(hash_hmac('sha256', "msg_clock.{$timestamp}.{$body}", $key, true));

    return Http::withHeaders(['webhook-id' => 'msg_clock', 'webhook-timestamp' => $timestamp, 'webhook-signature' => $signature])
        ->withBody($body, 'application/json')->post("http://127.0.0.1:{$sink['port']}/hook")->status();
}

it('measures the 300 s window from SINK_NOW in both directions, exactly at the limit and one second past it', function (): void {
    $now = 1_792_065_600;
    $sink = q1t7rSink(['SINK_NOW' => (string) $now]);
    try {
        expect([
            'same second' => q1t7rPost($sink, (string) $now),
            '300 s old' => q1t7rPost($sink, (string) ($now - 300)),
            '300 s ahead' => q1t7rPost($sink, (string) ($now + 300)),
            '301 s old' => q1t7rPost($sink, (string) ($now - 301)),
            '301 s ahead' => q1t7rPost($sink, (string) ($now + 301)),
        ])->toBe(['same second' => 204, '300 s old' => 204, '300 s ahead' => 204, '301 s old' => 401, '301 s ahead' => 401]);
    } finally {
        q1t7rStop($sink);
    }
});

it('lets SINK_NOW replace the wall clock rather than add to it: a request signed with the real time is stale against a pinned past clock', function (): void {
    $sink = q1t7rSink(['SINK_NOW' => '1000000000']);
    try {
        expect(q1t7rPost($sink, (string) time()))->toBe(401)
            ->and(q1t7rPost($sink, '1000000000'))->toBe(204);
    } finally {
        q1t7rStop($sink);
    }
});

it('reads the wall clock when SINK_NOW is not set, so the 300 s window follows time()', function (): void {
    $sink = q1t7rSink();
    try {
        expect([
            'now' => q1t7rPost($sink, (string) time()),
            '100 s old' => q1t7rPost($sink, (string) (time() - 100)),
            '400 s old' => q1t7rPost($sink, (string) (time() - 400)),
            '400 s ahead' => q1t7rPost($sink, (string) (time() + 400)),
        ])->toBe(['now' => 204, '100 s old' => 204, '400 s old' => 401, '400 s ahead' => 401]);
    } finally {
        q1t7rStop($sink);
    }
});

it('falls back to the wall clock for a SINK_NOW that is not 1 to 12 digits, instead of pinning the clock to a bad value', function (string $value): void {
    $sink = q1t7rSink(['SINK_NOW' => $value]);
    try {
        expect([q1t7rPost($sink, (string) time()), q1t7rPost($sink, (string) (time() - 1000))])->toBe([204, 401]);
    } finally {
        q1t7rStop($sink);
    }
})->with([
    'empty' => [''],
    'letters' => ['abc'],
    'exponent form' => ['1e9'],
    'negative' => ['-5'],
    'trailing space' => ['1000000000 '],
    'trailing newline' => ["1000000000\n"],
    'thirteen digits' => ['1000000000000'],
]);

it('treats a SINK_NOW of 0 as the Unix epoch, not as unset', function (): void {
    $sink = q1t7rSink(['SINK_NOW' => '0']);
    try {
        expect([q1t7rPost($sink, '300'), q1t7rPost($sink, '301'), q1t7rPost($sink, (string) time())])->toBe([204, 401, 401]);
    } finally {
        q1t7rStop($sink);
    }
});

it('keeps SINK_NOW out of everything but the test sink: no application, config, route, compose or workflow file reads it', function (): void {
    $roots = [base_path('app'), base_path('config'), base_path('routes'), base_path('bootstrap'), base_path('database'),
        base_path('../scripts'), base_path('../.github'), base_path('../compose.dev.yml'), base_path('../Makefile')];
    $readers = [];
    foreach ($roots as $root) {
        $files = is_dir($root)
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)), false)
            : [new SplFileInfo($root)];
        foreach ($files as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'SINK_NOW')) {
                $readers[] = substr($file->getPathname(), strlen(base_path('..')) + 1);
            }
        }
    }

    expect($readers)->toBe(['scripts/webhook-sink/sink.php']);
});

it('re-arms the stray-request guard after every bare Http::swap in the tests, bar the one that says it allows strays', function (): void {
    $unarmed = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'SinkClockReviewTest.php') {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'Http::swap(') && ! str_contains(implode("\n", array_slice($lines, $i + 1, 2)), 'Http::preventStrayRequests()')
                && ! str_contains(implode("\n", array_slice($lines, $i + 1, 2)), 'Http::allowStrayRequests()')) {
                $unarmed[] = substr($file->getPathname(), strlen(base_path('tests')) + 1).':'.($i + 1);
            }
        }
    }

    expect($unarmed)->toBe([]);
});

it('refuses a request nothing faked after a bare factory swap that re-arms the guard, instead of sending it', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);

    expect(Http::post('https://hooks.example.com/a')->status())->toBe(204);
    expect(fn () => Http::get('http://203.0.113.9/unfaked'))->toThrow(RuntimeException::class, 'Attempted request');
});

it('leaves a run that is no longer running exactly as it is: FinalizeDay neither finalizes, fails, deletes, releases nor throws', function (string $status): void {
    $runId = DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-11',
        'marker' => '2026-10-11 20:05:45+00', 'mode' => 'queued', 'status' => $status, 'started_at' => '2026-10-11 21:00:00+00']);
    $finalize = new FinalizeDay($runId, 'database');
    $finalize->withFakeQueueInteractions();

    $finalize->handle();

    $finalize->assertNotReleased();
    $finalize->assertNotFailed();
    $finalize->assertNotDeleted();
    expect(DB::table('ingest_runs')->where('id', $runId)->value('status'))->toBe($status)
        ->and(DB::table('ingest_runs')->where('id', $runId)->value('finished_at'))->toBeNull();
})->with(['ok', 'failed', 'superseded', 'skipped_unchanged']);

it('leaves a vanished run alone: FinalizeDay for an id with no row neither fails, deletes, releases nor throws', function (): void {
    $finalize = new FinalizeDay(999_999_999, 'database');
    $finalize->withFakeQueueInteractions();

    $finalize->handle();

    $finalize->assertNotReleased();
    $finalize->assertNotFailed();
    $finalize->assertNotDeleted();
    expect(DB::table('ingest_runs')->count())->toBe(0);
});
