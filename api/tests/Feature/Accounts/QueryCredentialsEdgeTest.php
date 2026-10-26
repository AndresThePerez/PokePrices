<?php

declare(strict_types=1);

use App\Http\Middleware\IgnoreQueryCredentials;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\Support\Accounts;

// Reviewer edge cases for plan Q1-i1 T04 (review a1, sec A4): the credential-bearing writes read nothing from the
// query string, whatever the spelling of the path or the method, and the same value in the body still works.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** A user with confirmed two-factor authentication and recovery codes, set up by Fortify's own action. */
function qceTwoFactorUser(): User
{
    $user = Accounts::user(['email' => 'andres@example.com']);
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

it('does not accept a recovery_code that travels only in the query string of POST /two-factor-challenge', function (): void {
    $user = qceTwoFactorUser();
    $code = $user->recoveryCodes()[0];
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => true]);
    Accounts::forgetGuards();

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/two-factor-challenge?recovery_code='.rawurlencode($code), []);

    expect($response->getStatusCode())->toBe(422)
        ->and(auth('web')->check())->toBeFalse()
        ->and($user->refresh()->recoveryCodes())->toContain($code);
});

it('does not reset a password when the token and email travel only in the query string, and does when they are in the body', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);
    $newPassword = Accounts::PASSWORD.'-changed';

    $queryOnly = $this->withHeaders(Accounts::FIRST_PARTY)->postJson(
        '/reset-password?'.http_build_query(['token' => $token, 'email' => 'andres@example.com']),
        ['password' => $newPassword, 'password_confirmation' => $newPassword],
    );

    expect($queryOnly->getStatusCode())->toBe(422)
        ->and(Hash::check(Accounts::PASSWORD, (string) $user->refresh()->password))->toBeTrue();

    $inBody = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/reset-password?token=wrong', [
        'token' => $token, 'email' => 'andres@example.com', 'password' => $newPassword, 'password_confirmation' => $newPassword,
    ]);

    expect($inBody->getStatusCode())->toBe(200)
        ->and(Hash::check($newPassword, (string) $user->refresh()->password))->toBeTrue();
});

it('answers POST /forgot-password?email=… with an empty body 422 and mails nobody', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/forgot-password?email=andres%40example.com', []);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['email']);
    Notification::assertNothingSent();
});

it('creates nobody for an invite code in the query, whichever way the register path is spelled', function (string $path): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson(
        'http://localhost'.$path.'?invite_code='.rawurlencode(Accounts::INVITE),
        ['email' => 'new@example.com', 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD],
    );

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
})->with([
    'plain' => ['/register'],
    'trailing slash' => ['/register/'],
    'two trailing slashes' => ['/register//'],
    'a leading double slash' => ['//register'],
    'one encoded letter' => ['/%72egister'],
    'a double-encoded letter' => ['/%2572egister'],
    'upper case' => ['/REGISTER'],
    'an encoded trailing slash' => ['/register%2F'],
    'a dot segment' => ['/./register'],
    'a parent segment' => ['/account/../register'],
    'a trailing dot' => ['/register.'],
    'a semicolon suffix' => ['/register;x=1'],
    'the front controller in the path' => ['/index.php/register'],
    'the API prefix' => ['/v1/register'],
]);

it('empties the query of PUT, PATCH, DELETE and OPTIONS to a credential-bearing path, and of a method override to one', function (string $method): void {
    $request = Request::create('/login?remember=1&code=1', $method);
    $seen = (new IgnoreQueryCredentials)->handle($request, fn ($r) => response()->json($r->query->all()));

    expect(json_decode((string) $seen->getContent(), true))->toBe([]);
})->with(['PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('reads no query input after the whole kernel has run on a spoofed method for a listed path', function (): void {
    $request = Request::create('/v1/me/deletion?password=x&confirm=1', 'POST', [], [], [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE']);
    $seen = (new IgnoreQueryCredentials)->handle($request, fn ($r) => response()->json([$r->getMethod(), $r->query->all(), $r->all()]));

    expect(json_decode((string) $seen->getContent(), true))->toBe(['DELETE', [], []]);
});

it('keeps the middleware global and ahead of routing for every listed path (registered as a global middleware)', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->toContain(IgnoreQueryCredentials::class);
    foreach (IgnoreQueryCredentials::PATHS as $path) {
        $route = app('router')->getRoutes()->match(Request::create('/'.$path, 'POST'));
        expect($route->uri())->toBe($path);
    }
});

it('sets the session cookie and no-store on POST /v1/demo/session', function (): void {
    Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session');

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Accounts::sessionId($response))->not->toBeNull();
});
