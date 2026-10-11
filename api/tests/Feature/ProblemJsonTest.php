<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Support\Facades\Route;

it('answers an unknown /v1 path with problem+json even without an Accept header', function (): void {
    $response = $this->get('/v1/does-not-exist');
    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'not-found')
        ->and($response->json('status'))->toBe(404)
        ->and($response->json('title'))->toBe('Not Found')
        ->and($response->json('instance'))->toBe('/v1/does-not-exist')
        ->and(array_key_exists('request_id', $response->json()))->toBeTrue();
});

it('answers the Fortify login path with a Laravel problem+json 404 while Fortify is absent (R24)', function (): void {
    $response = $this->post('/login');
    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('does not serve the old api/ prefix', function (): void {
    $this->get('/api/v1/ready')->assertNotFound();
});

it('answers a wrong method with 405 problem+json', function (): void {
    $response = $this->post('/up');
    $response->assertStatus(405);
    expect($response->json('type'))->toBe(ProblemDetails::BASE.'method-not-allowed');
});

it('never leaks an exception message, class or trace in a 500', function (): void {
    Route::get('/v1/__boom', fn () => throw new RuntimeException('sentinel-do-not-leak'));
    $response = $this->get('/v1/__boom');
    $response->assertStatus(500);
    $body = $response->getContent();
    expect($response->json('type'))->toBe(ProblemDetails::BASE.'server-error')
        ->and($body)->not->toContain('sentinel-do-not-leak')
        ->and($body)->not->toContain('RuntimeException')
        ->and($response->json())->not->toHaveKeys(['trace', 'exception', 'file', 'line']);
});

it('no longer serves the skeleton welcome page at /', function (): void {
    $this->get('/')->assertNotFound();
});
