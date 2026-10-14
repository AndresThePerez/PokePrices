<?php

declare(strict_types=1);

use App\Http\Middleware\IgnoreQueryCredentials;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
