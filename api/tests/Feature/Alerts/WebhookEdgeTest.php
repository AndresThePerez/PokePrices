<?php

declare(strict_types=1);

use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\UrlGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Reviewer edge cases for plan P5-i1 T03 (D4, D5, D6, D9, D13): the signing secret lives only in the 201 body,
// Discord URL shapes that point elsewhere never register, URL length at the byte boundary, deleting frees the
// limits, a registered webhook signs and delivers end to end, other users' ids reveal nothing (not even a disabled
// state), the R235 two-type 403 text sits only on the gated operations, the Discord switch ignores blank values,
// and the 2000-character cut is exact. DNS and HTTP are faked.

const T3E_URL = 'https://hooks.example.com/pokeprices';
const T3E_MARK = 'SENTINEL-edge-0001';
const T3E_DISCORD = 'https://discord.com/api/webhooks/123456789012345678/'.T3E_MARK;
const T3E_SHAPE = 'A Discord webhook URL looks like https://discord.com/api/webhooks/<id>/<token>.';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Http::preventStrayRequests();
    $this->resolver = FakeHostResolver::install([
        'hooks.example.com' => ['203.0.113.10'],
        'discord.com' => ['198.51.100.7'],
        'evil.example.com' => ['203.0.113.66'],
    ]);
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function t3eAs(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

/** @param array<string, mixed> $body */
function t3eStore(object $test, User $user, array $body): TestResponse
{
    return t3eAs($test, $user)->postJson('/v1/webhooks', $body);
}

it('shows the signing secret only in the uncacheable 201, never in a refusal, the list, the row or any log', function (): void {
    $user = Accounts::user();

    $created = t3eStore($this, $user, ['kind' => 'standard', 'url' => T3E_URL]);
    $secret = (string) $created->json('data.secret');
    $refusedDiscord = t3eStore($this, $user, ['kind' => 'discord', 'url' => 'https://evil.example.com/api/webhooks/1/'.T3E_MARK]);
    $refusedHttp = t3eStore($this, $user, ['kind' => 'standard', 'url' => 'http://evil.example.com/'.T3E_MARK]);
    $list = t3eAs($this, $user)->getJson('/v1/webhooks');

    $created->assertCreated();
    expect($created->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr_count($created->getContent(), trim((string) json_encode($secret, JSON_UNESCAPED_UNICODE), '"')))->toBe(1)
        ->and($refusedDiscord->status())->toBe(422)
        ->and($refusedDiscord->getContent())->not->toContain(T3E_MARK)
        ->and($refusedHttp->status())->toBe(422)
        ->and($refusedHttp->getContent())->not->toContain(T3E_MARK)
        ->and($list->getContent())->not->toContain(substr($secret, 6, 20))
        ->and($list->getContent())->not->toContain('whsec_')
        ->and(json_encode(DB::table('webhook_endpoints')->get(), JSON_UNESCAPED_SLASHES))->not->toContain(substr($secret, 6, 20))
        ->and(json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->not->toContain($secret)
        ->and(json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->not->toContain(T3E_MARK);
});

it('never registers a Discord URL that passes a lookalike shape but points elsewhere, and never resolves it', function (string $url): void {
    $user = Accounts::user();

    $response = t3eStore($this, $user, ['kind' => 'discord', 'url' => $url]);

    expect($response->status())->toBe(422)
        ->and($response->json('errors'))->toBe(['url' => [T3E_SHAPE]])
        ->and($response->getContent())->not->toContain(T3E_MARK)
        ->and(DB::table('webhook_endpoints')->count())->toBe(0)
        ->and($this->resolver->asked)->toBe([]);
})->with([
    'user-info naming discord' => ['https://discord.com@evil.example.com/api/webhooks/1/'.T3E_MARK],
    'user-info with a colon' => ['https://discord.com:x@evil.example.com/api/webhooks/1/'.T3E_MARK],
    'discord as a subdomain of another host' => ['https://discord.com.evil.example.com/api/webhooks/1/'.T3E_MARK],
    'a longer host ending in discord.com' => ['https://evildiscord.com/api/webhooks/1/'.T3E_MARK],
    'an explicit port' => ['https://discord.com:8443/api/webhooks/1/'.T3E_MARK],
    'the default port spelled out' => ['https://discord.com:443/api/webhooks/1/'.T3E_MARK],
    'a dot-dot token' => ['https://discord.com/api/webhooks/1/'.T3E_MARK.'/../../x'],
    'a fragment' => ['https://discord.com/api/webhooks/1/'.T3E_MARK.'#evil.example.com'],
    'a backslash authority' => ['https://discord.com\\@evil.example.com/api/webhooks/1/'.T3E_MARK],
    'a percent-encoded token tail' => ['https://discord.com/api/webhooks/1/'.T3E_MARK.'%2F..%2Fx'],
    'fullwidth digits in the id' => ['https://discord.com/api/webhooks/１２３/'.T3E_MARK],
    'an upper-case host' => ['https://DISCORD.COM/api/webhooks/1/'.T3E_MARK],
    'an IP literal host' => ['https://203.0.113.66/api/webhooks/1/'.T3E_MARK],
]);

it('accepts a URL of exactly 2048 bytes and refuses 2049 with the too-long message, for both kinds', function (): void {
    $user = Accounts::user();
    $signed = 'https://hooks.example.com/'.str_repeat('a', 2048 - 26);
    $discord = 'https://discord.com/api/webhooks/1/'.str_repeat('a', 2048 - 35);

    $okSigned = t3eStore($this, $user, ['kind' => 'standard', 'url' => $signed]);
    $okDiscord = t3eStore($this, $user, ['kind' => 'discord', 'url' => $discord]);
    $longSigned = t3eStore($this, $user, ['kind' => 'standard', 'url' => $signed.'a']);
    $longDiscord = t3eStore($this, $user, ['kind' => 'discord', 'url' => $discord.'a']);

    expect(strlen($signed))->toBe(UrlGuard::MAX_BYTES)
        ->and([$okSigned->status(), $okDiscord->status()])->toBe([201, 201])
        ->and([$longSigned->status(), $longDiscord->status()])->toBe([422, 422])
        ->and($longSigned->json('errors.url'))->toBe(['The URL is too long.'])
        ->and($longDiscord->json('errors.url'))->toBe(['The URL is too long.'])
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(2);
});

it('frees a limit when a webhook is deleted, and a second delete of the same id is still 204', function (): void {
    $user = Accounts::user();
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = (int) t3eStore($this, $user, ['kind' => 'standard', 'url' => T3E_URL])->json('data.id');
    }
    $discordId = (int) t3eStore($this, $user, ['kind' => 'discord', 'url' => T3E_DISCORD])->json('data.id');
    $full = t3eStore($this, $user, ['kind' => 'standard', 'url' => T3E_URL]);
    $secondDiscord = t3eStore($this, $user, ['kind' => 'discord', 'url' => T3E_DISCORD]);

    $first = t3eAs($this, $user)->deleteJson("/v1/webhooks/{$ids[0]}");
    $again = t3eAs($this, $user)->deleteJson("/v1/webhooks/{$ids[0]}");
    t3eAs($this, $user)->deleteJson("/v1/webhooks/{$discordId}")->assertNoContent();
    $sixthNow = t3eStore($this, $user, ['kind' => 'standard', 'url' => T3E_URL]);
    $discordNow = t3eStore($this, $user, ['kind' => 'discord', 'url' => T3E_DISCORD]);

    expect([$full->status(), $secondDiscord->status()])->toBe([422, 422])
        ->and([$first->status(), $again->status()])->toBe([204, 204])
        ->and([$sixthNow->status(), $discordNow->status()])->toBe([201, 201])
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->where('kind', 'standard')->count())->toBe(5)
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->where('kind', 'discord')->count())->toBe(1);
});

