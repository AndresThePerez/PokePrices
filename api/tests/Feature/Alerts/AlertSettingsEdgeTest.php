<?php

declare(strict_types=1);

use App\Http\Middleware\IgnoreQueryCredentials;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan P5-i1 T05 adversarial review, round 1: the edges of the threshold range split (DB 1..100, API from config),
// non-object and exotic JSON bodies, query-string smuggling, credential-stripping breadth, cross-user isolation of the
// channel reset and the export's leak surface. Every /v1 response is also validated against openapi/api.json (C3).

const T5X_KEY = 'base1-4:holo:unlimited:-';
const T5X_OTHER_KEY = 'ex5-17:reverse:-:-';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function t5xAs(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

/** @return array<string, int|null> */
function t5xThresholds(User $user): array
{
    /** @var array<string, int|null> */
    return DB::table('watchlist_items')->where('user_id', $user->id)->orderBy('variant_key')->pluck('alert_pct', 'variant_key')->all();
}

/** @return array{0: string, 1: bool}|null */
function t5xSettings(User $user): ?array
{
    $row = DB::table('notification_settings')->where('user_id', $user->id)->first();

    return $row === null ? null : [(string) $row->channel, (bool) $row->digest];
}

it('never lets the watchlist route write a threshold: a body alert_pct is ignored and a re-add keeps the one set', function (): void {
    $user = Accounts::user();
    AccountRows::variant(T5X_KEY);

    t5xAs($this, $user)->putJson('/v1/watchlist/'.T5X_KEY, ['alert_pct' => 50, 'pct' => 50])->assertOk();
    expect(t5xThresholds($user))->toBe([T5X_KEY => null]);

    t5xAs($this, $user)->putJson('/v1/alerts/'.T5X_KEY, ['pct' => 12])->assertOk();
    t5xAs($this, $user)->putJson('/v1/watchlist/'.T5X_KEY, ['alert_pct' => 99])->assertOk();

    expect(t5xThresholds($user))->toBe([T5X_KEY => 12]);
});

it('serves and clears a row the API would refuse: a direct-DB 3 reads back as 3 in the list and export, PUT 4 keeps it, DELETE clears it', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5X_KEY);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 3]);

    $list = t5xAs($this, $user)->getJson('/v1/watchlist')->assertOk();
    $export = t5xAs($this, $user)->getJson('/v1/me/export')->assertOk();
    expect($list->json('data.0.alert_pct'))->toBe(3)
        ->and($export->json('data.watchlist.0.alert_pct'))->toBe(3);

    $refused = t5xAs($this, $user)->putJson('/v1/alerts/'.T5X_KEY, ['pct' => 4]);
    expect($refused->getStatusCode())->toBe(422)
        ->and(t5xThresholds($user))->toBe([T5X_KEY => 3]);

    t5xAs($this, $user)->deleteJson('/v1/alerts/'.T5X_KEY)->assertNoContent();
    expect(t5xThresholds($user))->toBe([T5X_KEY => null]);

    // The CHECK still holds below and above whatever the API validates.
    expect(fn () => DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 0]))->toThrow(QueryException::class, 'watchlist_items_alert_pct_check');
});

it('answers a body that is not a JSON object, or is malformed or exotic, with 422 and never 500, changing nothing', function (string $raw): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5X_KEY);
    $server = $this->transformHeadersToServerVars(Accounts::FIRST_PARTY + ['Content-Type' => 'application/json']);

    $alert = AccountRows::signIn($this, $user)->call('PUT', '/v1/alerts/'.T5X_KEY, [], [], [], $server, $raw);
    $settings = AccountRows::signIn($this, $user)->call('PUT', '/v1/me/notifications', [], [], [], $server, $raw);

    expect($alert->getStatusCode())->toBe(422)
        ->and($alert->json('errors'))->toHaveKey('pct')
        ->and($settings->getStatusCode())->toBe(422)
        ->and(t5xThresholds($user))->toBe([T5X_KEY => null])
        ->and(t5xSettings($user))->toBeNull();
})->with([
    'scalar number' => ['10'],
    'scalar string' => ['"10"'],
    'null' => ['null'],
    'list' => ['[10]'],
    'empty' => [''],
    'malformed' => ['{"pct": 10'],
    'int64 overflow becomes a float' => ['{"pct": 9223372036854775808}'],
    'int64 max' => ['{"pct": 9223372036854775807}'],
    'float infinity' => ['{"pct": 1e999}'],
    'negative zero float' => ['{"pct": -0.0}'],
    'object' => ['{"pct": {"v": 10}}'],
]);

it('does not read pct, channel or digest from the query string', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5X_KEY);

    $alert = t5xAs($this, $user)->putJson('/v1/alerts/'.T5X_KEY.'?pct=10', []);
    $settings = t5xAs($this, $user)->putJson('/v1/me/notifications?channel=email&digest=true', []);

    expect($alert->getStatusCode())->toBe(422)
        ->and(t5xThresholds($user))->toBe([T5X_KEY => null])
        ->and($settings->getStatusCode())->toBe(422)
        ->and(t5xSettings($user))->toBeNull();

    // The body wins over a conflicting query: the smuggled value never reaches the row.
    t5xAs($this, $user)->putJson('/v1/alerts/'.T5X_KEY.'?pct=90', ['pct' => 15])->assertOk();
    expect(t5xThresholds($user))->toBe([T5X_KEY => 15]);
});

