<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 8 (GET /v1/me, D6), criterion 9 (public reads stay cookie-free, D1) and criterion 16
// (review F-P4-07: the account-guest limiter runs before any session starts).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

it('answers GET /v1/me with exactly email, email_verified, two_factor and read_only, no-store and no ETag', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com', 'name' => 'Should Not Show']);
    app(EnableTwoFactorAuthentication::class)($user);

    $response = $this->actingAs($user->refresh())->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    $response->assertOk();
    expect($response->json())->toBe(['data' => [
        'email' => 'andres@example.com', 'email_verified' => true, 'two_factor' => false, 'read_only' => false,
    ]])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->has('ETag'))->toBeFalse();
    foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'name', 'Should Not Show'] as $secret) {
        expect((string) $response->getContent())->not->toContain($secret);
    }
});

it('says two_factor true only once two-factor is confirmed, and email_verified false before verification', function (): void {
    $user = Accounts::user(['email_verified_at' => null, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.two_factor', true);
});

it('answers signed out with the 401 unauthenticated problem, with and without Accept', function (array $headers): void {
    $response = $this->withHeaders($headers)->get('/v1/me');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($response->json('status'))->toBe(401);
})->with([
    'first party, JSON' => [Accounts::FIRST_PARTY],
    'no headers' => [[]],
]);

it('answers a bearer token with no session with 401, never 500 (no API tokens)', function (): void {
    Accounts::user();

    $response = $this->withHeaders(['Authorization' => 'Bearer 1|example0000000000000000', 'Accept' => 'application/json'])->getJson('/v1/me');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

it('keeps public reads cookie-free with their caching, even with a first-party Referer and a valid session cookie (D1)', function (string $path): void {
    Http::fake();
    Accounts::user(['email' => 'andres@example.com']);
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);
    $sessionId = (string) Accounts::sessionId($login);
    expect($sessionId)->not->toBe('');
    Accounts::forgetGuards();

    $plain = $this->get($path);
    $stateful = $this->withHeaders(['Referer' => Accounts::ORIGIN.'/', 'Accept' => 'application/json'])
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->get($path);

    expect($plain->getStatusCode())->toBe(200)
        ->and($stateful->getStatusCode())->toBe(200)
        ->and($stateful->headers->has('Set-Cookie'))->toBeFalse()
        ->and($stateful->headers->getCookies())->toBe([])
        ->and($plain->headers->getCookies())->toBe([])
        ->and($stateful->headers->get('Cache-Control'))->toBe($plain->headers->get('Cache-Control'))
        ->and($stateful->headers->get('ETag'))->toBe($plain->headers->get('ETag'))
        ->and($plain->headers->get('ETag'))->not->toBeNull();
})->with(['/v1/movers', '/v1/cards/zz9-999/prices?range=1M']);

it('answers the 61st anonymous first-party GET /v1/me from one IP with 429, starting at most 60 sessions (F7)', function (): void {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $before = DB::table('sessions')->count();
    $ip = ['REMOTE_ADDR' => '192.0.2.50'];

    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertStatus(401);
    }
    $last = $this->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    expect($last->getStatusCode())->toBe(429)
        ->and($last->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $last->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($last->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count() - $before)->toBeLessThanOrEqual(60);
});

it('puts account-guest on /sanctum/csrf-cookie, /forgot-password and /reset-password: the 61st from one IP is 429 (F7)', function (string $method, string $path, array $body, int $status): void {
    $ip = ['REMOTE_ADDR' => '192.0.2.51'];

    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables($ip)->json($method, $path, $body)->assertStatus($status);
    }
    $last = $this->withServerVariables($ip)->json($method, $path, $body);
    $otherIp = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52'])->json($method, $path, $body);

    expect($last->getStatusCode())->toBe(429)
        ->and($last->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($otherIp->getStatusCode())->toBe($status);
})->with([
    'csrf cookie' => ['GET', '/sanctum/csrf-cookie', [], 204],
    'forgot password' => ['POST', '/forgot-password', ['email' => 'nobody@example.com'], 200],
    'reset password' => ['POST', '/reset-password', ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0'], 422],
]);
