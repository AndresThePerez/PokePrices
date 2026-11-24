<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountDeletion;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts;

// Edge cases of the guest limit (account-guest, 60 a minute per address, counted before any session starts): what a
// request with a signed-in cookie costs when it is refused before the account tier, who counts as signed in, what the
// 61st guest request costs, and the buckets of the demo and of one user behind two addresses. Every request goes
// through call() with exactly the cookies it names, on the database session driver.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    grleForgetMemory();
});

/** Forgets what an earlier request left in this one process: the guards, the session store and the auth driver. */
function grleForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** @return array<string, string> the server variables of a first-party JSON request from $ip */
function grleServer(string $ip): array
{
    return [
        'REMOTE_ADDR' => $ip,
        'HTTP_REFERER' => Accounts::ORIGIN.'/',
        'HTTP_ORIGIN' => Accounts::ORIGIN,
        'HTTP_ACCEPT' => 'application/json',
    ];
}

/** The session cookie as the framework would set it for the session id $value: prefixed and encrypted. */
function grleEncrypted(string $value): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create((string) config('session.cookie'), $encrypter->getKey()).$value, false);
}

/**
 * A first-party request from $ip carrying $cookie as the raw session cookie (null sends none).
 *
 * @param  array<string, mixed>  $parameters
 * @return TestResponse<Response>
 */
function grleRequest(object $test, string $ip, ?string $cookie, string $path = '/v1/me', string $method = 'GET', array $parameters = []): TestResponse
{
    grleForgetMemory();

    return $test->call($method, $path, $parameters, $cookie === null ? [] : [(string) config('session.cookie') => $cookie], [], grleServer($ip));
}

/** Creates the account, signs it in from $ip and returns the session id its cookie carries. */
function grleSignIn(object $test, string $email, string $ip): string
{
    grleForgetMemory();
    Accounts::user(['email' => $email]);
    $login = $test->call('POST', '/login', ['email' => $email, 'password' => Accounts::PASSWORD], [], [], grleServer($ip));
    $login->assertOk();

    return (string) Accounts::sessionId($login);
}

/** Spends the whole guest bucket of $ip: 60 requests without a cookie, each a 401. */
function grleFillGuestBucket(object $test, string $ip): void
{
    for ($i = 1; $i <= 60; $i++) {
        grleRequest($test, $ip, null)->assertStatus(401);
    }
}

it('counts a request that carries a signed-in cookie but is answered 401 for want of the first-party headers: a flood of them meets a 429', function (): void {
    $ip = '192.0.2.70';
    $cookie = grleEncrypted(grleSignIn($this, 'first@example.com', $ip));
    // What a script sends: the cookie, no Referer and no Origin. Sanctum starts no session for it, so it is a 401.
    $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];

    $statuses = [];
    for ($i = 1; $i <= 121; $i++) {
        grleForgetMemory();
        $statuses[] = $this->call('GET', '/v1/me', [], [(string) config('session.cookie') => $cookie], [], $server)->getStatusCode();
    }

    // Either bucket may count it (the guest's 60 or the user's 120); none counting it is the failure.
    expect($statuses[0])->toBe(401)
        ->and(array_values(array_unique($statuses)))->toBe([401, 429])
        ->and(count(array_keys($statuses, 401, true)))->toBeLessThanOrEqual(120)
        ->and($statuses[120])->toBe(429);
});

it('counts a signed-in write refused for its missing CSRF token: a flood of 419s meets a 429', function (): void {
    $ip = '192.0.2.71';
    $cookie = grleEncrypted(grleSignIn($this, 'first@example.com', $ip));
    // Laravel skips the CSRF check while running unit tests; this makes Sanctum's stateful pipeline enforce it.
    app()->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $statuses = [];
    for ($i = 1; $i <= 121; $i++) {
        $statuses[] = grleRequest($this, $ip, $cookie, '/v1/watchlist/base1-4:holo:unlimited:-', 'PUT')->getStatusCode();
    }

    expect($statuses[0])->toBe(419)
        ->and(array_values(array_unique($statuses)))->toBe([419, 429])
        ->and(count(array_keys($statuses, 419, true)))->toBeLessThanOrEqual(120)
        ->and($statuses[120])->toBe(429)
        ->and(DB::table('watchlist_items')->count())->toBe(0);
});

