<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

it('carries errors and status 422 for a validation failure', function (): void {
    Route::post('/v1/__validate', function (Request $request): never {
        $request->validate(['name' => 'required']);
        throw new LogicException('unreachable');
    });
    $response = $this->postJson('/v1/__validate', []);
    $response->assertStatus(422);
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('status'))->toBe(422)
        ->and($response->json('errors.name'))->toBeArray()->not->toBeEmpty();
});

it('keeps a well-formed body for an unlisted http status and maps 403 and 401 slugs', function (): void {
    Route::get('/v1/__a499', fn () => abort(499));
    Route::get('/v1/__a403', fn () => abort(403));
    Route::get('/v1/__a401', fn () => abort(401));
    $r499 = $this->get('/v1/__a499');
    $r403 = $this->get('/v1/__a403');
    $r401 = $this->get('/v1/__a401');
    expect($r499->getStatusCode())->toBe(499)
        ->and($r499->json('type'))->toBe(ProblemDetails::BASE.'http-499')
        ->and($r499->json('title'))->toBe('Error')
        ->and($r403->json('type'))->toBe(ProblemDetails::BASE.'http-403')
        ->and($r403->json('title'))->toBe('Forbidden')
        ->and($r401->json('type'))->toBe(ProblemDetails::BASE.'http-401')
        ->and($r401->json('title'))->toBe('Unauthorized');
});

it('answers Accept text/html with problem+json and strips the query from instance', function (): void {
    $response = $this->get('/v1/a/b/c?x=1&y=2', ['Accept' => 'text/html']);
    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('instance'))->toBe('/v1/a/b/c')
        ->and($response->getContent())->not->toContain('<html');
});

it('does not leak the exception message in a 500 with debug on and off', function (): void {
    Route::get('/v1/__boom2', fn () => throw new RuntimeException('sentinel-edge-leak'));
    foreach ([true, false] as $debug) {
        config(['app.debug' => $debug]);
        $response = $this->get('/v1/__boom2');
        expect($response->getStatusCode())->toBe(500)
            ->and($response->getContent())->not->toContain('sentinel-edge-leak');
    }
});

it('answers an unauthenticated exception with 401 and not a 500', function (): void {
    Route::get('/v1/__unauth', fn () => throw new AuthenticationException);
    $response = $this->get('/v1/__unauth');
    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('answers a thrown ValidationException built by hand with the errors map', function (): void {
    Route::get('/v1/__manual', fn () => throw ValidationException::withMessages(['q' => ['bad']]));
    $response = $this->get('/v1/__manual');
    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['q' => ['bad']]);
});

it('keeps a unicode title as raw UTF-8 and never lets extras overwrite required keys', function (): void {
    $response = ProblemDetails::response(418, 'teapot', 'PokéPrices', Request::create('/v1/x?a=b'), null, ['status' => 1, 'type' => 'x', 'k' => 'v']);
    $content = (string) $response->getContent();
    expect($content)->toContain('PokéPrices')
        ->and(json_decode($content, true))->toMatchArray(['status' => 418, 'type' => ProblemDetails::BASE.'teapot', 'k' => 'v', 'instance' => '/v1/x']);
});