it('signs a test message with the very secret the 201 showed, and the list then shows it delivered', function (): void {
    $user = Accounts::user();
    $created = t3eStore($this, $user, ['kind' => 'standard', 'url' => T3E_URL]);
    $id = (int) $created->json('data.id');
    $secret = (string) $created->json('data.secret');
    $messageId = (string) t3eAs($this, $user)->postJson("/v1/webhooks/{$id}/test")->assertStatus(202)->json('data.message_id');
    Http::fake(['*' => Http::response('', 200)]);

    [$code, $summary] = DeliveryRows::sweep();

    $requests = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
    expect($code)->toBe(0)
        ->and($summary['delivered'] ?? null)->toBe(1)
        ->and($requests)->toHaveCount(1);
    $sent = $requests[0];
    $expected = 'v1,'.base64_encode(hash_hmac('sha256', $messageId.'.'.$sent->header('webhook-timestamp')[0].'.'.$sent->body(), (string) base64_decode(substr($secret, 6), true), true));
    $list = t3eAs($this, $user)->getJson('/v1/webhooks');
    expect($sent->url())->toBe(T3E_URL)
        ->and($sent->header('webhook-id'))->toBe([$messageId])
        ->and($sent->header('webhook-signature'))->toBe([$expected])
        ->and($list->json('data.0.last_delivery.status'))->toBe('delivered')
        ->and($list->getContent())->not->toContain($secret);
});

