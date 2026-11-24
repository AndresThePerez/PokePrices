<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Accounts;

// Reviewer edge cases for PUT /user/password: what is stored is what the next sign-in accepts (a new password shaped
// like a hash, spaces, bytes against characters), the limiter shared with confirm-password, a remember-me cookie on the
// Fortify routes, passwords that travel only in the query string, the XSRF token, what is returned and logged, and a
// repeated submission.

/** A fake new password of 21 characters. */
const PWE_NEW = 'fake-new-password-0e1';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** Forgets the resolved guards and the session store, so only a cookie links two requests. */
function pweForget(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** @return array<string, string> a complete body: the current password and the new one, confirmed */
function pweBody(string $new = PWE_NEW, string $current = Accounts::PASSWORD): array
{
    return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new];
}

/** Whether $password signs $email in through /login, asked from a browser with no session. */
function pweSignsIn(mixed $test, string $email, string $password): bool
{
    pweForget();

    return $test->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => $password])->getStatusCode() === 200;
}

it('hashes a new password that is itself shaped like a password hash, so the text that was typed is what signs in', function (string $new): void {
    $user = Accounts::user(['email' => 'andres@example.com']);

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody($new));

    $stored = $user->refresh()->password;
    expect($response->getStatusCode())->toBe(200)
        // The column holds a hash of the text, never the text itself.
        ->and($stored === $new)->toBeFalse()
        ->and(Hash::check($new, $stored))->toBeTrue()
        ->and(pweSignsIn($this, 'andres@example.com', $new))->toBeTrue()
        ->and(pweSignsIn($this, 'andres@example.com', Accounts::PASSWORD))->toBeFalse();
})->with([
    '60 characters shaped like a bcrypt hash' => ['$2y$04$'.str_repeat('a', 53)],
    'the prefix of an argon2id hash' => ['$argon2id$fake-pass-00'],
]);

it('keeps the spaces around a new and a current password: the text as typed signs in and its trimmed form does not', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $spaced = '  fake spaced password 01  ';

    $first = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody($spaced));

    expect($first->getStatusCode())->toBe(200)
        ->and(pweSignsIn($this, 'andres@example.com', $spaced))->toBeTrue()
        ->and(pweSignsIn($this, 'andres@example.com', trim($spaced)))->toBeFalse();

    // The current password is compared as typed too: its trimmed form is the wrong one.
    Accounts::forgetGuards();
    $this->actingAs($user->refresh(), 'web');
    $trimmed = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody(PWE_NEW, trim($spaced)));
    $exact = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody(PWE_NEW, $spaced));

    expect($trimmed->getStatusCode())->toBe(422)
        ->and(array_keys($trimmed->json('errors')))->toBe(['current_password'])
        ->and($exact->getStatusCode())->toBe(200)
        ->and(Hash::check(PWE_NEW, $user->refresh()->password))->toBeTrue();
});

it('measures the upper limit in bytes as well as characters: 72 characters of 73 bytes are refused, 24 characters of 72 bytes are accepted and every byte counts', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;
    $over = str_repeat('a', 71).'é';
    $atLimit = str_repeat('€', 24);
    expect([mb_strlen($over), strlen($over), mb_strlen($atLimit), strlen($atLimit)])->toBe([72, 73, 24, 72]);

    $this->actingAs($user, 'web');
    $refused = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody($over));
    $unchanged = $user->refresh()->password === $hash;
    $accepted = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody($atLimit));

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(array_keys($refused->json('errors')))->toBe(['password'])
        ->and($unchanged)->toBeTrue()
        ->and($accepted->getStatusCode())->toBe(200)
        ->and(pweSignsIn($this, 'andres@example.com', $atLimit))->toBeTrue()
        // The same text with its last byte changed is another password: nothing was cut off before hashing.
        ->and(pweSignsIn($this, 'andres@example.com', str_repeat('€', 23).'₭'))->toBeFalse();
});

it('shares its five a minute with confirm-password: after five wrong confirmations a change behind the right current password is a 429 and changes nothing', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;

    $this->actingAs($user, 'web');
    for ($i = 1; $i <= 5; $i++) {
        $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/user/confirm-password', ['password' => 'wrong-password-'.$i])->assertStatus(422);
    }
    $change = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody());

    expect($change->getStatusCode())->toBe(429)
        ->and($change->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($change->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $change->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($user->refresh()->password === $hash)->toBeTrue()
        ->and(pweSignsIn($this, 'andres@example.com', Accounts::PASSWORD))->toBeTrue();
});

