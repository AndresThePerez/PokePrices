<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan Q0-i1 T02 (cloud security finding A1): every demo visitor signs in as the same users.id, so the demo's
// GET /v1/me/export lists only the caller's own session record, never another visitor's address or browser. A real
// account still gets every session record it has. The export's keys do not change. Every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3).

const DXS_CALLER_IP = '192.0.2.10';

const DXS_CALLER_AGENT = 'caller-browser/1.0';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    // The database driver, as on the dev stack, so the session row of the sign-in is real; the garbage-collection
    // lottery never runs, so the fixture rows are never dropped by chance.
    config([
        'session.driver' => 'database', 'session.lottery' => [0, 100],
        'accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null,
    ]);
    dxsForgetMemory();
});

/** Drops the guards and the in-memory session, so the next request knows the person only by its session cookie. */
function dxsForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function dxsSession(string $id, ?int $userId, string $ip, string $agent, int $secondsAgo): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $userId, 'ip_address' => $ip, 'user_agent' => $agent,
        'payload' => base64_encode('a:1:{s:4:"seen";s:19:"fake-session-0000-y";}'),
        'last_activity' => now()->getTimestamp() - $secondsAgo,
    ]);
}

function dxsDemo(): User
{
    return Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
}

/** Enters the demo the way the button does and returns the new session id. */
function dxsEnterDemo(object $test): string
{
    $enter = $test->withServerVariables(['REMOTE_ADDR' => DXS_CALLER_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXS_CALLER_AGENT])->postJson('/v1/demo/session');
    $enter->assertNoContent();

    return (string) Accounts::sessionId($enter);
}

/** GET /v1/me/export with only the session cookie to say who is asking. */
function dxsExport(object $test, string $sessionId): TestResponse
{
    dxsForgetMemory();

    return $test->withServerVariables(['REMOTE_ADDR' => DXS_CALLER_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXS_CALLER_AGENT])->withCredentials()
        ->withCookie((string) config('session.cookie'), $sessionId)->getJson('/v1/me/export');
}

it('exports only the demo caller\'s own session record, never another demo visitor\'s address or browser', function (): void {
    $demo = dxsDemo();
    dxsSession('session-demo-visitor-a-000000000000001', $demo->id, '192.0.2.20', 'other-visitor/2.0', 30);
    dxsSession('session-demo-visitor-b-000000000000002', $demo->id, '192.0.2.30', 'third-visitor/3.0', 5);

    $sessionId = dxsEnterDemo($this);
    expect($sessionId)->not->toBe('')
        ->and(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($demo->id)
        ->and(DB::table('sessions')->where('user_id', $demo->id)->count())->toBe(3);

    $response = dxsExport($this, $sessionId);
    $raw = (string) $response->getContent();
    $sessions = $response->json('data.sessions');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.account.email'))->toBe('demo@example.com')
        ->and(array_keys($response->json('data')))->toBe(['format', 'version', 'account', 'watchlist', 'portfolio', 'sessions', 'notifications', 'webhooks', 'deliveries'])
        ->and($sessions)->toHaveCount(1)
        ->and(array_keys($sessions[0]))->toBe(['last_active_at', 'ip_address', 'user_agent'])
        ->and($sessions[0]['ip_address'])->toBe(DXS_CALLER_IP)
        ->and($sessions[0]['user_agent'])->toBe(DXS_CALLER_AGENT)
        ->and($sessions[0]['last_active_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    foreach (['192.0.2.20', 'other-visitor/2.0', '192.0.2.30', 'third-visitor/3.0'] as $index => $needle) {
        expect(str_contains($raw, $needle))->toBeFalse("another visitor's value #{$index} appears in the export");
    }
});

it('exports the demo caller\'s own session record when no other demo visitor is signed in', function (): void {
    $demo = dxsDemo();

    $response = dxsExport($this, dxsEnterDemo($this));

    expect($response->getStatusCode())->toBe(200)
        ->and(DB::table('sessions')->where('user_id', $demo->id)->count())->toBe(1)
        ->and(array_map(fn (array $s): array => [$s['ip_address'], $s['user_agent']], $response->json('data.sessions')))
        ->toBe([[DXS_CALLER_IP, DXS_CALLER_AGENT]]);
});

it('exports no demo session record when the caller\'s own session has no stored row yet', function (): void {
    $demo = dxsDemo();
    dxsSession('session-demo-visitor-a-000000000000001', $demo->id, '192.0.2.20', 'other-visitor/2.0', 30);
    config(['session.driver' => 'array']);

    $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.sessions'))->toBe([])
        ->and((string) $response->getContent())->not->toContain('192.0.2.20');
});

it('fails closed: without a session id the demo gets no session record, a real account still gets all of its own', function (): void {
    $demo = dxsDemo();
    $person = Accounts::user(['email' => 'collector@example.com']);
    dxsSession('session-demo-visitor-a-000000000000001', $demo->id, '192.0.2.20', 'other-visitor/2.0', 30);
    dxsSession('session-person-older-0000000000000001', $person->id, '198.51.100.21', 'older-agent/1.0', 60);
    dxsSession('session-person-newer-0000000000000002', $person->id, '198.51.100.22', 'newer-agent/1.0', 10);
    $export = app(AccountExport::class);

    expect($export->of($demo)['sessions'])->toBe([])
        ->and($export->of($demo, null)['sessions'])->toBe([])
        ->and(array_column($export->of($person)['sessions'], 'ip_address'))->toBe(['198.51.100.22', '198.51.100.21'])
        ->and(array_column($export->of($person, 'session-person-newer-0000000000000002')['sessions'], 'ip_address'))->toBe(['198.51.100.22', '198.51.100.21']);
});

it('still exports every session record of a real account signed in through the session cookie, and no one else\'s', function (): void {
    $person = Accounts::user(['email' => 'collector@example.com']);
    $other = Accounts::user(['email' => 'other@example.com']);
    dxsSession('session-person-older-0000000000000001', $person->id, '198.51.100.21', 'older-agent/1.0', 60);
    dxsSession('session-person-newer-0000000000000002', $person->id, '198.51.100.22', 'newer-agent/1.0', 30);
    dxsSession('session-other-0000000000000000000003', $other->id, '203.0.113.23', 'other-agent/9.9', 5);

    $login = $this->withServerVariables(['REMOTE_ADDR' => DXS_CALLER_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXS_CALLER_AGENT])
        ->postJson('/login', ['email' => 'collector@example.com', 'password' => Accounts::PASSWORD]);
    $login->assertOk();
    $sessionId = (string) Accounts::sessionId($login);
    expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($person->id);

    $response = dxsExport($this, $sessionId);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.account.email'))->toBe('collector@example.com')
        ->and(array_map(fn (array $s): array => [$s['ip_address'], $s['user_agent']], $response->json('data.sessions')))->toBe([
            [DXS_CALLER_IP, DXS_CALLER_AGENT],
            ['198.51.100.22', 'newer-agent/1.0'],
            ['198.51.100.21', 'older-agent/1.0'],
        ])
        ->and((string) $response->getContent())->not->toContain('203.0.113.23');
});