it('delivers a Discord test message end to end, and a Discord 400 or 403 fails it without turning the webhook off', function (int $status): void {
    $user = Accounts::user();
    $id = (int) t3eStore($this, $user, ['kind' => 'discord', 'url' => T3E_DISCORD])->json('data.id');
    t3eAs($this, $user)->postJson("/v1/webhooks/{$id}/test")->assertStatus(202);
    Http::fake(['*' => Http::response('', $status)]);

    DeliveryRows::sweep();

    $requests = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
    $list = t3eAs($this, $user)->getJson('/v1/webhooks');
    $headers = array_map('strtolower', array_keys($requests[0]->headers()));
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->body())->toBe(DiscordMessage::envelope(DiscordMessage::TEST_TEXT))
        ->and(array_values(array_filter($headers, fn (string $h): bool => str_starts_with($h, 'webhook-'))))->toBe([])
        ->and($list->json('data.0.disabled'))->toBeFalse()
        ->and($list->json('data.0.last_delivery.status'))->toBe($status === 204 ? 'delivered' : 'failed')
        ->and(json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->not->toContain(T3E_MARK);
})->with([204, 400, 403]);

it('answers another user exactly as for an unknown id, even for a disabled webhook, and leaves the owner\'s rows and messages alone', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $disabled = AlertRows::endpoint($a, 'standard', disabled: true);
    $live = AlertRows::endpoint($a);
    $messageId = DeliveryRows::delivery($live);

    $foreignDisabled = t3eAs($this, $b)->postJson("/v1/webhooks/{$disabled}/test");
    $unknown = t3eAs($this, $b)->postJson('/v1/webhooks/999999999999999999/test');
    $zero = t3eAs($this, $b)->postJson('/v1/webhooks/0/test');
    $foreignDelete = t3eAs($this, $b)->deleteJson("/v1/webhooks/{$live}");
    $paddedDelete = t3eAs($this, $b)->deleteJson('/v1/webhooks/'.str_pad((string) $live, 18, '0', STR_PAD_LEFT));
    $bList = t3eAs($this, $b)->getJson('/v1/webhooks');

    expect([$foreignDisabled->status(), $unknown->status(), $zero->status()])->toBe([404, 404, 404])
        ->and($foreignDisabled->json('type'))->toBe($unknown->json('type'))
        ->and($foreignDisabled->json('detail'))->toBe($unknown->json('detail'))
        ->and([$foreignDelete->status(), $paddedDelete->status()])->toBe([204, 204])
        ->and(DB::table('webhook_endpoints')->where('user_id', $a->id)->count())->toBe(2)
        ->and(DB::table('deliveries')->where('id', $messageId)->exists())->toBeTrue()
        ->and($bList->json('data'))->toBe([])
        ->and($bList->json('meta.count'))->toBe(0);
});

