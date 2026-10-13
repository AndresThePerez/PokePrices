<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 7 (D2 two-factor) and criterion 16 (reviews F-P4-07 password-confirm limiter,
// F-P4-09 no-store on the secret GETs).

beforeEach(function (): void {
    Accounts::setUp();
});

function twoFactorSecret(User $user): string
{
    return (string) Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret);
}

/** The code of the current 30 s window plus $windows (the provider refuses a code it has already accepted). */
function twoFactorCode(User $user, int $windows = 0): string
{
    $engine = app(Google2FA::class);

    return (string) $engine->oathTotp(twoFactorSecret($user), $engine->getTimestamp() + $windows);
}

/** A user with confirmed two-factor authentication and recovery codes, set up by Fortify's own action. */
function twoFactorUser(): User
{
    $user = Accounts::user(['email' => 'andres@example.com']);
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

function twoFactorSignInStep(object $test): void
{
    Accounts::forgetGuards();
    $test->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => true]);
    Accounts::forgetGuards();
}

it('turns two-factor on with a confirmed password and a code, then requires the challenge at sign-in, then turns it off', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $this->actingAs($user);

    $locked = $this->postJson('/user/two-factor-authentication');
    expect($locked->getStatusCode())->toBe(423);

    $this->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $this->postJson('/user/two-factor-authentication')->assertOk();

    $qr = $this->getJson('/user/two-factor-qr-code');
    $qr->assertOk();
    expect(array_keys($qr->json()))->toBe(['svg', 'url'])
        ->and($qr->json('svg'))->toContain('<svg')
        ->and($qr->json('url'))->toStartWith('otpauth://totp/');
    $key = $this->getJson('/user/two-factor-secret-key');
    expect(array_keys($key->json()))->toBe(['secretKey'])
        ->and($key->json('secretKey'))->toBe(twoFactorSecret($user));

    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => twoFactorCode($user) === '000000' ? '111111' : '000000'])
        ->assertStatus(422);
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => twoFactorCode($user)])->assertOk();
    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
    $this->getJson('/v1/me')->assertOk()->assertJsonPath('data.two_factor', true);

    $this->postJson('/logout')->assertNoContent();
    twoFactorSignInStep($this);

    $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertStatus(401);
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', ['code' => twoFactorCode($user, 1)])->assertNoContent();
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertOk()->assertJsonPath('data.email', 'andres@example.com');

    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $this->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/user/two-factor-authentication')->assertOk();
    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('accepts a recovery code once; its second use is 422', function (): void {
    $user = twoFactorUser();
    $code = $user->recoveryCodes()[0];

    twoFactorSignInStep($this);
    $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', ['recovery_code' => $code])->assertNoContent();
    Accounts::forgetGuards();
    $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/logout')->assertNoContent();

    twoFactorSignInStep($this);
    $again = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', ['recovery_code' => $code]);
    expect($again->getStatusCode())->toBe(422)
        ->and($again->json('errors'))->toHaveKey('recovery_code')
        ->and($user->refresh()->recoveryCodes())->not->toContain($code);
});

it('answers the sixth challenge attempt within a minute with 429', function (): void {
    twoFactorUser();
    twoFactorSignInStep($this);

    for ($i = 1; $i <= 5; $i++) {
        $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', ['code' => 'nope'.$i])->assertStatus(422);
    }
    $sixth = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/two-factor-challenge', ['code' => 'nope6']);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});

it('answers the sixth wrong password confirmation of one user within a minute with 429 (F7)', function (): void {
    $user = Accounts::user();
    $this->actingAs($user);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/user/confirm-password', ['password' => 'wrong-password-'.$i])->assertStatus(422);
    }
    $sixth = $this->postJson('/user/confirm-password', ['password' => 'wrong-password-6']);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests');
});

it('answers the sixth two-factor confirmation of one user within a minute with 429 (F7)', function (): void {
    $user = Accounts::user();
    // The password is confirmed through the session, so no POST /user/confirm-password spends the shared
    // password-confirm bucket (D7: one limiter, keyed by user, on both routes).
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->postJson('/user/two-factor-authentication')->assertOk();

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => 'nope'.$i])->assertStatus(422);
    }
    $sixth = $this->postJson('/user/confirmed-two-factor-authentication', ['code' => 'nope6']);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($user->refresh()->two_factor_confirmed_at)->toBeNull();
});

it('marks the two-factor secret answers no-store and private, with no ETag (F9)', function (string $path): void {
    $user = Accounts::user();
    $this->actingAs($user);
    $this->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $this->postJson('/user/two-factor-authentication')->assertOk();

    $response = $this->getJson($path);

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->has('ETag'))->toBeFalse();
})->with(['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes']);
