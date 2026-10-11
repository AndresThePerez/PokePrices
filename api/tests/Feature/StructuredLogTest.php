<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->logFile = tempnam(sys_get_temp_dir(), 'pp-log-');
    config([
        'logging.default' => 'stderr_json',
        'logging.channels.stderr_json.handler_with.stream' => $this->logFile,
    ]);
    Log::forgetChannel('stderr_json');
});

afterEach(function (): void {
    @unlink($this->logFile);
});

/** @return list<array<string, mixed>> */
function logLines(string $file): array
{
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

    return array_map(fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);
}

it('writes every log line as one JSON object with the required top-level keys', function (): void {
    $this->withHeader('X-Request-Id', 'gate-log-0001')->get('/v1/ready');
    Log::warning('probe line', ['x' => 1]);
    $lines = logLines($this->logFile);
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect($line)->toHaveKeys(['ts', 'level', 'message', 'request_id', 'route', 'duration_ms', 'context'])
            ->and($line['ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
    }
})->group('O2');

it('logs one request line with the request id, the route and a duration', function (): void {
    $this->withHeader('X-Request-Id', 'gate-log-0002')->get('/v1/ready');
    $request = array_values(array_filter(logLines($this->logFile), fn (array $l): bool => $l['message'] === 'request'));
    expect($request)->toHaveCount(1)
        ->and($request[0]['request_id'])->toBe('gate-log-0002')
        ->and($request[0]['route'])->toBe('v1/ready')
        ->and($request[0]['duration_ms'])->toBeNumeric()
        ->and($request[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
})->group('O2');

it('keeps a hostile request id out of the log and never logs credentials', function (): void {
    $this->withHeaders([
        'X-Request-Id' => "x\r\n{\"level\":\"emergency\"}",
        'Authorization' => 'Bearer test-sentinel-0000',
    ])->get('/v1/missing');
    $raw = (string) file_get_contents($this->logFile);
    expect($raw)->not->toContain('test-sentinel-0000')
        ->and($raw)->not->toContain('"level":"emergency"');
    foreach (logLines($this->logFile) as $line) {
        expect($line['request_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
})->group('O2');
