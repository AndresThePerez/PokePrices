<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 3 (D3, invite-only registration) and criterion 16 (reviews F-P4-04, F-P4-05).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** @return array<string, mixed> */
function registerBody(array $overrides = []): array
{
    return $overrides + [
        'invite_code' => Accounts::INVITE,
        'email' => 'andres@example.com',
        'password' => Accounts::PASSWORD,
        'password_confirmation' => Accounts::PASSWORD,
    ];
}

function registerSignOut(): void
{
    Auth::guard('web')->logout();
    Accounts::forgetGuards();
}

it('registers with a valid invite code: 201, lower-cased email, no name, not the demo, signed in, one verification mail', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register', registerBody(['email' => 'Andres@Example.COM']));

    $response->assertStatus(201);
    $user = User::query()->sole();
    expect($user->email)->toBe('andres@example.com')
        ->and($user->name)->toBeNull()
        ->and($user->is_demo)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull();

    Accounts::forgetGuards();
    $me = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $me->assertOk();
    expect($me->json('data.email'))->toBe('andres@example.com');

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;
        $prefix = rtrim((string) config('app.url'), '/').'/account/verify-email?link=';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return str_starts_with($url, $prefix)
            && array_keys($query) === ['link']
            && is_string($query['link'])
            && str_starts_with($query['link'], '/email/verify/'.$user->id.'/')
            && rawurlencode($query['link']) === substr($url, strlen($prefix));
    });
});

it('refuses a wrong, missing or unconfigured invite code with exactly the invite_code error, examining nothing else', function (?string $configured, array $body): void {
    config(['accounts.invite_code' => $configured]);
    Accounts::user(['email' => 'taken@example.com']);

    $response = $this->postJson('/register', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toBe(['invite_code' => ['The invite code is not valid.']])
        ->and(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
})->with([
    'wrong code' => [Accounts::INVITE, ['invite_code' => 'not-the-code', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short']],
    'missing code' => [Accounts::INVITE, ['email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short']],
    'unconfigured (null), empty submitted' => [null, ['invite_code' => '', 'email' => 'taken@example.com', 'password' => 'short']],
    'unconfigured (null), any submitted' => [null, ['invite_code' => 'anything', 'email' => 'taken@example.com', 'password' => 'short']],
    "unconfigured (''), empty submitted" => ['', ['invite_code' => '', 'email' => 'taken@example.com', 'password' => 'short']],
    "unconfigured (''), any submitted" => ['', ['invite_code' => 'anything', 'email' => 'taken@example.com', 'password' => 'short']],
]);

it('gives exactly the wrong-code answer for an invite_code that is not a string (F5)', function (mixed $code): void {
    $response = $this->postJson('/register', registerBody(['invite_code' => $code]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['invite_code' => ['The invite code is not valid.']])
        ->and(User::query()->count())->toBe(0);
})->with([
    'an array' => [[Accounts::INVITE]],
    'an int' => [12345],
    'true' => [true],
    'false' => [false],
    'null' => [null],
    'a 10 KB string' => [str_repeat('a', 10240)],
]);

it('refuses a taken email with a valid code', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $response = $this->postJson('/register', registerBody());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email')
        ->and($response->json('errors'))->not->toHaveKey('invite_code')
        ->and(User::query()->count())->toBe(1);
});

it('refuses passwords outside 12..72 characters and 72 bytes, and a mismatched confirmation', function (string $password, string $confirmation): void {
    $response = $this->postJson('/register', registerBody(['password' => $password, 'password_confirmation' => $confirmation]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('password')
        ->and(User::query()->count())->toBe(0);
})->with([
    '11 characters' => [str_repeat('p', 11), str_repeat('p', 11)],
    '73 characters' => [str_repeat('p', 73), str_repeat('p', 73)],
    '40 two-byte letters (80 bytes)' => [str_repeat('é', 40), str_repeat('é', 40)],
    'a mismatched confirmation' => [Accounts::PASSWORD, Accounts::PASSWORD.'x'],
]);

it('accepts a 72-character password', function (): void {
    $this->postJson('/register', registerBody(['password' => str_repeat('p', 72), 'password_confirmation' => str_repeat('p', 72)]))
        ->assertStatus(201);
});

it('never splits an account on email case: Andres@Example.COM then andres@example.com is 422 errors.email (F4)', function (): void {
    $this->postJson('/register', registerBody(['email' => 'Andres@Example.COM']))->assertStatus(201);
    registerSignOut();

    $response = $this->postJson('/register', registerBody(['email' => 'andres@example.com']));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email')
        ->and(User::query()->count())->toBe(1);
});

it('answers 422, never 500, for an email that is not a string (F5)', function (mixed $email): void {
    $response = $this->postJson('/register', registerBody(['email' => $email]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveKey('email')
        ->and(User::query()->count())->toBe(0);
})->with([
    'an array' => [['andres@example.com']],
    'an int' => [42],
    'null' => [null],
]);

it('answers the sixth registration from one IP within a minute with 429 and Retry-After; another IP is not throttled', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/register', registerBody(['invite_code' => 'wrong']))->assertStatus(422);
    }

    $sixth = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->postJson('/register', registerBody(['invite_code' => 'wrong']));
    $other = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->postJson('/register', registerBody(['invite_code' => 'wrong']));

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $sixth->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($other->getStatusCode())->toBe(422);
});
