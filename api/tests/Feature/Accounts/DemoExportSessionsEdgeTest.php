<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts;

// Plan Q0-i1 T02 (security finding A1), adversarial edge cases: the demo's export never reaches another visitor's
// session record through the request input, a forged cookie, a session id of another account, a NULL-valued row or
// the session id itself. Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3).

const DXE_IP = '192.0.2.11';

const DXE_AGENT = 'edge-browser/1.0';

const DXE_OTHER_ID = 'session-demo-visitor-z-000000000000009';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config([
        'session.driver' => 'database', 'session.lottery' => [0, 100],
        'accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null,
    ]);
    dxeForget();
});

function dxeForget(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function dxeRow(string $id, ?int $userId, ?string $ip, ?string $agent, int $secondsAgo): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $userId, 'ip_address' => $ip, 'user_agent' => $agent,
        'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp() - $secondsAgo,
    ]);
}

function dxeDemo(): User
{
    return Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
}

function dxeEnter(object $test): string
{
    $enter = $test->withServerVariables(['REMOTE_ADDR' => DXE_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXE_AGENT])->postJson('/v1/demo/session');
    $enter->assertNoContent();

    return (string) Accounts::sessionId($enter);
}

function dxeExport(object $test, string $sessionId, string $query = ''): TestResponse
{
    dxeForget();

    return $test->withServerVariables(['REMOTE_ADDR' => DXE_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXE_AGENT])->withCredentials()
        ->withCookie((string) config('session.cookie'), $sessionId)->getJson('/v1/me/export'.$query);
}

it('ignores a session id, a header or a body smuggled in by the demo caller: only its own record is exported', function (): void {
    $demo = dxeDemo();
    dxeRow(DXE_OTHER_ID, $demo->id, '192.0.2.99', 'victim-visitor/9.9', 20);
    $own = dxeEnter($this);

    dxeForget();
    $response = $this->withServerVariables(['REMOTE_ADDR' => DXE_IP])
        ->withHeaders(Accounts::FIRST_PARTY + ['User-Agent' => DXE_AGENT, 'X-Session-Id' => DXE_OTHER_ID, 'X-Session' => DXE_OTHER_ID])
        ->withCredentials()->withCookie((string) config('session.cookie'), $own)
        ->getJson('/v1/me/export?id='.DXE_OTHER_ID.'&session_id='.DXE_OTHER_ID.'&session='.DXE_OTHER_ID.'&all=1');

    $raw = (string) $response->getContent();
    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($response->json('data.sessions'), 'ip_address'))->toBe([DXE_IP])
        ->and($raw)->not->toContain('192.0.2.99')
        ->and($raw)->not->toContain('victim-visitor');
});

it('answers 401 and exports nothing to a forged or wildcard session cookie on the demo user\'s id', function (): void {
    $demo = dxeDemo();
    dxeRow(DXE_OTHER_ID, $demo->id, '192.0.2.99', 'victim-visitor/9.9', 20);

    // The victim's own id is not a credential the caller holds, but a stolen cookie is that visitor, by design;
    // what must hold is that an unknown id and a SQL wildcard do not widen the match to the demo's other rows.
    foreach (['%', '_', str_repeat('a', 40), "' OR '1'='1"] as $forged) {
        $response = dxeExport($this, $forged);
        expect($response->getStatusCode())->toBe(401, "forged cookie {$forged}")
            ->and((string) $response->getContent())->not->toContain('victim-visitor')
            ->and((string) $response->getContent())->not->toContain('192.0.2.99');
    }
});

it('exports no session record for the demo when the given session id belongs to another account\'s row', function (): void {
    $demo = dxeDemo();
    $person = Accounts::user(['email' => 'collector@example.com']);
    dxeRow('session-person-0000000000000000000001', $person->id, '198.51.100.21', 'person-agent/1.0', 10);
    dxeRow(DXE_OTHER_ID, $demo->id, '192.0.2.99', 'victim-visitor/9.9', 20);
    $export = app(AccountExport::class);

    expect($export->of($demo, 'session-person-0000000000000000000001')['sessions'])->toBe([])
        ->and($export->of($demo, '')['sessions'])->toBe([])
        ->and($export->of($demo, '%')['sessions'])->toBe([])
        ->and(array_column($export->of($demo, DXE_OTHER_ID)['sessions'], 'ip_address'))->toBe(['192.0.2.99']);
});

it('exports the demo caller\'s own row when its address and browser are NULL, with the same three keys', function (): void {
    $demo = dxeDemo();
    dxeRow(DXE_OTHER_ID, $demo->id, '192.0.2.99', 'victim-visitor/9.9', 20);
    dxeRow('session-demo-caller-null-0000000000001', $demo->id, null, null, 3);

    $sessions = app(AccountExport::class)->of($demo, 'session-demo-caller-null-0000000000001')['sessions'];

    expect($sessions)->toHaveCount(1)
        ->and(array_keys($sessions[0]))->toBe(['last_active_at', 'ip_address', 'user_agent'])
        ->and($sessions[0]['ip_address'])->toBeNull()
        ->and($sessions[0]['user_agent'])->toBeNull();
});

it('never writes the session id of the demo caller or of another visitor into the export body', function (): void {
    $demo = dxeDemo();
    dxeRow(DXE_OTHER_ID, $demo->id, '192.0.2.99', 'victim-visitor/9.9', 20);
    $own = dxeEnter($this);

    $raw = (string) dxeExport($this, $own)->getContent();

    expect($raw)->not->toContain($own)
        ->and($raw)->not->toContain(DXE_OTHER_ID)
        ->and($raw)->not->toContain('payload');
});

it('stops narrowing the moment the account is no longer the demo, and starts narrowing when it becomes one', function (): void {
    $user = Accounts::user(['email' => 'flip@example.com']);
    dxeRow('session-flip-a-0000000000000000000001', $user->id, '198.51.100.21', 'a/1.0', 30);
    dxeRow('session-flip-b-0000000000000000000002', $user->id, '198.51.100.22', 'b/1.0', 10);
    $export = app(AccountExport::class);

    expect(array_column($export->of($user, 'session-flip-a-0000000000000000000001')['sessions'], 'ip_address'))->toHaveCount(2);

    $user->forceFill(['is_demo' => true])->save();
    expect(array_column($export->of($user->refresh(), 'session-flip-a-0000000000000000000001')['sessions'], 'ip_address'))->toBe(['198.51.100.21'])
        ->and($export->of($user, null)['sessions'])->toBe([]);
});
