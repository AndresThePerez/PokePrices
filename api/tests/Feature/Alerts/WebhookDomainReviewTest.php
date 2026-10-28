<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\GuardError;
use App\Domain\Alerts\NotificationSettings;
use App\Domain\Alerts\UrlGuard;
use App\Domain\Alerts\WebhookEndpoints;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan Q1-i4 T02 review: webhook and notification writes refuse by throwing from Domain; the test send finds its
// endpoint through Domain; the address guard's refusals are a closed enum. The order of the checks, the lock, the
// per-kind limits, ownership and the stored delivery error strings must not change.

const Q4T2R_URL = 'https://hooks.example.com/pokeprices';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10'], 'discord.com' => ['198.51.100.7']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function q4t2rAs(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

/** @return array<string, list<string>>|null the errors of the ValidationException the call throws, or null */
function q4t2rRefusal(callable $call): ?array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('takes the per-user lock, then counts, and inserts only below the limit (nothing after the count when refused)', function (): void {
    config(['alerts.webhooks.limit' => 1]);
    $user = Accounts::user();
    $endpoints = app(WebhookEndpoints::class);
    $label = function (string $sql): string {
        return match (true) {
            str_contains($sql, 'pg_advisory_xact_lock') => 'lock',
            str_contains($sql, 'count(*)') && str_contains($sql, 'webhook_endpoints') => 'count',
            str_starts_with($sql, 'INSERT INTO webhook_endpoints') => 'insert',
            default => 'other',
        };
    };
    $seen = [];
    DB::listen(function (QueryExecuted $query) use (&$seen, $label): void {
        $seen[] = $label($query->sql);
    });
    $level = DB::transactionLevel();
    $create = fn () => $endpoints->create($user->id, 'standard', Q4T2R_URL, 'https://hooks.example.com/…', null, AlertRows::secret(), CarbonImmutable::now());

    $first = $create();
    $afterFirst = $seen;
    $seen = [];
    $refused = q4t2rRefusal($create);

    expect($first)->toBeInt()
        ->and($afterFirst)->toBe(['lock', 'count', 'insert'])
        ->and($seen)->toBe(['lock', 'count'])
        ->and($refused)->toBe(['url' => ['You can add at most 1 signed webhooks.']])
        ->and(DB::transactionLevel())->toBe($level)
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(1);
});

it('counts each kind against its own limit: zero refuses the first, standard never spends the Discord allowance', function (): void {
    $user = Accounts::user();
    $endpoints = app(WebhookEndpoints::class);
    $make = fn (string $kind) => $endpoints->create($user->id, $kind, $kind === 'discord' ? 'https://discord.com/api/webhooks/1/abc' : Q4T2R_URL, 'hint', null, $kind === 'discord' ? null : AlertRows::secret(), CarbonImmutable::now());

    config(['alerts.webhooks.limit' => 0, 'alerts.webhooks.discord_limit' => 0]);
    $zeroStandard = q4t2rRefusal(fn () => $make('standard'));
    $zeroDiscord = q4t2rRefusal(fn () => $make('discord'));
    config(['alerts.webhooks.limit' => 1, 'alerts.webhooks.discord_limit' => 1]);
    $standard = $make('standard');
    $discord = $make('discord');
    $standardTwo = q4t2rRefusal(fn () => $make('standard'));
    $discordTwo = q4t2rRefusal(fn () => $make('discord'));

    expect($zeroStandard)->toBe(['url' => ['You can add at most 0 signed webhooks.']])
        ->and($zeroDiscord)->toBe(['url' => ['You can add one Discord webhook.']])
        ->and([$standard, $discord])->each->toBeInt()
        ->and($standardTwo)->toBe(['url' => ['You can add at most 1 signed webhooks.']])
        ->and($discordTwo)->toBe(['url' => ['You can add one Discord webhook.']])
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->pluck('kind')->sort()->values()->all())->toBe(['discord', 'standard']);
});

