<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Support\Accounts;

// PUT /user/password: a signed-in account changes its own password behind the current one. The rules of sign-up and
// reset (12 to 72 characters, confirmed), the password-confirm limiter, the read-only demo, the account's other
// sessions, and the exported contract, which documents /v1 only.

/** A fake new password of 21 characters. */
const PWC_NEW = 'fake-new-password-001';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** Forgets the resolved guards and the session store, so only a session cookie links two requests. */
function pwcForget(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** @return array<string, string> a complete body: the current password and the new one, confirmed */
function pwcBody(string $new = PWC_NEW, string $current = Accounts::PASSWORD): array
{
    return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new];
}

/** Signs a person in through /login and returns the session cookie value. */
function pwcLogin(mixed $test, string $email, string $password = Accounts::PASSWORD): string
{
    pwcForget();
    $login = $test->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => $password]);
    expect($login->getStatusCode())->toBe(200);

    return (string) Accounts::sessionId($login);
}

/** Whether $password signs $email in through /login, asked from a browser with no session. */
function pwcSignsIn(mixed $test, string $email, string $password): bool
{
    pwcForget();

    return $test->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => $password])->getStatusCode() === 200;
}

it('answers a signed-out PUT /user/password with the 401 problem and changes nobody', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody());

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($user->refresh()->password)->toBe($hash);
});

it('refuses a wrong, missing or non-text current password with 422 on that field alone and changes nothing', function (array $current): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;
    $body = $current + ['password' => PWC_NEW, 'password_confirmation' => PWC_NEW];

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($response->json('errors')))->toBe(['current_password'])
        ->and($response->json('errors.current_password.0'))->toBeString()->not->toBe('')
        ->and($user->refresh()->password)->toBe($hash)
        ->and(pwcSignsIn($this, 'andres@example.com', Accounts::PASSWORD))->toBeTrue()
        ->and(pwcSignsIn($this, 'andres@example.com', PWC_NEW))->toBeFalse();
})->with([
    'wrong' => [['current_password' => 'not-the-current-password']],
    'missing' => [[]],
    'empty' => [['current_password' => '']],
    'an array' => [['current_password' => [Accounts::PASSWORD]]],
]);

it('refuses a new password that breaks the sign-up rules with 422 on password alone and changes nothing', function (array $new): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/user/password', ['current_password' => Accounts::PASSWORD] + $new);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(array_keys($response->json('errors')))->toBe(['password'])
        ->and($user->refresh()->password)->toBe($hash);
})->with([
    '11 characters' => [['password' => str_repeat('a', 11), 'password_confirmation' => str_repeat('a', 11)]],
    '73 characters' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)]],
    'a confirmation that differs' => [['password' => PWC_NEW, 'password_confirmation' => 'fake-new-password-002']],
    'no confirmation' => [['password' => PWC_NEW]],
    'no new password' => [[]],
]);

it('accepts a new password at each end of the 12 to 72 range', function (int $length): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $new = str_repeat('b', $length);

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody($new));

    expect($response->getStatusCode())->toBe(200)
        ->and(Hash::check($new, $user->refresh()->password))->toBeTrue();
})->with([12, 72]);

it('changes the hash on success: the new password signs in and the old one no longer does', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $hash = $user->password;
    DB::table('password_reset_tokens')->insert(['email' => 'andres@example.com', 'token' => Hash::make('fake-reset-0000'), 'created_at' => now()]);

    $response = $this->actingAs($user, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($user->refresh()->password)->not->toBe($hash)
        ->and(Hash::check(PWC_NEW, $user->password))->toBeTrue()
        ->and(Hash::check(Accounts::PASSWORD, $user->password))->toBeFalse()
        // A reset link mailed before the change is spent with it.
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(pwcSignsIn($this, 'andres@example.com', Accounts::PASSWORD))->toBeFalse()
        ->and(pwcSignsIn($this, 'andres@example.com', PWC_NEW))->toBeTrue();
});

