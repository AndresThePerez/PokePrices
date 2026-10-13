<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Adversarial edge tests for plan P4-i1 T08 (D6 export and deletion, D7 password-confirm, D8 demo, D1 CSRF).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** Drops guards and in-memory session state so the next request knows the person only by its cookie. */
function edgeForget(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** Signs the (no two-factor) person in through /login on the database driver; returns the session cookie value. */
function edgeLogin(mixed $test, string $email): string
{
    edgeForget();
    $login = $test->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => Accounts::PASSWORD]);
    expect($login->getStatusCode())->toBe(200);

    return (string) Accounts::sessionId($login);
}

/** @return array<string, int> */
function edgeCounts(): array
{
    $counts = [];
    foreach (['users', 'watchlist_items', 'portfolio_holdings', 'sessions', 'password_reset_tokens'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

it('answers a deletion without a CSRF token with 419 and deletes nothing', function (): void {
    app()->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $user = Accounts::user(['email' => 'collector@example.com']);
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    $before = edgeCounts();

    $plain = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);
    $cross = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY + ['Sec-Fetch-Site' => 'cross-site', 'X-XSRF-TOKEN' => 'not-a-token'])
        ->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);

    expect($plain->getStatusCode())->toBe(419)
        ->and($plain->json('type'))->toBe(ProblemDetails::BASE.'csrf-token-mismatch')
        ->and($cross->getStatusCode())->toBe(419)
        ->and(edgeCounts())->toBe($before);
});

it('does not delete through any other method on /v1/me/deletion, nor accept a POST on /v1/me/export', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    $before = edgeCounts();
    $statuses = [];

    foreach (['getJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
        $statuses[] = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->{$method}('/v1/me/deletion', ['password' => Accounts::PASSWORD])->getStatusCode();
    }
    foreach (['postJson', 'putJson', 'deleteJson'] as $method) {
        $statuses[] = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->{$method}('/v1/me/export', ['password' => Accounts::PASSWORD])->getStatusCode();
    }

    foreach ($statuses as $status) {
        expect($status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    }
    expect(edgeCounts())->toBe($before);
});

it('leaves no row anywhere for a deleted user, and a second live session, a forged row and a mixed-case reset token are gone or dead', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = Accounts::user(['email' => 'collector@example.com', 'remember_token' => 'fake-remember-token-0000']);
    $other = Accounts::user(['email' => 'other@example.com']);
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 2);
    AccountRows::watch($other, 'base1-4:holo:unlimited:-');
    DB::table('password_reset_tokens')->insert([
        ['email' => 'Collector@Example.com', 'token' => Hash::make('fake-reset-0000'), 'created_at' => now()],
        ['email' => 'other@example.com', 'token' => Hash::make('fake-reset-0001'), 'created_at' => now()],
    ]);
    DB::table('sessions')->insert([
        'id' => 'session-forged-0000000000000000000001', 'user_id' => $user->id, 'ip_address' => '192.0.2.9',
        'user_agent' => 'forged', 'payload' => base64_encode('x'), 'last_activity' => now()->getTimestamp(),
    ]);
    $cookie = (string) config('session.cookie');
    $first = edgeLogin($this, 'collector@example.com');
    $second = edgeLogin($this, 'collector@example.com');
    expect($first)->not->toBe($second)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(3);

    edgeForget();
    $deleted = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $first)
        ->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);
    expect($deleted->getStatusCode())->toBe(204);

    // The surviving device and the deleted device both fail on /v1/me and on a Fortify route, and recreate no row.
    foreach ([$first, $second] as $old) {
        edgeForget();
        $me = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $old)->getJson('/v1/me');
        edgeForget();
        $fortify = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $old)->postJson('/user/two-factor-authentication');
        edgeForget();
        $export = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $old)->getJson('/v1/me/export');
        expect($me->getStatusCode())->toBe(401)
            ->and($fortify->getStatusCode())->toBe(401)
            ->and($export->getStatusCode())->toBe(401);
    }

    expect(DB::table('users')->where('id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'session-forged-0000000000000000000001')->count())->toBe(0)
        // A stale cookie may start a fresh anonymous row under its own id, never one that names the user.
        ->and(DB::table('sessions')->whereIn('id', [$first, $second])->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('watchlist_items')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('portfolio_holdings')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', ['collector@example.com'])->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'other@example.com')->count())->toBe(1)
        ->and(DB::table('watchlist_items')->where('user_id', $other->id)->count())->toBe(1)
        ->and(User::query()->where('id', $other->id)->exists())->toBeTrue();
});

it('allows exactly five password checks: four wrong ones then the right one still deletes (limit boundary)', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);
    for ($i = 0; $i < 4; $i++) {
        AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000'])->assertStatus(422);
    }

    $fifth = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);

    expect($fifth->getStatusCode())->toBe(204)
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeFalse();
});

it('exports watchlist and portfolio in byte order of variant_key and keeps long, unicode and IPv6 session fields intact', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);
    $keys = ['ex10-1:normal:-:-', 'ex5-17:reverse:-:-', 'base1-4:holo:unlimited:-', 'base1-4:holo:1st:-', 'sv3pt5-9:normal:-:-', 'ex1-1:holo:-:-', 'xy1-1:normal:-:-'];
    foreach ($keys as $key) {
        AccountRows::watch($user, $key);
        AccountRows::hold($user, $key, 1);
    }
    $agent = 'Ünïcode/1.0 😀 '.str_repeat('a', 3000);
    DB::table('sessions')->insert([
        'id' => 'session-edge-0000000000000000000001', 'user_id' => $user->id, 'ip_address' => '2001:db8::1',
        'user_agent' => $agent, 'payload' => base64_encode('x'), 'last_activity' => 1_760_000_000,
    ]);
    $sorted = $keys;
    sort($sorted, SORT_STRING);

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($response->json('data.watchlist'), 'variant_key'))->toBe($sorted)
        ->and(array_column($response->json('data.portfolio'), 'variant_key'))->toBe($sorted)
        ->and($response->json('data.sessions'))->toBe([['last_active_at' => '2025-10-09T08:53:20Z', 'ip_address' => '2001:db8::1', 'user_agent' => $agent]]);
});
