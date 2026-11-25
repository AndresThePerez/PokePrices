<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Accounts;

// The guest limit (account-guest, 60 a minute per address) counts only requests without a signed-in session. Every
// request here goes through call() with exactly the cookies it names, on the database session driver, so the only
// link between two requests is the session cookie.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    grlForgetMemory();
});

/**
 * Forgets what an earlier request left in this one process: the guards, the session store, and the container's
 * auth.driver singleton, which would otherwise write the earlier request's user id on a later request's sessions row.
 */
function grlForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** @return array<string, string> the server variables of a first-party JSON request from $ip */
function grlServer(string $ip): array
{
    return [
        'REMOTE_ADDR' => $ip,
        'HTTP_REFERER' => Accounts::ORIGIN.'/',
        'HTTP_ORIGIN' => Accounts::ORIGIN,
        'HTTP_ACCEPT' => 'application/json',
    ];
}

/** The session cookie as the framework would set it for the session id $value: prefixed and encrypted. */
function grlEncrypted(string $value): string
{
    $encrypter = app('encrypter');

    return $encrypter->encrypt(CookieValuePrefix::create((string) config('session.cookie'), $encrypter->getKey()).$value, false);
}

/**
 * A request from $ip carrying $cookie as the raw session cookie (null sends none).
 *
 * @return TestResponse<Response>
 */
function grlRequest(object $test, string $ip, ?string $cookie, string $path = '/v1/me'): TestResponse
{
    grlForgetMemory();

    return $test->call('GET', $path, [], $cookie === null ? [] : [(string) config('session.cookie') => $cookie], [], grlServer($ip));
}

/** Creates the account, signs it in from $ip and returns the session id its cookie carries. */
function grlSignIn(object $test, string $email, string $ip): string
{
    grlForgetMemory();
    Accounts::user(['email' => $email]);
    $login = $test->call('POST', '/login', ['email' => $email, 'password' => Accounts::PASSWORD], [], [], grlServer($ip));
    $login->assertOk();

    return (string) Accounts::sessionId($login);
}

/** Spends the whole guest bucket of $ip: 60 requests without a cookie, each a 401. */
function grlFillGuestBucket(object $test, string $ip): void
{
    for ($i = 1; $i <= 60; $i++) {
        grlRequest($test, $ip, null)->assertStatus(401);
    }
}

it('refuses the 61st guest request from one address, lets a signed-in user behind it pass 120 and refuses the 121st for that user only', function (): void {
    $ip = '192.0.2.60';
    $first = grlSignIn($this, 'first@example.com', $ip);
    $second = grlSignIn($this, 'second@example.com', $ip);

    grlFillGuestBucket($this, $ip);
    $guest = grlRequest($this, $ip, null);

    $passed = 0;
    for ($i = 1; $i <= 120; $i++) {
        $passed += grlRequest($this, $ip, grlEncrypted($first))->getStatusCode() === 200 ? 1 : 0;
    }
    $over = grlRequest($this, $ip, grlEncrypted($first));
    $other = grlRequest($this, $ip, grlEncrypted($second));
    $guestStill = grlRequest($this, $ip, null);

    expect($guest->getStatusCode())->toBe(429)
        ->and($guest->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($passed)->toBe(120)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $over->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($other->getStatusCode())->toBe(200)
        ->and($other->json('data.email'))->toBe('second@example.com')
        ->and($guestStill->getStatusCode())->toBe(429);
});

it('counts a session cookie that is not a signed-in session as a guest', function (Closure $cookie): void {
    $ip = '192.0.2.61';
    $signedIn = grlSignIn($this, 'first@example.com', $ip);
    $raw = $cookie($this, $signedIn, $ip);

    grlFillGuestBucket($this, $ip);
    $valid = grlRequest($this, $ip, grlEncrypted($signedIn));
    $refused = grlRequest($this, $ip, $raw);

    expect($valid->getStatusCode())->toBe(200)
        ->and($refused->getStatusCode())->toBe(429)
        ->and($refused->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($refused->headers->getCookies())->toBe([]);
})->with([
    'forged: a real session id sent without the encryption' => [fn (object $test, string $signedIn, string $ip): string => $signedIn],
    'forged: an encrypted value with its last character changed' => [function (object $test, string $signedIn, string $ip): string {
        $encrypted = grlEncrypted($signedIn);

        return substr($encrypted, 0, -1).(str_ends_with($encrypted, 'A') ? 'B' : 'A');
    }],
    'malformed: encrypted, but not a session id' => [fn (object $test, string $signedIn, string $ip): string => grlEncrypted('not-a-session-id')],
    'unknown: a well-formed id with no session behind it' => [fn (object $test, string $signedIn, string $ip): string => grlEncrypted(str_repeat('0', 40))],
    'expired: a signed-in session idle for longer than the lifetime' => [function (object $test, string $signedIn, string $ip): string {
        $expired = grlSignIn($test, 'expired@example.com', $ip);
        $idle = now()->subMinutes(((int) config('session.lifetime')) + 1)->getTimestamp();
        expect(DB::table('sessions')->where('id', $expired)->update(['last_activity' => $idle]))->toBe(1);

        return grlEncrypted($expired);
    }],
    'anonymous: a real session nobody signed in to' => [function (object $test, string $signedIn, string $ip): string {
        // Started from another address, so it spends none of this address's 60.
        $anonymous = (string) Accounts::sessionId(grlRequest($test, '192.0.2.63', null, '/sanctum/csrf-cookie'));
        expect(DB::table('sessions')->where('id', $anonymous)->whereNull('user_id')->count())->toBe(1);

        return grlEncrypted($anonymous);
    }],
]);

it('counts a signed-in request per address where no account tier follows: behind a full address /sanctum/csrf-cookie is refused for every user, the account group still answers', function (): void {
    $ip = '192.0.2.62';
    $first = grlSignIn($this, 'first@example.com', $ip);
    $second = grlSignIn($this, 'second@example.com', $ip);
    grlFillGuestBucket($this, $ip);

    $over = grlRequest($this, $ip, grlEncrypted($first), '/sanctum/csrf-cookie');
    $other = grlRequest($this, $ip, grlEncrypted($second), '/sanctum/csrf-cookie');
    // The address's guest bucket is not the account group's count: the first user still reads the account.
    $account = grlRequest($this, $ip, grlEncrypted($first));

    expect($over->getStatusCode())->toBe(429)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($other->getStatusCode())->toBe(429)
        ->and($account->getStatusCode())->toBe(200)
        ->and($account->json('data.email'))->toBe('first@example.com');
});