it('answers the sixth attempt of one user within a minute with 429 and changes nothing; another user is not throttled (password-confirm)', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $hash = $a->password;

    $this->actingAs($a, 'web');
    for ($i = 1; $i <= 5; $i++) {
        $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody(PWC_NEW, 'wrong-password-'.$i))->assertStatus(422);
    }
    $sixth = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody());

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($a->refresh()->password)->toBe($hash);

    Accounts::forgetGuards();
    $other = $this->actingAs($b, 'web')->withHeaders(Accounts::FIRST_PARTY)->putJson('/user/password', pwcBody());
    expect($other->getStatusCode())->toBe(200)
        ->and(Hash::check(PWC_NEW, $b->refresh()->password))->toBeTrue();
});

it('changes only the session\'s own password, whatever field, header or parameter names another account', function (array $body, string $query, array $headers): void {
    $a = Accounts::user(['email' => 'a@example.com', 'password' => Hash::make('fake-password-of-a-00')]);
    $b = Accounts::user(['email' => 'b@example.com']);
    $hash = $a->password;
    $fill = fn (mixed $value): mixed => $value === ':id' ? $a->id : $value;

    $response = $this->actingAs($b, 'web')
        ->withHeaders(Accounts::FIRST_PARTY + array_map($fill, $headers))
        ->putJson('/user/password'.str_replace(':id', (string) $a->id, $query), array_map($fill, $body) + pwcBody());

    expect($response->getStatusCode())->toBe(200)
        ->and($a->refresh()->password)->toBe($hash)
        ->and(Hash::check(PWC_NEW, $b->refresh()->password))->toBeTrue()
        ->and(pwcSignsIn($this, 'a@example.com', 'fake-password-of-a-00'))->toBeTrue()
        ->and(pwcSignsIn($this, 'a@example.com', PWC_NEW))->toBeFalse()
        ->and(pwcSignsIn($this, 'b@example.com', PWC_NEW))->toBeTrue();
})->with([
    'body fields' => [['id' => ':id', 'user_id' => ':id', 'user' => ['id' => ':id'], 'email' => 'a@example.com'], '', []],
    'query parameters' => [[], '?id=:id&user_id=:id&email=a%40example.com', []],
    'headers' => [[], '', ['X-User-Id' => ':id', 'X-User-Email' => 'a@example.com', 'X-On-Behalf-Of' => 'a@example.com']],
    'nothing extra' => [[], '', []],
]);

it('has no password route that names an account in its path, and another account\'s current password opens nothing', function (): void {
    $a = Accounts::user(['email' => 'a@example.com', 'password' => Hash::make('fake-password-of-a-00')]);
    $b = Accounts::user(['email' => 'b@example.com']);
    $before = [$a->password, $b->password];
    $this->actingAs($b, 'web');

    $byId = $this->withHeaders(Accounts::FIRST_PARTY)->putJson("/user/password/{$a->id}", pwcBody());
    $nested = $this->withHeaders(Accounts::FIRST_PARTY)->putJson("/user/{$a->id}/password", pwcBody());
    $theirs = $this->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/user/password', ['email' => 'a@example.com'] + pwcBody(PWC_NEW, 'fake-password-of-a-00'));

    expect([$byId->getStatusCode(), $nested->getStatusCode()])->toBe([404, 404])
        ->and($theirs->getStatusCode())->toBe(422)
        ->and(array_keys($theirs->json('errors')))->toBe(['current_password'])
        ->and([$a->refresh()->password, $b->refresh()->password])->toBe($before);
});

it('refuses the read-only demo with 403 demo-read-only, its own current password included, and leaves its password alone', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true, 'password' => Hash::make('fake-demo-password-00')]);
    $hash = $demo->password;

    $response = $this->actingAs($demo, 'web')->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/user/password', pwcBody(PWC_NEW, 'fake-demo-password-00'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'demo-read-only')
        ->and($demo->refresh()->password)->toBe($hash)
        ->and(User::query()->where('is_demo', true)->count())->toBe(1);
});

