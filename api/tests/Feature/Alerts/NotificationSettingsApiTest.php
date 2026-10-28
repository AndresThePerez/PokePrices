<?php

declare(strict_types=1);

use App\Domain\Alerts\WebhookEndpoints;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;

// Plan P5-i1 T05 criteria 3 and 4 (D1, D3 channel rule, D9): GET and PUT /v1/me/notifications read and save where
// alerts and the digest go (email or the account's active Discord webhook) and whether a daily digest is sent; a
// person without a row has the defaults; deleting the Discord webhook sets the channel back to email in the same
// transaction. Only a verified, non-demo account may save. Every /v1 response is also validated against
// openapi/api.json by Tests\TestCase (C3).

const T5_DISCORD_FIRST = 'Add a Discord webhook first.';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function t5nAs(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

function t5nGet(object $test, User $user): TestResponse
{
    return t5nAs($test, $user)->getJson('/v1/me/notifications');
}

/** @param array<string, mixed> $body */
function t5nPut(object $test, User $user, array $body): TestResponse
{
    return t5nAs($test, $user)->putJson('/v1/me/notifications', $body);
}

/** @return array{0: string, 1: bool}|null the user's stored [channel, digest] */
function t5nRow(User $user): ?array
{
    $row = DB::table('notification_settings')->where('user_id', $user->id)->first();

    return $row === null ? null : [(string) $row->channel, (bool) $row->digest];
}

it('answers a person without a settings row with the defaults: email, no digest, no Discord', function (): void {
    $user = Accounts::user();

    $response = t5nGet($this, $user);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['data' => ['channel' => 'email', 'digest' => false, 'discord_available' => false]])
        ->and(t5nRow($user))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('saves email with the digest on: 200 with the same body as GET, persisted', function (): void {
    $user = Accounts::user();

    $put = t5nPut($this, $user, ['channel' => 'email', 'digest' => true]);
    $get = t5nGet($this, $user);

    expect($put->getStatusCode())->toBe(200)
        ->and($put->json())->toBe(['data' => ['channel' => 'email', 'digest' => true, 'discord_available' => false]])
        ->and($get->json())->toBe($put->json())
        ->and(t5nRow($user))->toBe(['email', true]);
});

it('refuses discord without a Discord webhook with 422 errors.channel and saves nothing', function (): void {
    $user = Accounts::user();
    AlertRows::endpoint($user, 'standard');

    $response = t5nPut($this, $user, ['channel' => 'discord', 'digest' => true]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['channel' => [T5_DISCORD_FIRST]])
        ->and(t5nRow($user))->toBeNull();
});

it('counts a disabled Discord webhook as unavailable', function (): void {
    $user = Accounts::user();
    AlertRows::endpoint($user, 'discord', disabled: true);

    $get = t5nGet($this, $user);
    $put = t5nPut($this, $user, ['channel' => 'discord', 'digest' => false]);

    expect($get->json('data.discord_available'))->toBeFalse()
        ->and($put->getStatusCode())->toBe(422)
        ->and($put->json('errors'))->toBe(['channel' => [T5_DISCORD_FIRST]]);
});

it('saves discord with an active Discord webhook, and GET says discord_available', function (): void {
    $user = Accounts::user();
    AlertRows::endpoint($user, 'discord');

    $put = t5nPut($this, $user, ['channel' => 'discord', 'digest' => false]);

    expect($put->getStatusCode())->toBe(200)
        ->and($put->json())->toBe(['data' => ['channel' => 'discord', 'digest' => false, 'discord_available' => true]])
        ->and(t5nGet($this, $user)->json('data'))->toBe(['channel' => 'discord', 'digest' => false, 'discord_available' => true])
        ->and(t5nRow($user))->toBe(['discord', false]);
});

it('sets the channel back to email when the Discord webhook is deleted, keeping the digest choice', function (): void {
    $user = Accounts::user();
    $discord = AlertRows::endpoint($user, 'discord');
    t5nPut($this, $user, ['channel' => 'discord', 'digest' => true])->assertOk();

    t5nAs($this, $user)->deleteJson('/v1/webhooks/'.$discord)->assertNoContent();

    expect(t5nRow($user))->toBe(['email', true])
        ->and(t5nGet($this, $user)->json('data'))->toBe(['channel' => 'email', 'digest' => true, 'discord_available' => false]);
});

it('keeps the channel when a signed webhook is deleted, or when another user deletes the Discord id', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    $discord = AlertRows::endpoint($user, 'discord');
    $standard = AlertRows::endpoint($user, 'standard');
    t5nPut($this, $user, ['channel' => 'discord', 'digest' => false])->assertOk();

    t5nAs($this, $user)->deleteJson('/v1/webhooks/'.$standard)->assertNoContent();
    t5nAs($this, $other)->deleteJson('/v1/webhooks/'.$discord)->assertNoContent();

    expect(t5nRow($user))->toBe(['discord', false])
        ->and(DB::table('webhook_endpoints')->where('id', $discord)->exists())->toBeTrue();
});

it('resets the channel in the same transaction as the delete: a failing reset leaves the webhook in place', function (): void {
    $user = Accounts::user();
    $discord = AlertRows::endpoint($user, 'discord');
    t5nPut($this, $user, ['channel' => 'discord', 'digest' => false])->assertOk();
    // A trigger that refuses the reset proves the delete and the reset commit or fail together.
    DB::statement(<<<'SQL'
        CREATE FUNCTION t5_refuse_reset() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 't5 refuses the reset'; END $$
        SQL);
    DB::statement('CREATE TRIGGER t5_refuse_reset BEFORE UPDATE ON notification_settings FOR EACH ROW EXECUTE FUNCTION t5_refuse_reset()');

    // The controller's delete, called directly: an HTTP 500 is not an answer the contract documents (C3).
    expect(fn () => WebhookEndpoints::delete(DB::connection(), $user->id, $discord))->toThrow(QueryException::class, 't5 refuses the reset');

    expect(DB::table('webhook_endpoints')->where('id', $discord)->exists())->toBeTrue()
        ->and(t5nRow($user))->toBe(['discord', false]);
});

it('refuses a body that is not exactly a channel and a boolean digest with 422', function (array $body, string $field): void {
    $user = Accounts::user();

    $response = t5nPut($this, $user, $body);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toContain($field)
        ->and(t5nRow($user))->toBeNull();
})->with([
    'channel slack' => [['channel' => 'slack', 'digest' => true], 'channel'],
    'channel missing' => [['digest' => true], 'channel'],
    'channel array' => [['channel' => ['email'], 'digest' => true], 'channel'],
    'channel Email' => [['channel' => 'Email', 'digest' => true], 'channel'],
    'digest missing' => [['channel' => 'email'], 'digest'],
    'digest string' => [['channel' => 'email', 'digest' => 'true'], 'digest'],
    'digest 1' => [['channel' => 'email', 'digest' => 1], 'digest'],
    'digest null' => [['channel' => 'email', 'digest' => null], 'digest'],
]);

it('keeps each person\'s settings their own', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    AlertRows::endpoint($other, 'discord');

    t5nPut($this, $user, ['channel' => 'email', 'digest' => true])->assertOk();

    expect(t5nRow($other))->toBeNull()
        ->and(t5nGet($this, $other)->json('data'))->toBe(['channel' => 'email', 'digest' => false, 'discord_available' => true])
        ->and(t5nGet($this, $user)->json('data.discord_available'))->toBeFalse();
});

it('refuses an unverified account 403 email-not-verified on PUT while GET works', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);

    $put = t5nPut($this, $user, ['channel' => 'email', 'digest' => true]);
    $get = t5nGet($this, $user);

    expect([$put->getStatusCode(), $put->json('type')])->toBe([403, ProblemDetails::BASE.'email-not-verified'])
        ->and($get->getStatusCode())->toBe(200)
        ->and(t5nRow($user))->toBeNull();
});

it('refuses the demo 403 demo-read-only on PUT; its GET answers 200 with the defaults', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);

    $put = t5nPut($this, $demo, ['channel' => 'email', 'digest' => true]);
    $get = t5nGet($this, $demo);

    expect([$put->getStatusCode(), $put->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only'])
        ->and($get->json('data'))->toBe(['channel' => 'email', 'digest' => false, 'discord_available' => false])
        ->and(t5nRow($demo))->toBeNull();
});

it('answers signed-out GET and PUT with the 401 problem', function (): void {
    $get = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/notifications');
    $put = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/me/notifications', ['channel' => 'email', 'digest' => true]);

    expect([$get->getStatusCode(), $put->getStatusCode()])->toBe([401, 401])
        ->and($get->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});
