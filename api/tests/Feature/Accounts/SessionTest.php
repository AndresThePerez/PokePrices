<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 4 (sign in and out, the login throttle, D13 persistence) and criterion 16
// (reviews F-P4-02 JsonGuest, F-P4-04 email case, F-P4-05 input shapes).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/**
 * Forgets everything an earlier request left in memory: the resolved guards and the session store. The next
 * request then knows the person only through the session cookie it sends (D13).
 */
function sessionForgetMemory(): void
{
    Accounts::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

it('signs in with a case-insensitive email and answers {"two_factor": false}', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'Andres@Example.COM', 'password' => Accounts::PASSWORD]);

    $response->assertOk();
    expect($response->getContent())->toBe('{"two_factor":false}');
});

it('keeps the sign-in across two later requests from the session cookie alone, and ends it on sign-out (D13, R210)', function (): void {
    // The database driver, as on the dev stack, so the only link between requests is the cookie: the guards and
    // the session store are forgotten before every request (sessionForgetMemory), and each request sends the
    // session cookie the sign-in answer set (withCredentials: a JSON test request sends cookies only with it).
    config(['session.driver' => 'database']);
    sessionForgetMemory();
    Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');

    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);
    $login->assertOk();
    $sessionId = Accounts::sessionId($login);
    $setCookie = $login->getCookie($cookie);
    expect($sessionId)->toBeString()->not->toBe('')
        ->and($setCookie?->isHttpOnly())->toBeTrue()
        ->and($setCookie?->getSameSite())->toBe('lax');

    foreach ([1, 2] as $request) {
        sessionForgetMemory();
        $me = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, (string) $sessionId)->getJson('/v1/me');
        expect($me->getStatusCode())->toBe(200)
            ->and($me->json('data.email'))->toBe('andres@example.com');
    }

    sessionForgetMemory();
    $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, (string) $sessionId)->postJson('/logout')->assertNoContent();

    sessionForgetMemory();
    $after = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, (string) $sessionId)->getJson('/v1/me');
    expect($after->getStatusCode())->toBe(401)
        ->and($after->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

it('names the session cookie config(session.cookie), HttpOnly and SameSite=lax', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);
    $cookie = $login->getCookie((string) config('session.cookie'), false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax');
});

it('answers a wrong password with 422 errors.email and the generic message', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->postJson('/login', ['email' => 'andres@example.com', 'password' => 'wrong-password-000']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['email' => [__('auth.failed')]]);
});

it('answers 422, never 500, for a login email that is not a string (F5)', function (mixed $email): void {
    $response = $this->postJson('/login', ['email' => $email, 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email');
})->with([
    'an array' => [['andres@example.com']],
    'an int' => [42],
    'null' => [null],
]);

it('throttles the sixth wrong sign-in for one email from one IP with 429 and Retry-After, keyed by email and IP', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    $ip = ['REMOTE_ADDR' => '192.0.2.20'];

    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables($ip)->postJson('/login', ['email' => 'andres@example.com', 'password' => 'wrong-password-000'])
            ->assertStatus(422);
    }
    $sixth = $this->withServerVariables($ip)->postJson('/login', ['email' => 'andres@example.com', 'password' => 'wrong-password-000']);
    $otherEmail = $this->withServerVariables($ip)->postJson('/login', ['email' => 'other@example.com', 'password' => 'wrong-password-000']);
    $otherIp = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.21'])->postJson('/login', ['email' => 'andres@example.com', 'password' => 'wrong-password-000']);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $sixth->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($otherEmail->getStatusCode())->toBe(422)
        ->and($otherIp->getStatusCode())->toBe(422);
});

it('counts A@example.com and a@example.com in one login bucket (F4)', function (): void {
    $ip = ['REMOTE_ADDR' => '192.0.2.22'];
    foreach (['A@example.com', 'a@example.com', 'A@EXAMPLE.COM', 'a@Example.com', ' a@example.com '] as $email) {
        $this->withServerVariables($ip)->postJson('/login', ['email' => $email, 'password' => 'wrong-password-000'])->assertStatus(422);
    }

    $this->withServerVariables($ip)->postJson('/login', ['email' => 'a@example.com', 'password' => 'wrong-password-000'])
        ->assertStatus(429);
});

it('answers a signed-in person posting to a guest route with 409 already-signed-in, never a redirect, and changes nothing (F2)', function (string $path, array $body): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);
    $hash = $user->password;
    $body = array_map(fn (mixed $v): mixed => $v === '{token}' ? $token : $v, $body);

    $response = $this->actingAs($user)->postJson($path, $body);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->has('Location'))->toBeFalse()
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'already-signed-in')
        ->and($response->json('title'))->toBe('Already Signed In')
        ->and($response->json('status'))->toBe(409)
        ->and(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->password)->toBe($hash)
        ->and(Hash::check(Accounts::PASSWORD, User::query()->sole()->password))->toBeTrue();
    Notification::assertNothingSent();
})->with([
    '/login' => ['/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]],
    '/register' => ['/register', ['invite_code' => Accounts::INVITE, 'email' => 'new@example.com', 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD]],
    '/forgot-password' => ['/forgot-password', ['email' => 'andres@example.com']],
    '/reset-password' => ['/reset-password', ['token' => '{token}', 'email' => 'andres@example.com', 'password' => 'another-password-000', 'password_confirmation' => 'another-password-000']],
    '/two-factor-challenge' => ['/two-factor-challenge', ['code' => '000000']],
]);
