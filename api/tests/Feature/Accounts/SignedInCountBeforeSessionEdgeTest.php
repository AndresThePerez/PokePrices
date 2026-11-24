<?php

declare(strict_types=1);

use App\Http\CachePolicy;
use App\Http\Problems\ProblemDetails;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts;

// Edge cases of the count a signed-in request gets before its session starts (120 a minute per user and address):
// what the refusal on the account group costs and carries, and that its bucket, the bucket of the three routes
// without an account tier, the account tier's own and the guest's are four different buckets. Every request goes
// through call() with exactly the cookies it names, on the database session driver.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    sicForgetMemory();
});

/** Forgets what an earlier request left in this one process: the guards, the session store and the auth driver. */
function sicForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** The session cookie as the framework would set it for the session id $value: prefixed and encrypted. */
function sicEncrypted(string $value): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create((string) config('session.cookie'), $encrypter->getKey()).$value, false);
}

/**
 * A JSON request from $ip carrying $cookie as the raw session cookie (null sends none); first-party unless
 * $firstParty is false, which is what a script sends: no Referer and no Origin.
 *
 * @param  array<string, mixed>  $parameters
 * @return TestResponse<Response>
 */
function sicRequest(object $test, string $ip, ?string $cookie, string $path = '/v1/me', string $method = 'GET', array $parameters = [], bool $firstParty = true): TestResponse
{
    sicForgetMemory();
    $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json']
        + ($firstParty ? ['HTTP_REFERER' => Accounts::ORIGIN.'/', 'HTTP_ORIGIN' => Accounts::ORIGIN] : []);

    return $test->call($method, $path, $parameters, $cookie === null ? [] : [(string) config('session.cookie') => $cookie], [], $server);
}

/** Creates the account, signs it in from $ip and returns its session cookie as a browser would send it. */
function sicSignIn(object $test, string $email, string $ip): string
{
    Accounts::user(['email' => $email]);
    $login = sicRequest($test, $ip, null, '/login', 'POST', ['email' => $email, 'password' => Accounts::PASSWORD]);
    $login->assertOk();

    return sicEncrypted((string) Accounts::sessionId($login));
}

it('refuses the 121st signed-in request on the account group before its session starts: one read of sessions, no cookie, nothing written, the no-store problem with Retry-After', function (): void {
    $ip = '192.0.2.80';
    $cookie = sicSignIn($this, 'first@example.com', $ip);

    $passed = 0;
    for ($i = 1; $i <= 120; $i++) {
        $passed += sicRequest($this, $ip, $cookie)->getStatusCode() === 200 ? 1 : 0;
    }
    $rows = DB::table('sessions')->orderBy('id')->get()->all();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $over = sicRequest($this, $ip, $cookie);
    DB::disableQueryLog();
    $queries = array_column(DB::getQueryLog(), 'query');

    // The user's own 120 are whole: the count before the session and the account tier each count a request once.
    expect($passed)->toBe(120)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($over->json('status'))->toBe(429)
        ->and((int) $over->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $over->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($over->headers->get('Cache-Control'))->toBe(CachePolicy::ACCOUNT)
        ->and($over->headers->getCookies())->toBe([])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toStartWith('select')
        ->and($queries[0])->toContain('"sessions"')
        ->and(DB::table('sessions')->orderBy('id')->get()->all())->toEqual($rows);
});

it('keeps a flood of refused signed-in requests on the account group in its own bucket: the guest at that address, the routes without an account tier and the same user from another address still pass', function (): void {
    $home = '192.0.2.81';
    $away = '192.0.2.82';
    $cookie = sicSignIn($this, 'first@example.com', $home);

    $refused = 0;
    for ($i = 1; $i <= 120; $i++) {
        $refused += sicRequest($this, $home, $cookie, firstParty: false)->getStatusCode() === 401 ? 1 : 0;
    }
    $over = sicRequest($this, $home, $cookie, firstParty: false);
    // The bucket is the user's at this address whatever the request looks like: a first-party one is refused too.
    $overFirstParty = sicRequest($this, $home, $cookie);
    $csrfCookie = sicRequest($this, $home, $cookie, '/sanctum/csrf-cookie');
    $guest = sicRequest($this, $home, null);
    // None of the 120 reached the account tier, which is keyed by the user alone: from another address all is left.
    $fromAway = sicRequest($this, $away, $cookie);

    expect($refused)->toBe(120)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($overFirstParty->getStatusCode())->toBe(429)
        ->and($csrfCookie->getStatusCode())->toBe(204)
        ->and($guest->getStatusCode())->toBe(401)
        ->and($fromAway->getStatusCode())->toBe(200)
        ->and($fromAway->json('data.email'))->toBe('first@example.com');
});

it('shares one count before the session between /sanctum/csrf-cookie, /forgot-password and /reset-password: 120 on the first leave a 429 on the other two for that user only', function (): void {
    $ip = '192.0.2.83';
    $first = sicSignIn($this, 'first@example.com', $ip);
    $second = sicSignIn($this, 'second@example.com', $ip);

    $passed = 0;
    for ($i = 1; $i <= 120; $i++) {
        $passed += sicRequest($this, $ip, $first, '/sanctum/csrf-cookie')->getStatusCode() === 204 ? 1 : 0;
    }
    $forgot = sicRequest($this, $ip, $first, '/forgot-password', 'POST', ['email' => 'first@example.com']);
    $reset = sicRequest($this, $ip, $first, '/reset-password', 'POST', ['email' => 'first@example.com']);
    $ofSecond = sicRequest($this, $ip, $second, '/forgot-password', 'POST', ['email' => 'second@example.com']);

    expect($passed)->toBe(120)
        ->and($forgot->getStatusCode())->toBe(429)
        ->and($forgot->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($reset->getStatusCode())->toBe(429)
        ->and($reset->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($ofSecond->getStatusCode())->toBe(409)
        ->and($ofSecond->json('type'))->toBe(ProblemDetails::BASE.'already-signed-in');
    Notification::assertNothingSent();
});