it('signs the account\'s other sessions out after a change and keeps this one and other people\'s', function (): void {
    // The database driver, as on the dev stack: the only link between requests is the session cookie.
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = Accounts::user(['email' => 'andres@example.com']);
    Accounts::user(['email' => 'other@example.com']);
    $cookie = (string) config('session.cookie');
    $here = pwcLogin($this, 'andres@example.com');
    $elsewhere = pwcLogin($this, 'andres@example.com');
    $theirs = pwcLogin($this, 'other@example.com');
    DB::table('sessions')->insert([
        'id' => 'session-idle-00000000000000000000001', 'user_id' => $user->id, 'ip_address' => '192.0.2.10',
        'user_agent' => 'test-agent', 'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
    ]);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(3);

    pwcForget();
    $change = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $here)->putJson('/user/password', pwcBody());

    expect($change->getStatusCode())->toBe(200)
        ->and(Accounts::sessionId($change))->toBe($here)
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$here])
        ->and(DB::table('sessions')->where('id', $theirs)->exists())->toBeTrue();

    $status = function (string $session, string $method, string $path) use ($cookie): int {
        pwcForget();

        return $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $session)->json($method, $path)->getStatusCode();
    };
    // The other browser is signed out on the account API and on a sign-in route alike.
    expect($status($elsewhere, 'GET', '/v1/me'))->toBe(401)
        ->and($status($elsewhere, 'POST', '/user/two-factor-authentication'))->toBe(401)
        // This browser stays signed in, request after request, and so does the other person's.
        ->and($status($here, 'GET', '/v1/me'))->toBe(200)
        ->and($status($here, 'GET', '/v1/me'))->toBe(200)
        ->and($status($here, 'GET', '/user/confirmed-password-status'))->toBe(200)
        ->and($status($theirs, 'GET', '/v1/me'))->toBe(200)
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$here]);
});

it('stops a remember-me cookie issued before the change from signing anyone in', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    $recallerName = Auth::guard('web')->getRecallerName();
    pwcForget();
    $remembered = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD, 'remember' => true]);
    $recaller = (string) $remembered->getCookie($recallerName)?->getValue();
    $here = pwcLogin($this, 'andres@example.com');
    // A browser whose session is gone and whose remember-me cookie is all it has left (a test request keeps every
    // cookie given before it, so the session cookie is replaced with one that names no session).
    $me = function () use ($cookie, $recallerName, $recaller): int {
        pwcForget();

        return $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, str_repeat('0', 40))
            ->withCookie($recallerName, $recaller)->getJson('/v1/me')->getStatusCode();
    };
    expect($recaller)->not->toBe('')->and($me())->toBe(200);

    pwcForget();
    $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $here)->putJson('/user/password', pwcBody())->assertOk();

    expect($me())->toBe(401);
});

it('signs nobody out when the change is refused', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = Accounts::user(['email' => 'andres@example.com']);
    $cookie = (string) config('session.cookie');
    $here = pwcLogin($this, 'andres@example.com');
    $elsewhere = pwcLogin($this, 'andres@example.com');

    pwcForget();
    $refused = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $here)
        ->putJson('/user/password', pwcBody(PWC_NEW, 'not-the-current-password'));

    expect($refused->getStatusCode())->toBe(422)
        ->and(DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all())->toEqualCanonicalizing([$here, $elsewhere]);
    foreach ([$here, $elsewhere] as $session) {
        pwcForget();
        $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $session)->getJson('/v1/me')->assertOk();
    }
});

it('keeps PUT /user/password out of the exported contract, which stays byte-identical to openapi/api.json', function (): void {
    $route = Route::getRoutes()->getByName('user-password.update');
    $path = (string) tempnam(sys_get_temp_dir(), 'pp-spec-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $exported = (string) file_get_contents($path);
    @unlink($path);
    $spec = json_decode($exported, true, 512, JSON_THROW_ON_ERROR);

    expect($route?->uri())->toBe('user/password')
        ->and($route?->methods())->toBe(['PUT'])
        ->and($exported)->toBe((string) file_get_contents(base_path('../openapi/api.json')))
        ->and(array_values(array_filter(array_keys($spec['paths']), fn (string $p): bool => str_contains($p, 'user/'))))->toBe([]);
});
