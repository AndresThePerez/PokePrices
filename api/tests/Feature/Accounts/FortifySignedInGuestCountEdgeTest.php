<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts;

use function Pest\Laravel\call;

// Edge cases of the count a signed-out request to one of Fortify's signed-in routes gets before its session starts:
// cookies a stranger can really send (forged, stale, expired, a CSRF pair without a session), one shared count per
// address across those routes, the address the count comes from, and a signed-in person's own limits. Every request
// goes through call() with exactly the cookies it names, on the database session driver.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    fsrForgetMemory();
});

function fsrForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/**
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $headers  server variables beyond the first-party ones
 * @param  array<string, mixed>  $parameters
 * @return TestResponse<Response>
 */
function fsrRequest(string $method, string $path, string $ip, array $cookies = [], array $parameters = [], array $headers = []): TestResponse
{
    fsrForgetMemory();

    return call($method, $path, $parameters, $cookies, [], $headers + [
        'REMOTE_ADDR' => $ip,
        'HTTP_REFERER' => Accounts::ORIGIN.'/',
        'HTTP_ORIGIN' => Accounts::ORIGIN,
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

/** The session cookie as the framework would set it for the session id $value: prefixed and encrypted. */
function fsrEncrypted(string $value): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create((string) config('session.cookie'), $encrypter->getKey()).$value, false);
}

/** @return array<string, string> */
function fsrSessionCookie(string $value): array
{
    return [(string) config('session.cookie') => $value];
}

it('counts a forged, stale or expired session cookie on PUT /user/password as a guest: 401, no new sessions row, and the 61st is 429', function (): void {
    $user = Accounts::user(['email' => 'first@example.com']);
    $expiredId = str_repeat('e', 40);
    DB::table('sessions')->insert(['id' => $expiredId, 'user_id' => $user->id, 'ip_address' => '192.0.2.10', 'user_agent' => 'example', 'payload' => '', 'last_activity' => time() - 60 * 24 * 60]);
    $cookies = [
        fsrSessionCookie('garbage-not-encrypted'),
        fsrSessionCookie(fsrEncrypted(str_repeat('a', 40))),
        fsrSessionCookie(fsrEncrypted($expiredId)),
        fsrSessionCookie(fsrEncrypted('short')),
    ];
    $before = DB::table('sessions')->count();

    $answers = [];
    for ($i = 0; $i < 60; $i++) {
        $response = fsrRequest('PUT', '/user/password', '192.0.2.10', $cookies[$i % 4]);
        $answers[$response->getStatusCode()] = ($answers[$response->getStatusCode()] ?? 0) + 1;
    }
    $over = fsrRequest('PUT', '/user/password', '192.0.2.10', $cookies[1]);

    expect($answers)->toBe([401 => 60])
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and(DB::table('sessions')->count())->toBe($before);
});

it('answers 401 and stores no sessions row for a CSRF cookie and header pair without any session cookie, and for a bare request', function (): void {
    $withPair = fsrRequest('POST', '/user/confirm-password', '192.0.2.10', ['XSRF-TOKEN' => 'example-token'], ['password' => 'fake-password-0000'], ['HTTP_X_XSRF_TOKEN' => 'example-token']);
    $bare = fsrRequest('DELETE', '/user/two-factor-authentication', '192.0.2.10');
    $read = fsrRequest('GET', '/user/two-factor-recovery-codes', '192.0.2.10');

    expect($withPair->getStatusCode())->toBe(401)
        ->and($bare->getStatusCode())->toBe(401)
        ->and($read->getStatusCode())->toBe(401)
        ->and($bare->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('keeps one count per address across the Fortify signed-in routes: 61 requests spread over four routes end in a 429, and a second address is untouched', function (): void {
    $routes = [['PUT', '/user/password'], ['POST', '/user/confirm-password'], ['GET', '/user/two-factor-qr-code'], ['POST', '/logout']];
    $statuses = [];
    for ($i = 0; $i < 60; $i++) {
        [$method, $path] = $routes[$i % 4];
        $statuses[] = fsrRequest($method, $path, '192.0.2.10')->getStatusCode();
    }
    $over = fsrRequest('POST', '/user/two-factor-authentication', '192.0.2.10');
    $elsewhere = fsrRequest('PUT', '/user/password', '192.0.2.11');

    expect(array_count_values($statuses))->toBe([401 => 60])
        ->and($over->getStatusCode())->toBe(429)
        ->and($elsewhere->getStatusCode())->toBe(401)
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('takes the count from the connection address, not a client-set X-Forwarded-For: a new header each request does not split the count', function (): void {
    $statuses = [];
    for ($i = 1; $i <= 60; $i++) {
        $statuses[] = fsrRequest('PUT', '/user/password', '192.0.2.10', [], [], ['HTTP_X_FORWARDED_FOR' => '198.51.100.'.$i])->getStatusCode();
    }
    $over = fsrRequest('PUT', '/user/password', '192.0.2.10', [], [], ['HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
    $other = fsrRequest('PUT', '/user/password', '192.0.2.11', [], [], ['HTTP_X_FORWARDED_FOR' => '198.51.100.1']);

    expect(array_count_values($statuses))->toBe([401 => 60])
        ->and($over->getStatusCode())->toBe(429)
        ->and($other->getStatusCode())->toBe(401);
});

it('counts a guest carrying a forged remember-me cookie on a Fortify signed-in route: the 61st from the address is 429 and nothing signs in', function (): void {
    Accounts::user(['email' => 'first@example.com']);
    $recaller = 'remember_web_'.sha1(SessionGuard::class);
    $statuses = [];
    for ($i = 0; $i < 60; $i++) {
        $statuses[] = fsrRequest('GET', '/user/confirmed-password-status', '192.0.2.10', [$recaller => 'forged|value|value'])->getStatusCode();
    }
    $over = fsrRequest('GET', '/user/confirmed-password-status', '192.0.2.10');

    expect(array_count_values($statuses))->toBe([401 => 60])
        ->and($over->getStatusCode())->toBe(429)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBeLessThanOrEqual(60);
});

it('leaves a signed-in PUT /user/password to the password-confirm limit: five 422s, the sixth is 429, and the address guest bucket is never the one that refuses', function (): void {
    Accounts::user(['email' => 'first@example.com']);
    $login = fsrRequest('POST', '/login', '192.0.2.10', [], ['email' => 'first@example.com', 'password' => Accounts::PASSWORD]);
    $login->assertOk();
    $cookies = fsrSessionCookie(fsrEncrypted((string) Accounts::sessionId($login)));

    $statuses = [];
    for ($i = 1; $i <= 6; $i++) {
        $statuses[] = fsrRequest('PUT', '/user/password', '192.0.2.10', $cookies, ['current_password' => 'fake-wrong-password-0000', 'password' => 'fake-new-password-0000', 'password_confirmation' => 'fake-new-password-0000'])->getStatusCode();
    }
    $guest = fsrRequest('PUT', '/user/password', '192.0.2.10');

    expect($statuses)->toBe([422, 422, 422, 422, 422, 429])
        ->and($guest->getStatusCode())->toBe(401);
});
