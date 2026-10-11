<?php

declare(strict_types=1);

it('echoes a valid incoming request id and marks the response as coming from the API', function (): void {
    $response = $this->withHeader('X-Request-Id', 'gate-req-0001')->get('/up');
    expect($response->headers->get('X-Request-Id'))->toBe('gate-req-0001')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api');
});

it('generates a ULID when no id is sent', function (): void {
    $id = $this->get('/up')->headers->get('X-Request-Id');
    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('replaces hostile or malformed ids', function (string $bad): void {
    $response = $this->withHeader('X-Request-Id', $bad)->get('/up');
    $id = (string) $response->headers->get('X-Request-Id');
    expect($id)->not->toBe($bad)
        ->and($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->has('X-Injected'))->toBeFalse();
})->with([
    'empty' => [''],
    'too short' => ['abc'],
    'spaces' => ['bad id value'],
    'crlf injection' => ["abcdefgh\r\nX-Injected: 1"],
    'oversized' => [str_repeat('a', 10240)],
    'non-ascii' => ['poképrices-id-1'],
]);

it('puts the same id in a problem body and its header', function (): void {
    $response = $this->withHeader('X-Request-Id', 'gate-req-0002')->get('/v1/missing');
    expect($response->json('request_id'))->toBe('gate-req-0002')
        ->and($response->headers->get('X-Request-Id'))->toBe('gate-req-0002')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api');
});

it('gives two requests two different generated ids', function (): void {
    $a = $this->get('/up')->headers->get('X-Request-Id');
    $b = $this->get('/up')->headers->get('X-Request-Id');
    expect($a)->not->toBe($b);
});
