<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\Support\Accounts;

// Reviewer edge cases for P4-i1-T02: auth bypass, hostile input shapes, throttle keys, reset replay, guest GETs.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** Forgets the resolved guards and the session store, so only the session cookie links two requests (D13). */
function edgeForgetMemory(): void
{
    Accounts::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

it('refuses user A\'s verification link replayed by signed-in user B with 403 and verifies nobody', function (): void {
    $a = Accounts::user(['email' => 'a@example.com', 'email_verified_at' => null]);
    $b = Accounts::user(['email' => 'b@example.com', 'email_verified_at' => null]);
    $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $a->getKey(), 'hash' => sha1($a->email)]);
    $link = (string) parse_url($signed, PHP_URL_PATH).'?'.(string) parse_url($signed, PHP_URL_QUERY);

    $this->actingAs($b);
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->getJson($link);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($a->refresh()->email_verified_at)->toBeNull()
        ->and($b->refresh()->email_verified_at)->toBeNull();
});

it('answers a two-factor challenge with no pending sign-in with a 4xx problem, never 500, and signs nobody in', function (array $body): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', $body);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertStatus(401);
})->with([
    'code' => [['code' => '123456']],
    'recovery code' => [['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb']],
    'array code' => [['code' => ['1', '2']]],
    'empty body' => [[]],
]);

it('answers hostile password, token and confirmation shapes with 4xx, never 500', function (string $path, array $body): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $body += ['email' => 'andres@example.com'];
    config(['accounts.invite_code' => Accounts::INVITE]);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson($path, $body);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
        ->and($user->refresh()->password)->not->toBeEmpty();
})->with([
    'login password array' => ['/login', ['password' => ['x']]],
    'login password int' => ['/login', ['password' => 123456789012]],
    'login password bool' => ['/login', ['password' => true]],
    'login password null' => ['/login', ['password' => null]],
    'login password nested' => ['/login', ['password' => ['a' => ['b' => 'c']]]],
    'reset token array' => ['/reset-password', ['token' => ['x'], 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD]],
    'reset token int' => ['/reset-password', ['token' => 1, 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD]],
    'reset token null' => ['/reset-password', ['token' => null, 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD]],
    'reset password array' => ['/reset-password', ['token' => 'x', 'password' => ['y'], 'password_confirmation' => ['y']]],
    'register confirmation array' => ['/register', ['invite_code' => Accounts::INVITE, 'email' => 'new@example.com', 'password' => Accounts::PASSWORD, 'password_confirmation' => ['x']]],
    'register password array' => ['/register', ['invite_code' => Accounts::INVITE, 'email' => 'new@example.com', 'password' => ['x'], 'password_confirmation' => ['x']]],
    'login oversized email' => ['/login', ['email' => str_repeat('a', 100_000).'@example.com', 'password' => 'x']],
]);

it('answers login and forgot-password with a NUL or invalid-UTF-8 email without a 500', function (string $path, string $email): void {
    $response = $this->withHeaders(['Accept' => 'application/json'])->post($path, ['email' => $email, 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBeLessThan(500);
})->with([
    'login NUL' => ['/login', "an\0dres@example.com"],
    'login invalid UTF-8' => ['/login', "an\xFFdres@example.com"],
    'forgot NUL' => ['/forgot-password', "an\0dres@example.com"],
    'forgot invalid UTF-8' => ['/forgot-password', "an\xFFdres@example.com"],
]);

it('refuses a registration email that holds a NUL byte: 422 errors.email and no account (the database would truncate it)', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => "an\0dres@example.com",
        'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email')
        ->and(User::query()->count())->toBe(0);
});

it('does not honour a valid session cookie on a request that is not first-party', function (string $referer): void {
    config(['session.driver' => 'database']);
    edgeForgetMemory();
    Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);
    $login->assertOk();
    $sessionId = (string) Accounts::sessionId($login);

    edgeForgetMemory();
    $this->flushHeaders();
    $headers = ['Accept' => 'application/json'] + ($referer === '' ? [] : ['Referer' => $referer, 'Origin' => rtrim($referer, '/')]);
    $response = $this->withHeaders($headers)->withCredentials()->withCookie($cookie, $sessionId)->getJson('/v1/me');

    expect($response->getStatusCode())->toBe(401);

    edgeForgetMemory();
    $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $sessionId)->getJson('/v1/me')->assertOk();
})->with([
    'no referer' => [''],
    'other site' => ['http://evil.example.com/'],
    'suffix of the first-party host' => ['http://pokeprices.localhost:8095.evil.example.com/'],
    'prefix of the first-party host' => ['http://evil.pokeprices.localhost:8095/'],
    'other port' => ['http://pokeprices.localhost:9999/'],
]);