it('ignores unknown body keys on PUT /v1/me/notifications: another user\'s row is never written', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    DB::insert("INSERT INTO notification_settings (user_id, channel, digest, updated_at) VALUES (?, 'email', true, '2026-10-01 00:00:00+00')", [$other->id]);

    t5xAs($this, $user)->putJson('/v1/me/notifications', ['channel' => 'email', 'digest' => false, 'user_id' => $other->id, 'updated_at' => '2030-01-01'])->assertOk();

    $row = DB::table('notification_settings')->where('user_id', $other->id)->first();
    expect(t5xSettings($other))->toBe(['email', true])
        ->and(str_starts_with((string) $row?->updated_at, '2026-10-01'))->toBeTrue()
        ->and(t5xSettings($user))->toBe(['email', false]);
});

it('resets only the deleting user\'s channel: another user on discord keeps theirs, untouched', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    $mine = AlertRows::endpoint($user, 'discord');
    AlertRows::endpoint($other, 'discord');
    t5xAs($this, $user)->putJson('/v1/me/notifications', ['channel' => 'discord', 'digest' => true])->assertOk();
    t5xAs($this, $other)->putJson('/v1/me/notifications', ['channel' => 'discord', 'digest' => false])->assertOk();
    DB::update("UPDATE notification_settings SET updated_at = '2026-10-01 00:00:00+00' WHERE user_id = ?", [$other->id]);

    t5xAs($this, $user)->deleteJson('/v1/webhooks/'.$mine)->assertNoContent();

    $row = DB::table('notification_settings')->where('user_id', $other->id)->first();
    expect(t5xSettings($user))->toBe(['email', true])
        ->and(t5xSettings($other))->toBe(['discord', false])
        ->and(str_starts_with((string) $row?->updated_at, '2026-10-01'))->toBeTrue()
        ->and(t5xAs($this, $other)->getJson('/v1/me/notifications')->json('data'))->toBe(['channel' => 'discord', 'digest' => false, 'discord_available' => true]);
});

it('lets a person whose Discord webhook was turned off still read honestly and move to email, but not re-save discord', function (): void {
    $user = Accounts::user();
    $discord = AlertRows::endpoint($user, 'discord');
    t5xAs($this, $user)->putJson('/v1/me/notifications', ['channel' => 'discord', 'digest' => true])->assertOk();
    DB::table('webhook_endpoints')->where('id', $discord)->update(['disabled_at' => '2026-10-12 00:00:00+00']);

    $read = t5xAs($this, $user)->getJson('/v1/me/notifications')->assertOk()->json('data');
    $again = t5xAs($this, $user)->putJson('/v1/me/notifications', ['channel' => 'discord', 'digest' => false]);
    $email = t5xAs($this, $user)->putJson('/v1/me/notifications', ['channel' => 'email', 'digest' => true]);

    // The stored choice is reported as stored, and the unavailability is reported beside it.
    expect($read)->toBe(['channel' => 'discord', 'digest' => true, 'discord_available' => false])
        ->and($again->getStatusCode())->toBe(422)
        ->and($again->json('errors'))->toBe(['channel' => ['Add a Discord webhook first.']])
        ->and($email->json('data'))->toBe(['channel' => 'email', 'digest' => true, 'discord_available' => false])
        ->and(t5xSettings($user))->toBe(['email', true]);
});

it('strips every credential from the query on routes the P4 tests do not reach, and nothing else', function (): void {
    $user = Accounts::user();

    // Fortify's password-confirmation route reads a password: in the query alone it must not confirm.
    $confirm = t5xAs($this, $user)->postJson('/user/confirm-password?password='.rawurlencode(Accounts::PASSWORD), []);
    expect($confirm->getStatusCode())->toBe(422)
        ->and(array_keys($confirm->json('errors')))->toBe(['password']);

});

it('does not reset a password from the query string alone and keeps look-alike query keys intact', function (): void {
    $user = Accounts::user();

    $reset = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/reset-password?password=brand-new-0000-ok&password_confirmation=brand-new-0000-ok', [
        'token' => 'not-a-token', 'email' => $user->email,
    ]);
    expect($reset->getStatusCode())->toBe(422)
        ->and(password_verify(Accounts::PASSWORD, (string) $user->refresh()->password))->toBeTrue();

    // Nothing else is touched: look-alike names, other case, arrays and a real filter keep their values; array
    // forms of the credential keys are removed whole.
    $request = Request::create('/v1/cards/search?q=char&page=2&Password=a&password_hint=b&pass=c&current_password_x=d&password[]=e&password_confirmation[k]=f&current_password=g', 'GET');
    $seen = (new IgnoreQueryCredentials)->handle($request, fn ($r) => response()->json($r->query->all()));
    expect(json_decode((string) $seen->getContent(), true))->toBe([
        'q' => 'char', 'page' => '2', 'Password' => 'a', 'password_hint' => 'b', 'pass' => 'c', 'current_password_x' => 'd',
    ]);
});