it('answers a test of another person\'s turned-off endpoint 404 webhook-not-found, never 409, and queues one message only for the owner', function (): void {
    $owner = Accounts::user(['email' => 'owner@example.com']);
    $other = Accounts::user(['email' => 'other@example.com']);
    $off = AlertRows::endpoint($owner, 'standard', true);
    $on = AlertRows::endpoint($owner);

    $foreignOff = q4t2rAs($this, $other)->postJson("/v1/webhooks/{$off}/test");
    $foreignOn = q4t2rAs($this, $other)->postJson("/v1/webhooks/{$on}/test");
    $ownOff = q4t2rAs($this, $owner)->postJson("/v1/webhooks/{$off}/test");
    $deliveriesBefore = DB::table('deliveries')->count();
    $ownOn = q4t2rAs($this, $owner)->postJson("/v1/webhooks/{$on}/test");

    expect([$foreignOff->status(), $foreignOff->json('type')])->toBe([404, ProblemDetails::BASE.'webhook-not-found'])
        ->and([$foreignOn->status(), $foreignOn->json('type')])->toBe([404, ProblemDetails::BASE.'webhook-not-found'])
        ->and([$ownOff->status(), $ownOff->json('type')])->toBe([409, ProblemDetails::BASE.'webhook-disabled'])
        ->and($deliveriesBefore)->toBe(0)
        ->and($ownOn->status())->toBe(202)
        ->and(DB::table('deliveries')->get(['endpoint_id', 'kind', 'user_id'])->map(fn ($r): array => [(int) $r->endpoint_id, $r->kind, (int) $r->user_id])->all())
        ->toBe([[$on, 'webhook.test', $owner->id]]);
});

it('refuses Discord as the channel without an active Discord webhook of the same person, leaving the saved row as it was', function (): void {
    $user = Accounts::user(['email' => 'a@example.com']);
    $other = Accounts::user(['email' => 'b@example.com']);
    $off = AlertRows::endpoint($user, 'discord', true);
    AlertRows::endpoint($other, 'discord');
    $settings = app(NotificationSettings::class);
    $settings->save($user->id, 'email', true, CarbonImmutable::now());
    $level = DB::transactionLevel();

    $refused = q4t2rRefusal(fn () => $settings->save($user->id, 'discord', false, CarbonImmutable::now()));
    $row = DB::table('notification_settings')->where('user_id', $user->id)->first();
    $otherRow = DB::table('notification_settings')->where('user_id', $other->id)->first();
    DB::table('webhook_endpoints')->where('id', $off)->update(['disabled_at' => null]);
    $accepted = q4t2rRefusal(fn () => $settings->save($user->id, 'discord', false, CarbonImmutable::now()));

    expect($refused)->toBe(['channel' => ['Add a Discord webhook first.']])
        ->and([$row?->channel, $row?->digest])->toBe(['email', true])
        ->and($otherRow)->toBeNull()
        ->and($accepted)->toBeNull()
        ->and(DB::table('notification_settings')->where('user_id', $user->id)->value('channel'))->toBe('discord')
        ->and(DB::transactionLevel())->toBe($level);
});

it('resets only the owner\'s channel when the owner deletes their Discord webhook; another person\'s delete changes nothing', function (): void {
    $owner = Accounts::user(['email' => 'owner@example.com']);
    $other = Accounts::user(['email' => 'other@example.com']);
    $id = AlertRows::endpoint($owner, 'discord');
    app(NotificationSettings::class)->save($owner->id, 'discord', true, CarbonImmutable::now());

    $foreign = q4t2rAs($this, $other)->deleteJson("/v1/webhooks/{$id}");
    $afterForeign = DB::table('notification_settings')->where('user_id', $owner->id)->value('channel');
    $own = q4t2rAs($this, $owner)->deleteJson("/v1/webhooks/{$id}");

    expect($foreign->status())->toBe(204)
        ->and($afterForeign)->toBe('discord')
        ->and($own->status())->toBe(204)
        ->and(DB::table('notification_settings')->where('user_id', $owner->id)->get(['channel', 'digest'])->map(fn ($r): array => [$r->channel, (bool) $r->digest])->all())
        ->toBe([['email', true]]);
});

