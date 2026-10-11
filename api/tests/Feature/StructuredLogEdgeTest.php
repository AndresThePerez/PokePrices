<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->edgeFile = tempnam(sys_get_temp_dir(), 'pp-edge-');
    config([
        'logging.default' => 'stderr_json',
        'logging.channels.stderr_json.handler_with.stream' => $this->edgeFile,
    ]);
    Log::forgetChannel('stderr_json');
});

afterEach(function (): void {
    @unlink($this->edgeFile);
});

/** @return list<array<string, mixed>> */
function edgeLines(string $file): array
{
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

    return array_map(fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);
}

it('produces a request line whose id is a ULID and no hostile bytes for a hostile id', function (): void {
    $this->withHeaders([
        'X-Request-Id' => "x\r\n{\"level\":\"emergency\"}",
        'Authorization' => 'Bearer test-sentinel-0000',
    ])->get('/v1/does-not-exist');
    $raw = (string) file_get_contents($this->edgeFile);
    $requests = array_values(array_filter(edgeLines($this->edgeFile), fn (array $l): bool => $l['message'] === 'request'));
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['request_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($requests[0]['route'])->toBe('v1/does-not-exist')
        ->and($raw)->not->toContain('test-sentinel-0000')
        ->and($raw)->not->toContain('emergency');
})->group('O2');

it('keeps one valid JSON line for a message with newline, quotes and invalid UTF-8', function (): void {
    Log::warning("multi\nline \"quoted\" \xB1 bad");
    $lines = edgeLines($this->edgeFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning');
})->group('O2');

it('keeps one valid JSON line for nested context with newline and invalid UTF-8', function (): void {
    Log::warning('ctx', ['k' => "a\nb\xB1", 'nested' => ['deep' => ['x' => "\xB1"]]]);
    $lines = edgeLines($this->edgeFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toHaveKey('nested');
})->group('O2');

it('normalises throwable context and drops wrongly typed route and duration', function (): void {
    Log::error('boom', ['exception' => new RuntimeException('sentinel'), 'route' => 7, 'duration_ms' => '12']);
    $lines = edgeLines($this->edgeFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['route'])->toBe('')
        ->and($lines[0]['duration_ms'])->toBeNull()
        ->and($lines[0]['context'])->toHaveKey('exception');
})->group('O2');

it('writes exactly one request line with status 500 when the route throws', function (): void {
    Route::get('/v1/edge-boom', fn () => throw new RuntimeException('boom'));
    $this->get('/v1/edge-boom')->assertStatus(500);
    $requests = array_values(array_filter(edgeLines($this->edgeFile), fn (array $l): bool => $l['message'] === 'request'));
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['context']['status'])->toBe(500);
})->group('O2');

it('emits ts in UTC and lowercase levels regardless of app timezone', function (): void {
    config(['app.timezone' => 'America/Chicago']);
    Log::emergency('e');
    $lines = edgeLines($this->edgeFile);
    expect($lines[0]['ts'])->toEndWith('+00:00')
        ->and($lines[0]['level'])->toBe('emergency');
})->group('O2');

it('gives two sequential requests distinct ids and a duration with at most 3 decimals', function (): void {
    $this->get('/up');
    $this->get('/up');
    $requests = array_values(array_filter(edgeLines($this->edgeFile), fn (array $l): bool => $l['message'] === 'request'));
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['request_id'])->not->toBe($requests[1]['request_id'])
        ->and($requests[0]['route'])->toBe('up')
        ->and(strlen(substr((string) $requests[0]['duration_ms'], (int) strpos((string) $requests[0]['duration_ms'].'.', '.') + 1)))->toBeLessThanOrEqual(3);
})->group('O2');