it('counts test messages per user across all of their webhooks', function (): void {
    $user = Accounts::user();
    $first = AlertRows::endpoint($user);
    $second = AlertRows::endpoint($user, 'discord');
    $statuses = [];
    foreach ([$first, $second, $first, $second, $first] as $id) {
        $statuses[] = t3eAs($this, $user)->postJson("/v1/webhooks/{$id}/test")->status();
    }

    $sixth = t3eAs($this, $user)->postJson("/v1/webhooks/{$second}/test");

    expect($statuses)->toBe([202, 202, 202, 202, 202])
        ->and($sixth->status())->toBe(429)
        ->and($sixth->headers->has('Retry-After'))->toBeTrue()
        ->and(DB::table('deliveries')->where('user_id', $user->id)->count())->toBe(5);
});

it('documents email-not-verified in the committed contract on exactly the four gated operations', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $gated = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $status => $response) {
                if (str_contains((string) ($response['description'] ?? ''), 'email-not-verified')) {
                    $gated[] = strtoupper((string) $method).' '.$status.' '.$operation['operationId'];
                }
            }
        }
    }
    sort($gated);

    expect($gated)->toBe(['POST 403 createWebhook', 'POST 403 testWebhook', 'PUT 403 putAlert', 'PUT 403 putNotifications'])
        ->and(preg_match('~whsec_[A-Za-z0-9+/\\\\]{43}=~', (string) json_encode($spec, JSON_UNESCAPED_SLASHES)))->toBe(0);
});

it('treats a blank Discord switch value as unset, and sends exactly once even when Discord answers 429', function (): void {
    config(['alerts.discord.test_webhook_url' => "  \t "]);
    $code = Artisan::call('pokeprices:alerts:discord-test');
    $blank = trim(Artisan::output());
    $afterBlank = Http::recorded()->count();

    config(['alerts.discord.test_webhook_url' => T3E_DISCORD]);
    Http::fake(['https://discord.com/*' => Http::response(json_encode(['message' => 'rate limited '.T3E_DISCORD]), 429, ['Retry-After' => '2'])]);
    $limitedCode = Artisan::call('pokeprices:alerts:discord-test');
    $limited = trim(Artisan::output());

    $sent = Http::recorded(fn (ClientRequest $r): bool => str_starts_with($r->url(), 'https://discord.com/'))->count();
    expect($code)->toBe(1)
        ->and($blank)->toBe('DISCORD_TEST_WEBHOOK_URL is not set; nothing was sent.')
        ->and($afterBlank)->toBe(0)
        ->and($limitedCode)->toBe(1)
        ->and(json_decode($limited, true))->toBe(['status' => 429, 'delivered' => false])
        ->and($limited)->not->toContain(T3E_MARK)
        ->and($sent)->toBe(1)
        ->and(json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->not->toContain(T3E_MARK)
        ->and(DB::table('deliveries')->count())->toBe(0)
        ->and(DB::table('dispatch_days')->count())->toBe(0);
});

it('cuts Discord content to at most 2000 characters on a line boundary with an exact, maximal "…and <k> more" line', function (): void {
    mt_srand(20261015);
    $alphabet = ['a', 'é', '▲', '→', '·', ' '];
    for ($round = 0; $round < 300; $round++) {
        $lines = [];
        $count = mt_rand(2, 80);
        for ($i = 0; $i < $count; $i++) {
            $line = '';
            for ($j = 0, $n = mt_rand(0, 120); $j < $n; $j++) {
                $line .= $alphabet[mt_rand(0, 5)];
            }
            $lines[] = $line;
        }
        $text = implode("\n", $lines);
        $out = DiscordMessage::content($text);
        if (mb_strlen($text) <= 2000) {
            expect($out)->toBe($text);

            continue;
        }
        $outLines = explode("\n", $out);
        $last = array_pop($outLines);
        $kept = count($outLines);
        expect(mb_strlen($out))->toBeLessThanOrEqual(2000, "round {$round}");
        if ($kept === 0) {
            continue; // the first line alone is too long: it is shortened, not dropped
        }
        expect($outLines)->toBe(array_slice($lines, 0, $kept), "prefix, round {$round}")
            ->and($last)->toBe('…and '.($count - $kept).' more', "k, round {$round}");
        if ($kept < $count) {
            $more = implode("\n", array_slice($lines, 0, $kept + 1))."\n…and ".($count - $kept - 1).' more';
            expect(mb_strlen($more))->toBeGreaterThan(2000, "maximal, round {$round}");
        }
    }
});