it('stores only blocked_address or unresolvable as a delivery error, whichever guard refusal stopped a stored URL', function (string $url, string $stored): void {
    DeliveryRows::resolver();
    $user = AlertRows::user('sender@example.com');
    $id = DeliveryRows::delivery(DeliveryRows::endpoint($user, $url));
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    expect(DeliveryRows::row($id)->last_error)->toBe($stored)
        ->and(Http::recorded())->toHaveCount(0);
})->with([
    'plain http (scheme)' => ['http://hooks.example.com/hook', 'blocked_address'],
    'user info (invalid)' => ['https://user@hooks.example.com/hook', 'blocked_address'],
    'fragment (invalid)' => ['https://hooks.example.com/hook#frag', 'blocked_address'],
    'over 2048 bytes (too_long)' => ['https://hooks.example.com/'.str_repeat('a', 2100), 'blocked_address'],
    'name that no longer resolves' => ['https://gone.example.com/hook', 'unresolvable'],
]);

it('refuses a Discord send with the same stored strings: unresolvable for a vanished host, blocked_address for the rest', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    $url = 'https://discord.com/api/webhooks/123456789012345678/abcDEF';
    $send = function (array $dns, string $to): array {
        FakeHostResolver::install($dns);
        app()->forgetInstance(UrlGuard::class);
        app()->forgetInstance(DeliverySender::class);

        return app(DeliverySender::class)->discord($to, '{}');
    };

    $vanished = $send([], $url);
    $private = $send(['discord.com' => ['169.254.1.1']], $url);
    $notDiscord = $send(['hooks.example.com' => ['203.0.113.10']], 'https://hooks.example.com/api/webhooks/1/abc');

    expect([$vanished[0], $vanished[1]])->toBe([null, 'unresolvable'])
        ->and([$private[0], $private[1]])->toBe([null, 'blocked_address'])
        ->and([$notDiscord[0], $notDiscord[1]])->toBe([null, 'blocked_address'])
        ->and(Http::recorded())->toHaveCount(0);
});

it('draws the 2048-byte and port-range lines exactly: the last allowed value passes with no error, the next is too_long or invalid', function (): void {
    $guard = app(UrlGuard::class);
    $endpoints = app(WebhookEndpoints::class);
    $base = 'https://hooks.example.com/';
    $at = $base.str_repeat('a', 2048 - strlen($base));
    $over = $at.'a';
    $cases = [
        '2048 bytes' => [$at, null, null],
        '2049 bytes' => [$over, GuardError::TooLong, WebhookEndpoints::TOO_LONG],
        'port 65535' => ['https://hooks.example.com:65535/x', null, null],
        'port 65536' => ['https://hooks.example.com:65536/x', GuardError::Invalid, WebhookEndpoints::HTTPS],
        'port 0' => ['https://hooks.example.com:0/x', GuardError::Invalid, WebhookEndpoints::HTTPS],
        'six-digit port' => ['https://hooks.example.com:123456/x', GuardError::Invalid, WebhookEndpoints::HTTPS],
        'http scheme' => ['http://hooks.example.com/x', GuardError::Scheme, WebhookEndpoints::HTTPS],
        'private literal' => ['https://169.254.1.1/x', GuardError::BlockedAddress, WebhookEndpoints::PRIVATE],
        'unknown name' => ['https://gone.example.com/x', GuardError::Unresolvable, WebhookEndpoints::UNRESOLVABLE],
    ];

    $got = [];
    foreach ($cases as $name => [$url]) {
        $result = $guard->check($url);
        $got[$name] = [$result->error, $result->allowed(), $endpoints->check('standard', $url)[0]];
    }

    $expected = [];
    foreach ($cases as $name => [, $error, $message]) {
        $expected[$name] = [$error, $error === null, $message];
    }
    expect($got)->toBe($expected);
});