it('signs an older remember-me cookie in on no Fortify route either, so it cannot change the password again', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    $recallerName = Auth::guard('web')->getRecallerName();
    pweForget();
    $remembered = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD, 'remember' => true]);
    $recaller = (string) $remembered->getCookie($recallerName)?->getValue();
    pweForget();
    $here = (string) Accounts::sessionId($this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]));
    // A browser whose session is gone and whose remember-me cookie is all it has left.
    $status = function (string $method, string $path, array $body = []) use ($cookie, $recallerName, $recaller): int {
        pweForget();

        return $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, str_repeat('0', 40))
            ->withCookie($recallerName, $recaller)->json($method, $path, $body)->getStatusCode();
    };
    expect($recaller)->not->toBe('')
        ->and($here)->not->toBe('')
        ->and($status('GET', '/user/confirmed-password-status'))->toBe(200);

    pweForget();
    $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $here)->putJson('/user/password', pweBody())->assertOk();
    $hash = $user->refresh()->password;

    expect($status('GET', '/user/confirmed-password-status'))->toBe(401)
        ->and($status('POST', '/user/two-factor-authentication'))->toBe(401)
        ->and($status('PUT', '/user/password', pweBody('fake-new-password-0e2', PWE_NEW)))->toBe(401)
        ->and($user->refresh()->password === $hash)->toBeTrue();
});

it('reads none of the three password fields from the query string: a change that carries them only there is refused, and a body is never overruled by it', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;
    $this->actingAs($user, 'web');

    $queryOnly = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password?'.http_build_query(pweBody()), []);
    $unchanged = $user->refresh()->password === $hash;
    $both = $this->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/user/password?'.http_build_query(pweBody('fake-query-password-9', 'not-the-current-password')), pweBody());

    expect($queryOnly->getStatusCode())->toBe(422)
        ->and(array_keys($queryOnly->json('errors')))->toEqualCanonicalizing(['current_password', 'password'])
        ->and($unchanged)->toBeTrue()
        ->and($both->getStatusCode())->toBe(200)
        ->and(Hash::check(PWE_NEW, $user->refresh()->password))->toBeTrue()
        ->and(Hash::check('fake-query-password-9', $user->password))->toBeFalse();
});

it('refuses a change that carries no XSRF token with 419 and changes nothing', function (): void {
    // Laravel skips the CSRF check while running unit tests; this subclass turns it back on for the one request.
    app()->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody());

    expect($response->getStatusCode())->toBe(419)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'csrf-token-mismatch')
        ->and($user->refresh()->password === $hash)->toBeTrue();
});

it('returns and logs none of the passwords a change carries, nor a hash, whether it is refused or accepted', function (): void {
    $logFile = (string) tempnam(sys_get_temp_dir(), 'pp-password-change-log-');
    config(['logging.default' => 'stderr_json', 'logging.channels.stderr_json.handler_with.stream' => $logFile]);
    Log::forgetChannel('stderr_json');
    $user = Accounts::user(['email' => 'andres@example.com']);
    $before = $user->password;

    $this->actingAs($user, 'web');
    $refused = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody('fake-new-sentinel-0001', 'fake-wrong-sentinel-01'));
    $accepted = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pweBody('fake-new-sentinel-0001'));

    $raw = (string) file_get_contents($logFile);
    @unlink($logFile);
    $routes = array_map(
        fn (string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['route'] ?? null,
        array_values(array_filter(explode("\n", $raw), fn (string $line): bool => str_contains($line, '"message":"request"'))),
    );
    $out = $refused->getContent().$accepted->getContent().$refused->headers.$accepted->headers.$raw;
    expect([$refused->getStatusCode(), $accepted->getStatusCode()])->toBe([422, 200])
        ->and($routes)->toBe(['user/password', 'user/password'])
        ->and(str_contains($out, 'fake-new-sentinel-0001'))->toBeFalse()
        ->and(str_contains($out, 'fake-wrong-sentinel-01'))->toBeFalse()
        ->and(str_contains($out, Accounts::PASSWORD))->toBeFalse()
        ->and(str_contains($out, $before))->toBeFalse()
        ->and(str_contains($out, $user->refresh()->password))->toBeFalse();
});

it('answers the same change sent twice with 422 the second time: the first change stands, this session stays signed in and can change it again', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    pweForget();
    $here = (string) Accounts::sessionId($this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD]));
    $send = function (string $method, string $path, array $body = []) use ($cookie, $here) {
        pweForget();

        return $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $here)->json($method, $path, $body);
    };

    $first = $send('PUT', '/user/password', pweBody());
    $hash = $user->refresh()->password;
    $second = $send('PUT', '/user/password', pweBody());

    expect([$first->getStatusCode(), $second->getStatusCode()])->toBe([200, 422])
        ->and(array_keys($second->json('errors')))->toBe(['current_password'])
        ->and($user->refresh()->password === $hash)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$here])
        ->and($send('GET', '/v1/me')->getStatusCode())->toBe(200);

    // A second change from the same session, behind the password the first one set.
    $third = $send('PUT', '/user/password', pweBody('fake-new-password-0e2', PWE_NEW));

    expect($third->getStatusCode())->toBe(200)
        ->and(Hash::check('fake-new-password-0e2', $user->refresh()->password))->toBeTrue()
        ->and($send('GET', '/v1/me')->getStatusCode())->toBe(200)
        ->and($send('GET', '/user/confirmed-password-status')->getStatusCode())->toBe(200);
});