it('counts the session of a deleted account and a session signed out a moment ago as guests', function (): void {
    $ip = '192.0.2.72';
    $kept = grleSignIn($this, 'first@example.com', $ip);
    $deleted = grleSignIn($this, 'gone@example.com', $ip);
    $signedOut = grleSignIn($this, 'out@example.com', $ip);

    app(AccountDeletion::class)->delete(User::query()->where('email', 'gone@example.com')->firstOrFail());
    $logout = grleRequest($this, $ip, grleEncrypted($signedOut), '/logout', 'POST');
    $afterLogout = Accounts::sessionId($logout);

    grleFillGuestBucket($this, $ip);
    $ofDeleted = grleRequest($this, $ip, grleEncrypted($deleted));
    $ofSignedOut = grleRequest($this, $ip, grleEncrypted($signedOut));
    $ofAfterLogout = grleRequest($this, $ip, grleEncrypted((string) $afterLogout));
    $ofKept = grleRequest($this, $ip, grleEncrypted($kept));

    expect($logout->getStatusCode())->toBe(204)
        ->and($afterLogout)->toBeString()
        ->and($afterLogout)->not->toBe($signedOut)
        ->and($ofDeleted->getStatusCode())->toBe(429)
        ->and($ofSignedOut->getStatusCode())->toBe(429)
        ->and($ofAfterLogout->getStatusCode())->toBe(429)
        ->and($ofAfterLogout->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($ofKept->getStatusCode())->toBe(200)
        ->and($ofKept->json('data.email'))->toBe('first@example.com');
});

it('refuses the 61st guest request without a query when it has no cookie or one that does not decrypt, with one read of sessions for a real anonymous session, and starts no session', function (): void {
    $ip = '192.0.2.73';
    // A real session nobody signed in to, started from another address so it spends none of this address's 60.
    $anonymous = (string) Accounts::sessionId(grleRequest($this, '192.0.2.74', null, '/sanctum/csrf-cookie'));
    grleFillGuestBucket($this, $ip);
    $rows = DB::table('sessions')->orderBy('id')->get(['id', 'user_id', 'last_activity'])->all();

    $queries = [];
    $responses = [];
    foreach (['none' => null, 'undecryptable' => 'not-an-encrypted-value', 'anonymous' => grleEncrypted($anonymous)] as $name => $cookie) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $responses[$name] = grleRequest($this, $ip, $cookie);
        DB::disableQueryLog();
        $queries[$name] = array_column(DB::getQueryLog(), 'query');
    }

    expect(array_map(fn (TestResponse $r): int => $r->getStatusCode(), $responses))->toBe(['none' => 429, 'undecryptable' => 429, 'anonymous' => 429])
        ->and($queries['none'])->toBe([])
        ->and($queries['undecryptable'])->toBe([])
        ->and($queries['anonymous'])->toHaveCount(1)
        ->and($queries['anonymous'][0])->toStartWith('select')
        ->and($queries['anonymous'][0])->toContain('"sessions"')
        ->and(array_map(fn (TestResponse $r): array => $r->headers->getCookies(), $responses))->toBe(['none' => [], 'undecryptable' => [], 'anonymous' => []])
        ->and(DB::table('sessions')->orderBy('id')->get(['id', 'user_id', 'last_activity'])->all())->toEqual($rows);
});

it('gives the demo its own bucket behind a full address: 30 pass, the 31st is refused, a person behind that address and the demo from another address still pass', function (): void {
    $ip = '192.0.2.75';
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $person = grleSignIn($this, 'first@example.com', $ip);
    $entry = grleRequest($this, $ip, null, '/v1/demo/session', 'POST');
    $demo = (string) Accounts::sessionId($entry);
    $elsewhere = (string) Accounts::sessionId(grleRequest($this, '192.0.2.76', null, '/v1/demo/session', 'POST'));
    grleFillGuestBucket($this, $ip);

    $passed = 0;
    for ($i = 1; $i <= 30; $i++) {
        $passed += grleRequest($this, $ip, grleEncrypted($demo))->getStatusCode() === 200 ? 1 : 0;
    }
    $over = grleRequest($this, $ip, grleEncrypted($demo));
    $ofPerson = grleRequest($this, $ip, grleEncrypted($person));
    $ofElsewhere = grleRequest($this, '192.0.2.76', grleEncrypted($elsewhere));

    expect($entry->getStatusCode())->toBe(204)
        ->and($passed)->toBe(30)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($ofPerson->getStatusCode())->toBe(200)
        ->and($ofPerson->json('data.email'))->toBe('first@example.com')
        ->and($ofElsewhere->getStatusCode())->toBe(200)
        ->and($ofElsewhere->json('data.read_only'))->toBeTrue();
});

it('keys the count before the session by the user and the address: on /sanctum/csrf-cookie the 121st from one address is refused while the same user passes from another', function (): void {
    $home = '192.0.2.77';
    $away = '192.0.2.78';
    $cookie = grleEncrypted(grleSignIn($this, 'first@example.com', $home));

    $passed = 0;
    for ($i = 1; $i <= 120; $i++) {
        $passed += grleRequest($this, $home, $cookie, '/sanctum/csrf-cookie')->getStatusCode() === 204 ? 1 : 0;
    }
    $over = grleRequest($this, $home, $cookie, '/sanctum/csrf-cookie');
    $fromAway = grleRequest($this, $away, $cookie, '/sanctum/csrf-cookie');
    // The guest bucket of the address was never spent by the signed-in requests: a guest still gets all 60.
    grleFillGuestBucket($this, $home);
    $guestOver = grleRequest($this, $home, null);

    expect($passed)->toBe(120)
        ->and($over->getStatusCode())->toBe(429)
        ->and((int) $over->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($fromAway->getStatusCode())->toBe(204)
        ->and($guestOver->getStatusCode())->toBe(429);
});

it('sends no reset mail for a signed-in request to /forgot-password, behind a full address too: it is the 409 already-signed-in', function (): void {
    $ip = '192.0.2.79';
    Accounts::user(['email' => 'victim@example.com']);
    $cookie = grleEncrypted(grleSignIn($this, 'first@example.com', $ip));
    grleFillGuestBucket($this, $ip);

    $signedIn = grleRequest($this, $ip, $cookie, '/forgot-password', 'POST', ['email' => 'victim@example.com']);
    $guest = grleRequest($this, $ip, null, '/forgot-password', 'POST', ['email' => 'victim@example.com']);

    expect($signedIn->getStatusCode())->toBe(409)
        ->and($signedIn->json('type'))->toBe(ProblemDetails::BASE.'already-signed-in')
        ->and($guest->getStatusCode())->toBe(429);
    Notification::assertNothingSent();
});
