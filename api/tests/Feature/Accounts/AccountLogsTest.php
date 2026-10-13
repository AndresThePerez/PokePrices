<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 12: request log lines carry route templates, never a token, hash, signature, password
// or invite code.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->logFile = tempnam(sys_get_temp_dir(), 'pp-accounts-log-');
    config([
        'logging.default' => 'stderr_json',
        'logging.channels.stderr_json.handler_with.stream' => $this->logFile,
    ]);
    Log::forgetChannel('stderr_json');
});

afterEach(function (): void {
    @unlink($this->logFile);
});

/** @return list<array<string, mixed>> */
function accountLogLines(string $file): array
{
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

    return array_map(fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);
}

/** @return list<array<string, mixed>> */
function accountRequestLines(string $file): array
{
    return array_values(array_filter(accountLogLines($file), fn (array $l): bool => $l['message'] === 'request'));
}

it('logs POST /reset-password by its route, never the token, the email or the password', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ])->assertOk();

    $raw = (string) file_get_contents($this->logFile);
    $lines = accountRequestLines($this->logFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['route'])->toBe('reset-password')
        ->and($raw)->not->toContain($token)
        ->and($raw)->not->toContain('brand-new-password-0')
        ->and($raw)->not->toContain(Accounts::PASSWORD);
});

it('logs the verification GET by its route template, never the hash or the signature', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);
    $url = (new VerifyEmail)->toMail($user)->actionUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $link = (string) $query['link'];
    parse_str((string) parse_url($link, PHP_URL_QUERY), $signed);
    $hash = sha1($user->email);

    $this->actingAs($user)->getJson($link)->assertNoContent();

    $raw = (string) file_get_contents($this->logFile);
    $lines = accountRequestLines($this->logFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['route'])->toBe('email/verify/{id}/{hash}')
        ->and($raw)->not->toContain($hash)
        ->and($raw)->not->toContain((string) $signed['signature']);
});

it('writes no log line holding the password or the invite code during register, sign-in and reset', function (): void {
    $this->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => 'andres@example.com',
        'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ])->assertStatus(201);
    $this->postJson('/logout')->assertNoContent();
    Accounts::forgetGuards();
    $this->postJson('/login', ['email' => 'andres@example.com', 'password' => 'wrong-password-000'])->assertStatus(422);
    $this->postJson('/login', ['email' => 'andres@example.com', 'password' => Accounts::PASSWORD])->assertOk();
    $this->postJson('/logout')->assertNoContent();
    Accounts::forgetGuards();
    $this->postJson('/forgot-password', ['email' => 'andres@example.com'])->assertOk();
    $token = Password::broker()->createToken(User::query()->sole());
    $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'andres@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ])->assertOk();

    $raw = (string) file_get_contents($this->logFile);
    expect(accountRequestLines($this->logFile))->toHaveCount(7)
        ->and($raw)->not->toContain(Accounts::PASSWORD)
        ->and($raw)->not->toContain('wrong-password-000')
        ->and($raw)->not->toContain('brand-new-password-0')
        ->and($raw)->not->toContain(Accounts::INVITE)
        ->and($raw)->not->toContain($token);
});
