<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 5 (D2 reset) and criterion 16 (reviews F-P4-04 email case, F-P4-08 reset ends sessions).

const RESET_ANSWER = '{"message":"If that address has a PokéPrices account, a reset link is on its way."}';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

function resetSessionRow(string $id, ?int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $userId, 'ip_address' => '192.0.2.40', 'user_agent' => 'test-agent',
        'payload' => base64_encode('a:0:{}'), 'last_activity' => 1_760_000_000,
    ]);
}

it('answers a known and an unknown email with byte-identical 200 bodies and mails only the known user', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);

    $known = $this->postJson('/forgot-password', ['email' => 'andres@example.com']);
    $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.com']);

    expect($known->getStatusCode())->toBe(200)
        ->and($unknown->getStatusCode())->toBe(200)
        ->and($known->getContent())->toBe(RESET_ANSWER)
        ->and($unknown->getContent())->toBe($known->getContent());
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;
        $base = rtrim((string) config('app.url'), '/').'/password/reset?';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // <APP_URL>/password/reset?token=<token>&email=<rawurlencode email>, in that order.
        return str_starts_with($url, $base)
            && $query === ['token' => $notification->token, 'email' => $user->email]
            && substr($url, strlen($base)) === http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    });
});

it('still answers the same 200 for a second request inside the broker throttle, without a second mail', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);

    $this->postJson('/forgot-password', ['email' => 'andres@example.com'])->assertOk();
    $second = $this->postJson('/forgot-password', ['email' => 'andres@example.com']);

    expect($second->getStatusCode())->toBe(200)->and($second->getContent())->toBe(RESET_ANSWER);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('answers a malformed email with 422, never 500 (F5)', function (mixed $email): void {
    $response = $this->postJson('/forgot-password', ['email' => $email]);

    expect($response->getStatusCode())->toBe(422)->and($response->json('errors'))->toHaveKey('email');
    Notification::assertNothingSent();
})->with([
    'not an address' => ['not-an-email'],
    'an array' => [['andres@example.com']],
    'an int' => [42],
    'null' => [null],
]);

it('sends one reset mail for ANDRES@EXAMPLE.COM to the lower-case account (F4)', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);

    $response = $this->postJson('/forgot-password', ['email' => 'ANDRES@EXAMPLE.COM']);

    expect($response->getContent())->toBe(RESET_ANSWER);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

it('resets the password with the token: 200, the new password signs in and the old one does not', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);

    $reset = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ]);

    $reset->assertOk();
    $user->refresh();
    expect(Hash::check('brand-new-password-0', $user->password))->toBeTrue()
        ->and(Hash::check(Accounts::PASSWORD, $user->password))->toBeFalse()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);

    $this->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])->assertStatus(422);
    $this->postJson('/login', ['email' => 'andres@example.com', 'password' => 'brand-new-password-0'])->assertOk();
});

it('resets with a mixed-case email and a valid token (F4)', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'Andres@EXAMPLE.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ])->assertOk();

    expect(Hash::check('brand-new-password-0', $user->refresh()->password))->toBeTrue();
});

it('refuses a bad token with 422 and changes nothing', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    Password::broker()->createToken($user);

    $response = $this->postJson('/reset-password', [
        'token' => 'not-the-token', 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email')
        ->and(Hash::check(Accounts::PASSWORD, $user->refresh()->password))->toBeTrue();
});

it('refuses a short new password with 422 errors.password', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);

    $response = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'andres@example.com', 'password' => 'short', 'password_confirmation' => 'short',
    ]);

    expect($response->getStatusCode())->toBe(422)->and($response->json('errors'))->toHaveKey('password');
});

it('ends every other session of the user on a successful reset and keeps other users\' sessions (F8)', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $other = Accounts::user(['email' => 'other@example.com']);
    resetSessionRow('session-user-0000000000000000000001', $user->id);
    resetSessionRow('session-user-0000000000000000000002', $user->id);
    resetSessionRow('session-other-000000000000000000003', $other->id);
    $token = Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ])->assertOk();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['session-other-000000000000000000003']);
});

it('deletes no session on a failed reset (F8)', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    resetSessionRow('session-user-0000000000000000000001', $user->id);
    resetSessionRow('session-user-0000000000000000000002', $user->id);
    Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => 'not-the-token', 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ])->assertStatus(422);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);
});
