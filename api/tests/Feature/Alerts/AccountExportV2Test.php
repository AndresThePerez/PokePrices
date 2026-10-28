<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;

// Plan P5-i1 T05 criterion 5 (D9, review A10.4): GET /v1/me/export version 2 adds the alert threshold of each
// watchlist entry, the notification settings, the webhooks (url_hint only: never the URL, the signing secret or a
// Discord token) and the newest deliveries (never their body), at most alerts.export_deliveries_limit of them.
// Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3).

const T5_EXPORT_DISCORD = 'https://discord.com/api/webhooks/1/test-token-0000';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

function t5eDelivery(User $user, array $fields): void
{
    DB::table('deliveries')->insert($fields + [
        'message_id' => 'msg_'.strtoupper(bin2hex(random_bytes(13))),
        'user_id' => $user->id,
        'body' => '{"sentinel":"T5-EXPORT-BODY-0000"}',
        'next_attempt_at' => '2026-10-13 20:40:00+00',
    ]);
}

/** A verified person with a threshold, settings, one endpoint of each kind and two deliveries. */
function t5ePerson(): User
{
    $user = Accounts::user(['email' => 'collector@example.com', 'email_verified_at' => '2026-10-01 09:45:00', 'created_at' => '2026-10-01 09:30:00']);
    DB::table('watchlist_items')->insert([
        ['user_id' => $user->id, 'variant_key' => AccountRows::variant('base1-4:holo:unlimited:-'), 'created_at' => '2026-10-03 10:00:00+00', 'alert_pct' => 10],
        ['user_id' => $user->id, 'variant_key' => AccountRows::variant('ex5-17:reverse:-:-'), 'created_at' => '2026-10-02 11:15:30+00', 'alert_pct' => null],
    ]);
    DB::insert("INSERT INTO notification_settings (user_id, channel, digest, updated_at) VALUES (?, 'discord', true, '2026-10-08 09:00:00+00')", [$user->id]);
    $standard = AlertRows::endpoint($user, 'standard');
    $discord = AlertRows::endpoint($user, 'discord', disabled: true);
    DB::table('webhook_endpoints')->where('id', $standard)->update(['description' => 'Home bot', 'created_at' => '2026-10-04 08:00:00+00']);
    DB::table('webhook_endpoints')->where('id', $discord)->update(['created_at' => '2026-10-05 08:00:00+00']);
    t5eDelivery($user, [
        'kind' => 'price.moved', 'channel' => 'webhook', 'endpoint_id' => $standard, 'event_id' => 1, 'day' => '2026-10-12',
        'status' => 'delivered', 'attempts' => 3, 'created_at' => '2026-10-12 20:40:00+00', 'delivered_at' => '2026-10-12 20:50:00+00',
    ]);
    t5eDelivery($user, [
        'kind' => 'webhook.test', 'channel' => 'discord', 'endpoint_id' => $discord, 'day' => null,
        'status' => 'failed', 'attempts' => 1, 'last_error' => 'http_status', 'last_status' => 404, 'created_at' => '2026-10-13 07:00:00+00',
    ]);

    return $user;
}

