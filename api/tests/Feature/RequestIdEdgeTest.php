<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('keeps ids of exactly 8 and 128 characters and replaces 7 and 129', function (string $id, bool $kept): void {
    $got = (string) $this->withHeader('X-Request-Id', $id)->get('/up')->headers->get('X-Request-Id');
    if ($kept) {
        expect($got)->toBe($id);
    } else {
        expect($got)->not->toBe($id)->and($got)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
})->with([
    '7 chars' => ['abcdefg', false],
    '8 chars' => ['abcdefgh', true],
    '128 chars' => [str_repeat('a', 128), true],
    '129 chars' => [str_repeat('a', 129), false],
    'dot allowed inside' => ['a.b_c-d.e', true],
]);

it('replaces ids with a bad first character, a NUL, a tab or a trailing newline', function (string $bad): void {
    $got = (string) $this->withHeader('X-Request-Id', $bad)->get('/up')->headers->get('X-Request-Id');
    expect($got)->not->toBe($bad)->and($got)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
})->with([
    'leading dot' => ['.abcdefgh'],
    'leading dash' => ['-abcdefgh'],
    'leading underscore' => ['_abcdefgh'],
    'nul inside' => ["abcd\0efgh"],
    'tab inside' => ["abcd\tefgh"],
    'trailing newline' => ["abcdefgh\n"],
    'comma joined' => ['abcdefgh, ijklmnop'],
]);

it('keeps one id across header, body and context on a 500 thrown inside a route', function (): void {
    Route::get('/v1/__boom', function (): never {
        throw new RuntimeException('secret-sentinel-0000');
    });
    $response = $this->withHeader('X-Request-Id', 'boom-req-0001')->get('/v1/__boom');
    $response->assertStatus(500);
    expect($response->headers->get('X-Request-Id'))->toBe('boom-req-0001')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api')
        ->and($response->json('request_id'))->toBe('boom-req-0001')
        ->and((string) $response->getContent())->not->toContain('secret-sentinel-0000');
});

it('keeps the id on a 422 validation failure and a 405', function (): void {
    Route::post('/v1/__val', function (Request $request): never {
        $request->validate(['name' => 'required']);
        throw new LogicException('unreachable');
    });
    $r422 = $this->withHeader('X-Request-Id', 'val-req-00001')->postJson('/v1/__val', []);
    $r405 = $this->withHeader('X-Request-Id', 'val-req-00002')->post('/up');
    expect($r422->getStatusCode())->toBe(422)
        ->and($r422->headers->get('X-Request-Id'))->toBe('val-req-00001')
        ->and($r422->json('request_id'))->toBe('val-req-00001')
        ->and($r405->getStatusCode())->toBe(405)
        ->and($r405->headers->get('X-Request-Id'))->toBe('val-req-00002')
        ->and($r405->json('request_id'))->toBe('val-req-00002')
        ->and($r405->headers->get('X-PokePrices-Origin'))->toBe('api');
});

it('does not leak a previous request id into the next request without a header', function (): void {
    Route::get('/v1/__ctx', fn (): string => (string) Context::get('request_id'));
    $first = $this->withHeader('X-Request-Id', 'first-req-0001')->get('/v1/__ctx');
    $this->flushHeaders();
    $second = $this->get('/v1/__ctx');
    $secondId = (string) $second->headers->get('X-Request-Id');
    expect($first->getContent())->toBe('first-req-0001')
        ->and($secondId)->not->toBe('first-req-0001')
        ->and($second->getContent())->toBe($secondId);
});

it('marks non-v1 paths and streamed or file responses too', function (): void {
    Route::get('/__stream', fn (): StreamedResponse => response()->stream(function (): void {
        echo 'x';
    }));
    Route::get('/__file', fn (): BinaryFileResponse => response()->file(base_path('composer.json')));
    foreach (['/login', '/__stream', '/__file'] as $path) {
        $response = $this->withHeader('X-Request-Id', 'path-req-0001')->get($path);
        expect($response->headers->get('X-Request-Id'))->toBe('path-req-0001', $path)
            ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api', $path);
    }
});

it('never echoes a hostile id in the problem instance or body', function (): void {
    $bad = 'bad id <script>';
    $response = $this->withHeader('X-Request-Id', $bad)->get('/v1/missing');
    expect((string) $response->getContent())->not->toContain('<script>')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});
