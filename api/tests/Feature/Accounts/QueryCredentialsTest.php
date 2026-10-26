<?php

declare(strict_types=1);

use App\Http\Middleware\IgnoreQueryCredentials;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan P5-i1 T05 criterion 6 (D9, the P4 carry-forward "?password="): a password, password_confirmation or
// current_password in the query string is ignored before anything reads input, so a request that carries it only
// there fails validation; the same value in the JSON body still works. No status, route or contract changes.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

it('runs IgnoreQueryCredentials as a global middleware, first after the request id', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->toContain(IgnoreQueryCredentials::class)
        ->and(array_search(IgnoreQueryCredentials::class, $global, true))->toBeLessThanOrEqual(1);
});

it('answers POST /v1/me/deletion?password=<right password> with an empty body 422 errors.password and keeps the account', function (): void {
    $user = Accounts::user();

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/v1/me/deletion?password='.rawurlencode(Accounts::PASSWORD), []);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['password'])
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});

it('still deletes with the right password in the JSON body, whatever the query says', function (): void {
    $user = Accounts::user();

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/v1/me/deletion?password=not-the-password-0000', ['password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(204)
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeFalse();
});

it('answers POST /login?email=…&password=… with an empty body 422 and signs nobody in', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login?email=collector%40example.com&password='.rawurlencode(Accounts::PASSWORD), []);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toContain('password')
        ->and(auth('web')->check())->toBeFalse()
        ->and($user->refresh()->exists)->toBeTrue();
});

it('still signs in with the password in the JSON body', function (): void {
    Accounts::user(['email' => 'collector@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login?password=not-the-password-0000', ['email' => 'collector@example.com', 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(200)
        ->and(auth('web')->check())->toBeTrue();
});

it('ignores password_confirmation and current_password in the query too', function (): void {
    $register = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register?password_confirmation='.rawurlencode(Accounts::PASSWORD), [
        'invite_code' => Accounts::INVITE, 'email' => 'new@example.com', 'password' => Accounts::PASSWORD,
    ]);

    expect($register->getStatusCode())->toBe(422)
        ->and(array_keys($register->json('errors')))->toBe(['password'])
        ->and(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $request = Request::create('/v1/me?current_password=x&password=y&password_confirmation=z&keep=1', 'GET');
    $seen = (new IgnoreQueryCredentials)->handle($request, fn ($r) => response()->json($r->query->all()));

    expect(json_decode((string) $seen->getContent(), true))->toBe(['keep' => '1'])
        ->and($request->input('current_password'))->toBeNull()
        ->and($request->all())->toBe(['keep' => '1']);
});

// Plan Q1-i1 T04 (review a1, sec A4): the credential-bearing endpoints read nothing from the query string on a write,
// so an invite code, a remember flag or a two-factor code that travels only in the URL counts for nothing.

/** The names of the remember-me cookies a response sets. @return list<string> */
function qcRememberCookies(TestResponse $response): array
{
    $names = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());

    return array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'remember_')));
}

/** A user with confirmed two-factor authentication, set up by Fortify's own action. */
function qcTwoFactorUser(): User
{
    $user = Accounts::user(['email' => 'andres@example.com']);
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

function qcTwoFactorCode(User $user): string
{
    $engine = app(Google2FA::class);
    $secret = (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);

    return (string) $engine->oathTotp($secret, $engine->getTimestamp());
}

it('answers POST /register with the invite code only in the query string 422 errors.invite_code and creates nobody', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register?invite_code='.rawurlencode(Accounts::INVITE), [
        'email' => 'new@example.com', 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['invite_code'])
        ->and(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('signs in for POST /login?remember=1 but mints no remember-me cookie', function (): void {
    Accounts::user(['email' => 'collector@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login?remember=1', ['email' => 'collector@example.com', 'password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(200)
        ->and(auth('web')->check())->toBeTrue()
        ->and(qcRememberCookies($response))->toBe([]);
});

it('still mints the remember-me cookie for remember in the JSON body', function (): void {
    Accounts::user(['email' => 'collector@example.com']);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'collector@example.com', 'password' => Accounts::PASSWORD, 'remember' => true]);

    expect($response->getStatusCode())->toBe(200)
        ->and(qcRememberCookies($response))->toHaveCount(1);
});

it('answers POST /two-factor-challenge?code=<right code> with an empty body 422 and signs nobody in', function (): void {
    $user = qcTwoFactorUser();
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => true]);
    Accounts::forgetGuards();

    $response = $this->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/two-factor-challenge?code='.qcTwoFactorCode($user), []);

    expect($response->getStatusCode())->toBe(422)
        ->and(auth('web')->check())->toBeFalse();
});

it('empties the whole query string of a write to a credential-bearing path, and only there', function (string $method, string $uri, array $kept): void {
    $request = Request::create($uri, $method);
    $seen = (new IgnoreQueryCredentials)->handle($request, fn ($r) => response()->json($r->query->all()));

    expect(json_decode((string) $seen->getContent(), true))->toBe($kept);
})->with([
    'POST login' => ['POST', '/login?remember=1&keep=1', []],
    'POST register' => ['POST', '/register?invite_code=x', []],
    'POST two-factor-challenge' => ['POST', '/two-factor-challenge?code=1&recovery_code=2', []],
    'POST reset-password' => ['POST', '/reset-password?token=x&email=a', []],
    'POST forgot-password' => ['POST', '/forgot-password?email=a', []],
    'POST user/confirm-password' => ['POST', '/user/confirm-password?x=1', []],
    'POST user/confirmed-two-factor-authentication' => ['POST', '/user/confirmed-two-factor-authentication?code=1', []],
    'POST user/two-factor-recovery-codes' => ['POST', '/user/two-factor-recovery-codes?x=1', []],
    'POST v1/me/deletion' => ['POST', '/v1/me/deletion?x=1', []],
    'POST login/ with a trailing slash' => ['POST', '/login/?remember=1', []],
    'POST an encoded login path' => ['POST', '/logi%6E?remember=1', []],
    'GET user/two-factor-recovery-codes keeps its query' => ['GET', '/user/two-factor-recovery-codes?x=1', ['x' => '1']],
    'POST v1/webhooks keeps its query' => ['POST', '/v1/webhooks?x=1', ['x' => '1']],
    'POST logout keeps its query' => ['POST', '/logout?x=1', ['x' => '1']],
    'GET signed email/verify keeps its query' => ['GET', '/email/verify/1/abc?expires=1&signature=s', ['expires' => '1', 'signature' => 's']],
]);
