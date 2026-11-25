<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts;

use function Pest\Laravel\call;

// A signed-out request to one of Fortify's signed-in routes is counted by account-guest before any session starts and
// stores no sessions row; /sanctum/csrf-cookie, /forgot-password and /reset-password count per address whether or not
// the request carries a signed-in cookie. Every request goes through call() with exactly the cookies it names, on the
// database session driver.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    fsgForgetMemory();
});

/**
 * Every route Fortify registers behind its own sign-in middleware, read from the registered route list, so a route a
 * feature flag adds is in it too.
 *
 * @return array<string, array{string, string}> method and request path, by "METHOD uri"
 */
function fsgSignedInRoutes(): array
{
    $auth = config('fortify.auth_middleware', 'auth').':'.config('fortify.guard');
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), 'Laravel\\Fortify\\') || ! in_array($auth, $route->middleware(), true)) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.$route->uri()] = [$method, '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/')];
        }
    }
    ksort($routes);

    return $routes;
}

function fsgForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/**
 * A first-party JSON request from $ip carrying $cookie as the raw session cookie (null sends none).
 *
 * @param  array<string, mixed>  $parameters
 * @return TestResponse<Response>
 */
function fsgRequest(string $method, string $path, string $ip, ?string $cookie = null, array $parameters = []): TestResponse
{
    fsgForgetMemory();

    return call($method, $path, $parameters, $cookie === null ? [] : [(string) config('session.cookie') => $cookie], [], [
        'REMOTE_ADDR' => $ip,
        'HTTP_REFERER' => Accounts::ORIGIN.'/',
        'HTTP_ORIGIN' => Accounts::ORIGIN,
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

/** The session cookie as the framework would set it for the session id $value: prefixed and encrypted. */
function fsgEncrypted(string $value): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create((string) config('session.cookie'), $encrypter->getKey()).$value, false);
}

/** Creates the account, signs it in from $ip and returns its encrypted session cookie. */
function fsgSignIn(string $email, string $ip): string
{
    Accounts::user(['email' => $email]);
    $login = fsgRequest('POST', '/login', $ip, null, ['email' => $email, 'password' => Accounts::PASSWORD]);
    $login->assertOk();

    return fsgEncrypted((string) Accounts::sessionId($login));
}

it('answers a signed-out request to every Fortify signed-in route with its 401 and stores no sessions row', function (): void {
    $routes = fsgSignedInRoutes();
    $answers = [];
    foreach ($routes as $name => [$method, $path]) {
        $before = DB::table('sessions')->count();
        $response = fsgRequest($method, $path, '192.0.2.10');
        $answers[$name] = [$response->getStatusCode(), $response->json('type'), DB::table('sessions')->count() - $before];
    }

    expect(array_keys($routes))->toContain('PUT user/password', 'POST user/confirm-password', 'GET user/confirmed-password-status', 'POST user/two-factor-authentication')
        ->and($answers)->toBe(array_fill_keys(array_keys($routes), [401, ProblemDetails::BASE.'unauthenticated', 0]));
});

it('refuses the 61st signed-out PUT /user/password in a minute from one address with 429, and none of them stores a sessions row', function (): void {
    $statuses = [];
    for ($i = 1; $i <= 61; $i++) {
        $statuses[] = fsgRequest('PUT', '/user/password', '192.0.2.10')->getStatusCode();
    }
    $over = fsgRequest('PUT', '/user/password', '192.0.2.10');

    expect(array_count_values(array_slice($statuses, 0, 60)))->toBe([401 => 60])
        ->and($statuses[60])->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('counts a request carrying a signed-in cookie per address on the guest routes: the 61st in a minute is 429', function (string $method, string $path, array $body, int $status): void {
    $cookie = fsgSignIn('first@example.com', '192.0.2.10');

    $passed = 0;
    for ($i = 1; $i <= 60; $i++) {
        $passed += fsgRequest($method, $path, '192.0.2.10', $cookie, $body)->getStatusCode() === $status ? 1 : 0;
    }
    $over = fsgRequest($method, $path, '192.0.2.10', $cookie, $body);
    $guest = fsgRequest($method, $path, '192.0.2.10', null, $body);

    expect($passed)->toBe(60)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($guest->getStatusCode())->toBe(429);
    Notification::assertNothingSent();
})->with([
    'csrf cookie' => ['GET', '/sanctum/csrf-cookie', [], 204],
    'forgot password' => ['POST', '/forgot-password', ['email' => 'first@example.com'], 409],
    'reset password' => ['POST', '/reset-password', ['token' => 'example', 'email' => 'first@example.com', 'password' => 'fake-new-password-0000', 'password_confirmation' => 'fake-new-password-0000'], 409],
]);

it('leaves a signed-in person on the Fortify signed-in routes to the password-confirm limit, behind a full address too', function (): void {
    $cookie = fsgSignIn('first@example.com', '192.0.2.10');
    for ($i = 1; $i <= 60; $i++) {
        fsgRequest('PUT', '/user/password', '192.0.2.10');
    }
    $guest = fsgRequest('PUT', '/user/password', '192.0.2.10');

    $status = fsgRequest('GET', '/user/confirmed-password-status', '192.0.2.10', $cookie);
    $wrong = [];
    for ($i = 1; $i <= 5; $i++) {
        $wrong[] = fsgRequest('POST', '/user/confirm-password', '192.0.2.10', $cookie, ['password' => 'fake-wrong-password-0000'])->getStatusCode();
    }
    $sixth = fsgRequest('POST', '/user/confirm-password', '192.0.2.10', $cookie, ['password' => Accounts::PASSWORD]);

    expect($guest->getStatusCode())->toBe(429)
        ->and($status->getStatusCode())->toBe(200)
        ->and($status->json('confirmed'))->toBeFalse()
        ->and($wrong)->toBe([422, 422, 422, 422, 422])
        ->and($sixth->getStatusCode())->toBe(429)
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests');
});
