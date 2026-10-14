<?php

declare(strict_types=1);

use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\TestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T03 criteria 6 and 9 (D4, D6, D7; review A08, A10.6): a discord delivery sends exactly the stored
// envelope to the stored Discord URL through the address guard, with no webhook-* headers; 204 delivers, 429 waits
// its Retry-After (capped), 404 and 401 turn the Discord endpoint off; nothing thrown by a send leaks the URL.

const T3D_URL = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0000';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Http::preventStrayRequests();
    $this->resolver = FakeHostResolver::install(['discord.com' => ['198.51.100.7'], 'hooks.example.com' => ['203.0.113.10']]);
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->user = AlertRows::user('discord@example.com');
});

/** A Discord endpoint of the test user at $url, saved through the model; returns it. */
function t3dEndpoint(object $test, string $url = T3D_URL): WebhookEndpoint
{
    $endpoint = new WebhookEndpoint;
    $endpoint->forceFill([
        'user_id' => $test->user->id, 'kind' => 'discord', 'url' => $url, 'url_hint' => 'https://discord.com/…', 'secret' => null,
    ])->save();

    return $endpoint;
}

/** @return list<ClientRequest> */
function t3dRequests(): array
{
    return Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
}

it('sends exactly the stored Discord envelope to the stored URL, pinned, with no webhook headers, and 204 delivers it', function (): void {
    $endpoint = t3dEndpoint($this);
    $delivery = TestMessage::queue($endpoint);
    $seen = null;
    Http::fake(function (ClientRequest $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('', 204);
    });

    [$code, $summary] = DeliveryRows::sweep();

    $requests = t3dRequests();
    $headers = array_map('strtolower', array_keys($requests[0]->headers()));
    $row = DeliveryRows::row($delivery->id);
    expect($code)->toBe(0)
        ->and($summary['delivered'])->toBe(1)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->url())->toBe(T3D_URL)
        ->and($requests[0]->method())->toBe('POST')
        ->and($requests[0]->body())->toBe('{"content":"PokéPrices test message. Your alerts and daily digest arrive here when you choose Discord.","username":"PokéPrices","allowed_mentions":{"parse":[]}}')
        ->and($requests[0]->header('Content-Type'))->toBe(['application/json'])
        ->and(array_values(array_filter($headers, fn (string $h): bool => str_starts_with($h, 'webhook-'))))->toBe([])
        ->and($seen['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['discord.com:443:198.51.100.7'])
        ->and($seen['allow_redirects'] ?? null)->toBeFalse()
        ->and([$row->status, $row->last_status, $row->attempts])->toBe(['delivered', 204, 1]);
});

it('waits a Discord 429 out for its Retry-After, never longer than the cap', function (string $retryAfter, int $expected): void {
    $delivery = TestMessage::queue(t3dEndpoint($this));
    Http::fake(['*' => Http::response('{"retry_after": 1}', 429, ['Retry-After' => $retryAfter])]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($delivery->id);
    expect([$row->status, $row->last_status, $row->last_error, $row->attempts])->toBe(['pending', 429, 'http_status', 1])
        ->and((int) round(DeliveryRows::dueIn($delivery->id)))->toBe($expected);
})->with([
    'ninety seconds' => ['90', 90],
    'above the cap' => ['99999', 3600],
]);

it('turns a Discord endpoint off on its 404 or 401, so the list shows it disabled and its other rows fail no_channel', function (int $status): void {
    $endpoint = t3dEndpoint($this);
    $first = TestMessage::queue($endpoint);
    Http::fake(['*' => Http::response('{"message": "Unknown Webhook"}', $status)]);

    DeliveryRows::sweep();
    $second = TestMessage::queue($endpoint->refresh());
    DeliveryRows::sweep();

    $row = DeliveryRows::row($first->id);
    $other = DeliveryRows::row($second->id);
    $list = AccountRows::signIn($this, $this->user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks');
    expect([$row->status, $row->last_status, $row->last_error])->toBe(['failed', $status, 'http_status'])
        ->and($endpoint->refresh()->disabled_at)->not->toBeNull()
        ->and([$other->status, $other->last_error, $other->attempts])->toBe(['failed', 'no_channel', 0])
        ->and(t3dRequests())->toHaveCount(1)
        ->and($list->json('data.0.disabled'))->toBeTrue();
})->with([404, 401]);

it('does not turn a signed webhook off on a 404 or 401 (only Discord answers so for a deleted webhook)', function (int $status): void {
    DeliveryRows::resolver();
    $endpoint = DeliveryRows::endpoint($this->user);
    DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', $status)]);

    DeliveryRows::sweep();

    expect(DB::table('webhook_endpoints')->where('id', $endpoint)->value('disabled_at'))->toBeNull();
})->with([404, 401]);

it('refuses at send time a stored Discord URL that is not a Discord webhook, without a request', function (): void {
    $endpoint = t3dEndpoint($this, 'https://hooks.example.com/api/webhooks/1/abc');
    $delivery = TestMessage::queue($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($delivery->id);
    expect([$row->status, $row->last_error])->toBe(['failed', 'blocked_address'])
        ->and(t3dRequests())->toBe([]);
});

it('refuses at send time a Discord host that now resolves to a blocked address, without a request', function (): void {
    FakeHostResolver::install(['discord.com' => ['::1']]);
    $delivery = TestMessage::queue(t3dEndpoint($this));
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    expect([DeliveryRows::row($delivery->id)->status, DeliveryRows::row($delivery->id)->last_error])->toBe(['failed', 'blocked_address'])
        ->and(t3dRequests())->toBe([]);
});

it('fails a discord row whose endpoint is a signed webhook, and a webhook row whose endpoint is Discord, with no_channel', function (): void {
    DeliveryRows::resolver();
    $standard = DeliveryRows::endpoint($this->user);
    $discord = t3dEndpoint($this);
    $a = DeliveryRows::delivery($standard, ['channel' => 'discord', 'body' => DiscordMessage::envelope('x')]);
    $b = DeliveryRows::delivery($discord->id, ['channel' => 'webhook']);
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    expect([DeliveryRows::row($a)->status, DeliveryRows::row($a)->last_error])->toBe(['failed', 'no_channel'])
        ->and([DeliveryRows::row($b)->status, DeliveryRows::row($b)->last_error])->toBe(['failed', 'no_channel'])
        ->and(t3dRequests())->toBe([]);
});

it('keeps the stored Discord URL out of logs, failed jobs and the exception handler whatever a send throws', function (Closure $throw, string $error): void {
    Exceptions::fake();
    $delivery = TestMessage::queue(t3dEndpoint($this));
    Http::fake(['*' => $throw]);

    DeliveryRows::job();

    $row = DeliveryRows::row($delivery->id);
    $all = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES).json_encode(DB::table('failed_jobs')->get());
    expect([$row->status, $row->last_error, $row->attempts])->toBe(['pending', $error, 1])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($all)->not->toContain('SENTINEL')
        ->and($all)->not->toContain('api/webhooks');
    Exceptions::assertNothingReported();
})->with([
    'runtime exception carrying the URL' => [fn () => throw new RuntimeException('failed for '.T3D_URL), 'internal'],
    'connection exception ending with the URL' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.T3D_URL), 'connection'],
]);