it('answers 401 when the session belongs to a user who has since been deleted', function (): void {
    config(['session.driver' => 'database']);
    edgeForgetMemory();
    $user = Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]);
    $login->assertOk();
    $sessionId = (string) Accounts::sessionId($login);
    DB::table('users')->where('id', $user->getKey())->delete();

    edgeForgetMemory();
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $sessionId)->getJson('/v1/me');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

it('keys the password-confirm limiter by user: one person\'s failures from an IP do not lock another person out', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);

    $this->actingAs($a);
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/user/confirm-password', ['password' => 'wrong-password-'.$i])->assertStatus(422);
    }
    expect($this->postJson('/user/confirm-password', ['password' => 'wrong-password-6'])->getStatusCode())->toBe(429);

    Accounts::forgetGuards();
    $this->actingAs($b);
    $this->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
});

it('refuses a reset token with the wrong email, a token replay, and another person\'s email, changing no password', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $before = [$a->password, $b->password];
    $newPassword = 'another-fake-password-1';

    $token = Password::broker()->createToken($a);
    $wrongEmail = $this->postJson('/reset-password', ['token' => $token, 'email' => 'b@example.com', 'password' => $newPassword, 'password_confirmation' => $newPassword]);
    $unknown = $this->postJson('/reset-password', ['token' => $token, 'email' => 'nobody@example.com', 'password' => $newPassword, 'password_confirmation' => $newPassword]);
    expect($wrongEmail->getStatusCode())->toBe(422)
        ->and($wrongEmail->json('errors'))->toHaveKey('email')
        ->and($unknown->getStatusCode())->toBe(422)
        ->and([$a->refresh()->password, $b->refresh()->password])->toBe($before);

    $this->postJson('/reset-password', ['token' => $token, 'email' => 'a@example.com', 'password' => $newPassword, 'password_confirmation' => $newPassword])->assertOk();
    expect(Hash::check($newPassword, $a->refresh()->password))->toBeTrue();

    $replay = $this->postJson('/reset-password', ['token' => $token, 'email' => 'a@example.com', 'password' => 'yet-another-fake-pw-2', 'password_confirmation' => 'yet-another-fake-pw-2']);
    expect($replay->getStatusCode())->toBe(422)
        ->and(Hash::check($newPassword, $a->refresh()->password))->toBeTrue();
});

it('does not tell a registered email from an unregistered one on POST /reset-password (a bad token answers alike)', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    $body = ['token' => 'not-a-real-token', 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD];

    $known = $this->postJson('/reset-password', ['email' => 'andres@example.com'] + $body);
    $unknown = $this->postJson('/reset-password', ['email' => 'nobody@example.com'] + $body);

    expect($known->getStatusCode())->toBe(422)
        ->and($unknown->getStatusCode())->toBe(422)
        ->and($unknown->json('errors'))->toBe($known->json('errors'));
});

it('answers signed-out GETs without an Accept header with a problem or a plain status, never a redirect or a 500', function (string $path): void {
    $response = $this->get($path);

    expect($response->getStatusCode())->not->toBeBetween(300, 399)->not->toBeGreaterThanOrEqual(500)
        ->and($response->headers->has('Location'))->toBeFalse();
    if ($response->getStatusCode() >= 400) {
        expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    }
})->with([
    '/v1/me',
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/reset-password/some-token',
    '/two-factor-challenge',
    '/user/two-factor-qr-code',
    '/user/two-factor-secret-key',
    '/user/two-factor-recovery-codes',
    '/user/confirm-password',
    '/user/confirmed-password-status',
    '/email/verify',
    '/email/verify/1/abc',
    '/email/verification-notification',
    '/sanctum/csrf-cookie',
]);

it('turns a unique-violation race on registration into a 422 errors.email, never a 500', function (): void {
    // Two submits of the same email can both pass the validation lookup; the lower(email) index then stops the second.
    // The race is simulated by a row that appears between validation and the insert.
    User::creating(static function (User $user): void {
        DB::table('users')->insert(['email' => 'Raced@Example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    });

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => 'raced@example.com',
        'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email');
});
