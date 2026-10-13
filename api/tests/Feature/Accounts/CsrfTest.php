<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 10 (CSRF, D1, D4) and criterion 16 (review note: fetch metadata).
//
// Mechanism: Laravel skips the CSRF check while running unit tests (PreventRequestForgery::runningUnitTests).
// Each test here binds PreventRequestForgery in the container to a test-only subclass whose runningUnitTests()
// answers false, so the web group and Sanctum's stateful pipeline (both resolve that class) enforce the token.

beforeEach(function (): void {
    Accounts::setUp();
    app()->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
});

it('answers a stateful sign-in without an XSRF token, and without Sec-Fetch-Site, with 419 csrf-token-mismatch', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(419)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'csrf-token-mismatch')
        ->and($response->json('title'))->toBe('CSRF Token Mismatch')
        ->and($response->json('status'))->toBe(419);
});

it('answers Sec-Fetch-Site: cross-site without a token with 419 too', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY + ['Sec-Fetch-Site' => 'cross-site'])
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(419)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'csrf-token-mismatch');
});

it('answers a wrong X-XSRF-TOKEN with 419 and registers nobody', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY + ['X-XSRF-TOKEN' => 'not-a-token'])->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => 'new@example.com',
        'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ]);

    expect($response->getStatusCode())->toBe(419)
        ->and(User::query()->count())->toBe(0);
});

it('accepts the X-XSRF-TOKEN the csrf-cookie answer set (the check is enforced, not bypassed)', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $csrf = $this->withHeaders(Accounts::FIRST_PARTY)->get('/sanctum/csrf-cookie');
    $csrf->assertNoContent();
    $token = $csrf->getCookie('XSRF-TOKEN', false)?->getValue();
    expect($token)->toBeString();

    $this->withHeaders(Accounts::FIRST_PARTY + ['X-XSRF-TOKEN' => (string) $token])
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])
        ->assertOk();
});