it('never exports the sentinel of any kind of row: email delivery body, user-set description aside, URL tokens and secret', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);
    $standard = AlertRows::endpoint($user, 'standard');
    $discord = AlertRows::endpoint($user, 'discord');
    DB::table('deliveries')->insert([
        ['message_id' => 'msg_T5XEMAIL0000000000000000A', 'user_id' => $user->id, 'kind' => 'digest', 'channel' => 'email', 'endpoint_id' => null, 'day' => '2026-10-12', 'body' => '{"s":"T5X-SENTINEL-EMAIL-BODY"}', 'status' => 'delivered', 'attempts' => 1, 'next_attempt_at' => '2026-10-13 00:00:00+00', 'delivered_at' => '2026-10-13 01:00:00+00', 'created_at' => '2026-10-13 00:30:00+00', 'last_error' => null, 'last_status' => null],
        ['message_id' => 'msg_T5XDISC00000000000000000B', 'user_id' => $user->id, 'kind' => 'alert.triggered', 'channel' => 'discord', 'endpoint_id' => $discord, 'day' => '2026-10-12', 'body' => '{"s":"T5X-SENTINEL-DISCORD-BODY"}', 'status' => 'failed', 'attempts' => 6, 'next_attempt_at' => '2026-10-13 00:00:00+00', 'delivered_at' => null, 'created_at' => '2026-10-13 00:31:00+00', 'last_error' => 'http_status', 'last_status' => 500],
        ['message_id' => 'msg_T5XSIGN00000000000000000C', 'user_id' => $user->id, 'kind' => 'webhook.test', 'channel' => 'webhook', 'endpoint_id' => $standard, 'day' => null, 'body' => '{"s":"T5X-SENTINEL-SIGNED-BODY"}', 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => '2026-10-13 00:00:00+00', 'delivered_at' => null, 'created_at' => '2026-10-13 00:32:00+00', 'last_error' => null, 'last_status' => null],
    ]);

    $response = t5xAs($this, $user)->getJson('/v1/me/export')->assertOk();
    $raw = str_replace('\\/', '/', (string) $response->getContent());

    expect($response->json('data.deliveries'))->toHaveCount(3)
        ->and(array_column($response->json('data.deliveries'), 'channel'))->toBe(['webhook', 'discord', 'email'])
        ->and(array_keys($response->json('data.deliveries.0')))->toBe(['kind', 'channel', 'day', 'status', 'attempts', 'created_at', 'delivered_at']);
    foreach (['T5X-SENTINEL', 'test-token-0000', 'pokeprices/test-0000', 'whsec_', 'last_error', 'last_status', 'message_id', 'msg_T5X', 'endpoint_id', 'next_attempt_at'] as $index => $needle) {
        expect(str_contains($raw, $needle))->toBeFalse("forbidden value #{$index} appears in the export");
    }
});

it('orders equal-time deliveries by id descending and survives a non-positive or non-numeric export limit without a 500', function (): void {
    $user = Accounts::user();
    foreach (['A', 'B', 'C'] as $n => $letter) {
        DB::table('deliveries')->insert([
            'message_id' => 'msg_T5XTIE0000000000000000'.$letter.'0', 'user_id' => $user->id, 'kind' => 'digest', 'channel' => 'email',
            'endpoint_id' => null, 'day' => '2026-10-1'.($n + 1), 'body' => '{}', 'next_attempt_at' => '2026-10-13 00:00:00+00', 'created_at' => '2026-10-13 00:00:00+00',
        ]);
    }

    $days = fn (TestResponse $r): array => array_column($r->json('data.deliveries'), 'day');
    expect($days(t5xAs($this, $user)->getJson('/v1/me/export')->assertOk()))->toBe(['2026-10-13', '2026-10-12', '2026-10-11']);

    config(['alerts.export_deliveries_limit' => -5]);
    expect($days(t5xAs($this, $user)->getJson('/v1/me/export')->assertOk()))->toBe([]);

    config(['alerts.export_deliveries_limit' => 'many']);
    expect($days(t5xAs($this, $user)->getJson('/v1/me/export')->assertOk()))->toHaveCount(3);

    config(['alerts.export_deliveries_limit' => 2]);
    expect($days(t5xAs($this, $user)->getJson('/v1/me/export')->assertOk()))->toBe(['2026-10-13', '2026-10-12']);
});

it('keeps a threshold when the same variant is moved between lists elsewhere: a portfolio write never touches alert_pct', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5X_KEY);
    AccountRows::watch($user, T5X_OTHER_KEY);
    t5xAs($this, $user)->putJson('/v1/alerts/'.T5X_KEY, ['pct' => 25])->assertOk();

    t5xAs($this, $user)->putJson('/v1/portfolio/'.T5X_KEY, ['quantity' => 2])->assertOk();
    t5xAs($this, $user)->deleteJson('/v1/portfolio/'.T5X_KEY)->assertNoContent();

    expect(t5xThresholds($user))->toBe([T5X_KEY => 25, T5X_OTHER_KEY => null]);
});