it('exports version 2: the threshold per watchlist entry, the settings, the webhooks and the deliveries, newest first', function (): void {
    $user = t5ePerson();

    $data = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export')->assertOk()->json('data');

    expect(array_keys($data))->toBe(['format', 'version', 'account', 'watchlist', 'portfolio', 'sessions', 'notifications', 'webhooks', 'deliveries'])
        ->and($data['version'])->toBe(2)
        ->and($data['watchlist'])->toBe([
            ['variant_key' => 'base1-4:holo:unlimited:-', 'added_at' => '2026-10-03T10:00:00Z', 'alert_pct' => 10],
            ['variant_key' => 'ex5-17:reverse:-:-', 'added_at' => '2026-10-02T11:15:30Z', 'alert_pct' => null],
        ])
        ->and($data['notifications'])->toBe(['channel' => 'discord', 'digest' => true])
        ->and($data['webhooks'])->toBe([
            ['kind' => 'standard', 'url_hint' => 'https://hooks.example.com/…', 'description' => 'Home bot', 'created_at' => '2026-10-04T08:00:00Z', 'disabled' => false],
            ['kind' => 'discord', 'url_hint' => 'https://discord.com/…', 'description' => null, 'created_at' => '2026-10-05T08:00:00Z', 'disabled' => true],
        ])
        ->and($data['deliveries'])->toBe([
            ['kind' => 'webhook.test', 'channel' => 'discord', 'day' => null, 'status' => 'failed', 'attempts' => 1, 'created_at' => '2026-10-13T07:00:00Z', 'delivered_at' => null],
            ['kind' => 'price.moved', 'channel' => 'webhook', 'day' => '2026-10-12', 'status' => 'delivered', 'attempts' => 3, 'created_at' => '2026-10-12T20:40:00Z', 'delivered_at' => '2026-10-12T20:50:00Z'],
        ]);
});

it('never exports a webhook URL, a signing secret, a Discord token or a delivery body', function (): void {
    $user = t5ePerson();

    $raw = (string) AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export')->assertOk()->getContent();
    $unescaped = str_replace('\\/', '/', $raw);

    foreach ([AlertRows::URL, T5_EXPORT_DISCORD, 'test-token-0000', 'pokeprices/test-0000', AlertRows::secret(), 'whsec_', 'T5-EXPORT-BODY-0000', '"url"', '"secret"', '"body"'] as $index => $needle) {
        // The message names the position only, never the value.
        expect(str_contains($unescaped, $needle))->toBeFalse("forbidden value #{$index} appears in the export");
    }
});

it('never exports another user\'s settings, webhooks or deliveries', function (): void {
    t5ePerson();
    $other = Accounts::user(['email' => 'other@example.com']);

    $data = AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export')->assertOk()->json('data');

    expect($data['notifications'])->toBe(['channel' => 'email', 'digest' => false])
        ->and($data['webhooks'])->toBe([])
        ->and($data['deliveries'])->toBe([])
        ->and($data['watchlist'])->toBe([]);
});

it('holds the newest 500 of 501 deliveries, newest first', function (): void {
    $user = Accounts::user();
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = [
            'message_id' => sprintf('msg_T5EXPORT%016d', $i), 'user_id' => $user->id, 'kind' => 'digest', 'channel' => 'email',
            'day' => '2026-10-12', 'body' => '{}', 'status' => 'delivered', 'attempts' => 1,
            'next_attempt_at' => '2026-10-13 00:00:00+00',
            'created_at' => gmdate('Y-m-d H:i:s', 1_760_000_000 + $i).'+00',
        ];
    }
    // The digest index allows one per (user, day); these rows need distinct days.
    foreach ($rows as $i => &$row) {
        $row['day'] = gmdate('Y-m-d', strtotime('2024-01-01 +'.$i.' days'));
    }
    unset($row);
    DB::table('deliveries')->insert($rows);

    $deliveries = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export')->assertOk()->json('data.deliveries');

    expect(count($deliveries))->toBe(500)
        ->and($deliveries[0]['created_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', 1_760_000_500))
        ->and($deliveries[499]['created_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', 1_760_000_001));
});

it('takes the cap from alerts.export_deliveries_limit', function (): void {
    config(['alerts.export_deliveries_limit' => 1]);
    $user = t5ePerson();

    $deliveries = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export')->assertOk()->json('data.deliveries');

    expect(array_column($deliveries, 'kind'))->toBe(['webhook.test'])
        ->and(config('alerts.export_deliveries_limit'))->toBe(1);
});

it('ships alerts.export_deliveries_limit 500', function (): void {
    $config = require config_path('alerts.php');

    expect($config['export_deliveries_limit'] ?? null)->toBe(500);
});
