<?php

declare(strict_types=1);

use App\Domain\Accounts\AlertEligibility;
use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\UrlGuard;
use App\Domain\Alerts\WebhookEndpoints;
use App\Http\Middleware\RequireAlertEligibility;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T03 criteria 1 to 5 and 9 (D4, D6, D9; P4 D15 attached): registering signed and Discord webhooks,
// every 422 refusal, the per-user limits under the P4 lock, eligibility, the demo, ownership of {id}, test
// messages, the webhook-test limiter, and no URL, secret or token in any log line. Every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3). DNS is faked; no request leaves the test.

const T3_URL = 'https://hooks.example.com/pokeprices';
const T3_DISCORD = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123';
const T3_PRIVATE = 'PokéPrices does not send to private, local or reserved network addresses.';
const T3_DISCORD_SHAPE = 'A Discord webhook URL looks like https://discord.com/api/webhooks/<id>/<token>.';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Http::preventStrayRequests();
    FakeHostResolver::install([
        'hooks.example.com' => ['203.0.113.10'],
        'discord.com' => ['198.51.100.7'],
        'discordapp.com' => ['198.51.100.8'],
    ]);
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function t3As(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

/** @param array<string, mixed> $body */
function t3Store(object $test, User $user, array $body): TestResponse
{
    return t3As($test, $user)->postJson('/v1/webhooks', $body);
}

function t3Index(object $test, User $user): TestResponse
{
    return t3As($test, $user)->getJson('/v1/webhooks');
}

/** The first, an inside and the last address of an IPv4 block of UrlGuard::BLOCKS, computed at run time. */
function t3Inside(string $block): string
{
    [$network, $prefix] = explode('/', $block);
    $start = (int) ip2long(implode('.', array_pad(explode('.', $network), 4, '0')));

    return (string) long2ip($start + intdiv(2 ** (32 - (int) $prefix), 2));
}

it('registers a signed webhook: 201 with the secret once, then lists it without the secret or the URL', function (): void {
    $user = Accounts::user();

    $created = t3Store($this, $user, ['kind' => 'standard', 'url' => T3_URL, 'description' => 'Home bot']);

    $created->assertCreated();
    $id = (int) $created->json('data.id');
    $secret = (string) $created->json('data.secret');
    $endpoint = WebhookEndpoint::query()->findOrFail($id);
    $raw = DB::table('webhook_endpoints')->where('id', $id)->first();
    expect($created->json())->toBe(['data' => [
        'id' => $id, 'kind' => 'standard', 'url_hint' => 'https://hooks.example.com/…', 'description' => 'Home bot',
        'created_at' => '2026-10-15T12:00:00Z', 'disabled' => false, 'last_delivery' => null, 'secret' => $secret,
    ]])
        ->and($secret)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/')
        ->and($endpoint->secret)->toBe($secret)
        ->and($endpoint->url)->toBe(T3_URL)
        ->and($endpoint->user_id)->toBe($user->id)
        ->and($raw->secret)->not->toBe($secret)
        ->and($raw->url)->not->toContain('hooks.example.com');

    $list = t3Index($this, $user);

    $list->assertOk();
    expect($list->json())->toBe([
        'data' => [[
            'id' => $id, 'kind' => 'standard', 'url_hint' => 'https://hooks.example.com/…', 'description' => 'Home bot',
            'created_at' => '2026-10-15T12:00:00Z', 'disabled' => false, 'last_delivery' => null,
        ]],
        'meta' => ['count' => 1, 'limit' => 5],
    ])
        ->and($list->getContent())->not->toContain('secret')
        ->and($list->getContent())->not->toContain('whsec_')
        ->and($list->getContent())->not->toContain('/pokeprices');
});

it('registers a Discord webhook with no secret and only its host shown back', function (): void {
    $user = Accounts::user();

    $created = t3Store($this, $user, ['kind' => 'discord', 'url' => T3_DISCORD]);

    $created->assertCreated();
    $id = (int) $created->json('data.id');
    expect($created->json())->toBe(['data' => [
        'id' => $id, 'kind' => 'discord', 'url_hint' => 'https://discord.com/…', 'description' => null,
        'created_at' => '2026-10-15T12:00:00Z', 'disabled' => false, 'last_delivery' => null, 'secret' => null,
    ]])
        ->and(WebhookEndpoint::query()->findOrFail($id)->url)->toBe(T3_DISCORD)
        ->and(DB::table('webhook_endpoints')->where('id', $id)->value('secret'))->toBeNull()
        ->and(t3Index($this, $user)->getContent())->not->toContain('abcDEF_ghi-123');
});

it('keeps a non-default port in the hint and drops the path and query', function (): void {
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10']]);

    $created = t3Store($this, Accounts::user(), ['kind' => 'standard', 'url' => 'https://hooks.example.com:8443/a/b?token=x']);

    expect($created->json('data.url_hint'))->toBe('https://hooks.example.com:8443/…');
});

it('lists endpoints ordered by id with the newest delivery of each', function (): void {
    $user = Accounts::user();
    $first = AlertRows::endpoint($user);
    $second = AlertRows::endpoint($user, 'discord', disabled: true);
    DB::table('deliveries')->insert([
        ['message_id' => 'msg_a', 'user_id' => $user->id, 'kind' => 'webhook.test', 'channel' => 'webhook', 'endpoint_id' => $first, 'body' => '{}', 'status' => 'failed', 'next_attempt_at' => '2026-10-13 10:00:00+00', 'created_at' => '2026-10-13 10:00:00+00', 'delivered_at' => null],
        ['message_id' => 'msg_b', 'user_id' => $user->id, 'kind' => 'webhook.test', 'channel' => 'webhook', 'endpoint_id' => $first, 'body' => '{}', 'status' => 'delivered', 'next_attempt_at' => '2026-10-14 10:00:00+00', 'created_at' => '2026-10-14 10:00:00+00', 'delivered_at' => '2026-10-14 10:00:07+00'],
    ]);

    $list = t3Index($this, $user);

    expect(array_column($list->json('data'), 'id'))->toBe([$first, $second])
        ->and($list->json('data.0.last_delivery'))->toBe(['status' => 'delivered', 'at' => '2026-10-14T10:00:07Z'])
        ->and($list->json('data.1.last_delivery'))->toBeNull()
        ->and($list->json('data.1.disabled'))->toBeTrue()
        ->and($list->json('meta'))->toBe(['count' => 2, 'limit' => 5]);
});

it('refuses each bad registration with the documented 422 message', function (array $body, string $field, ?string $message): void {
    $response = t3Store($this, Accounts::user(), $body);

    $response->assertStatus(422);
    expect($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($response->json('errors')))->toBe([$field])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
    if ($message !== null) {
        expect($response->json("errors.{$field}"))->toBe([$message]);
    }
})->with([
    'plain http' => [['kind' => 'standard', 'url' => 'http://hooks.example.com/x'], 'url', 'Use an https URL.'],
    'unresolvable host' => [['kind' => 'standard', 'url' => 'https://nowhere.example.com/x'], 'url', 'This host name does not resolve.'],
    'Discord on another host' => [['kind' => 'discord', 'url' => 'https://hooks.example.com/api/webhooks/123/abc'], 'url', T3_DISCORD_SHAPE],
    'Discord with a query' => [['kind' => 'discord', 'url' => T3_DISCORD.'?wait=true'], 'url', T3_DISCORD_SHAPE],
    'Discord with a wrong path' => [['kind' => 'discord', 'url' => 'https://discord.com/api/webhook/123/abc'], 'url', T3_DISCORD_SHAPE],
    'Discord over http' => [['kind' => 'discord', 'url' => 'http://discord.com/api/webhooks/123/abc'], 'url', T3_DISCORD_SHAPE],
    'kind slack' => [['kind' => 'slack', 'url' => T3_URL], 'kind', 'Choose a signed webhook or Discord.'],
    'no kind' => [['url' => T3_URL], 'kind', 'Choose a signed webhook or Discord.'],
    'a 101-character description' => [['kind' => 'standard', 'url' => T3_URL, 'description' => str_repeat('a', 101)], 'description', null],
    'too long' => [['kind' => 'standard', 'url' => 'https://hooks.example.com/'.str_repeat('a', 2030)], 'url', 'The URL is too long.'],
    'no url' => [['kind' => 'standard'], 'url', null],
    'url as a number' => [['kind' => 'standard', 'url' => 12345], 'url', null],
    'url as an array' => [['kind' => 'standard', 'url' => [T3_URL]], 'url', null],
    'kind as an array' => [['kind' => ['standard'], 'url' => T3_URL], 'kind', 'Choose a signed webhook or Discord.'],
    'description as a number' => [['kind' => 'standard', 'url' => T3_URL, 'description' => 5], 'description', null],
    'description as an object' => [['kind' => 'standard', 'url' => T3_URL, 'description' => ['a' => 'b']], 'description', null],
]);

it('accepts a 100-character description', function (): void {
    t3Store($this, Accounts::user(), ['kind' => 'standard', 'url' => T3_URL, 'description' => str_repeat('a', 100)])->assertCreated();
});

it('refuses a host resolving into any blocked IPv4 block, and an IPv6 loopback answer, with the private-address message', function (): void {
    $user = Accounts::user();
    $blocks = array_values(array_filter(UrlGuard::BLOCKS, fn (string $b): bool => ! str_contains($b, ':')));
    $answers = [];
    foreach ($blocks as $i => $block) {
        $answers["blocked{$i}.example.com"] = [t3Inside($block)];
    }
    $answers['six.example.com'] = ['::1'];
    $answers['mixed.example.com'] = ['203.0.113.10', t3Inside('10/8')];
    $answers['discord.com'] = [t3Inside('127/8')];
    FakeHostResolver::install($answers);

    $messages = [];
    foreach (array_keys($answers) as $host) {
        $url = $host === 'discord.com' ? T3_DISCORD : "https://{$host}/hook";
        $kind = $host === 'discord.com' ? 'discord' : 'standard';
        $messages[$host] = t3Store($this, $user, ['kind' => $kind, 'url' => $url])->json('errors.url');
    }

    expect($messages)->toBe(array_fill_keys(array_keys($answers), [T3_PRIVATE]))
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});

it('refuses an IP literal in a blocked block without resolving it', function (): void {
    $resolver = FakeHostResolver::install([]);

    $response = t3Store($this, Accounts::user(), ['kind' => 'standard', 'url' => 'https://['.'::1'.']/hook']);

    expect($response->json('errors.url'))->toBe([T3_PRIVATE])
        ->and($resolver->asked)->toBe([]);
});

it('allows at most 5 signed webhooks and one Discord webhook per user, disabled ones included', function (): void {
    $user = Accounts::user();
    for ($i = 0; $i < 4; $i++) {
        AlertRows::endpoint($user);
    }
    AlertRows::endpoint($user, 'standard', disabled: true);
    AlertRows::endpoint($user, 'discord', disabled: true);

    $sixth = t3Store($this, $user, ['kind' => 'standard', 'url' => T3_URL]);
    $second = t3Store($this, $user, ['kind' => 'discord', 'url' => T3_DISCORD]);
    $other = t3Store($this, Accounts::user(['email' => 'other@example.com']), ['kind' => 'standard', 'url' => T3_URL]);

    expect($sixth->status())->toBe(422)
        ->and($sixth->json('errors'))->toBe(['url' => ['You can add at most 5 signed webhooks.']])
        ->and($second->status())->toBe(422)
        ->and($second->json('errors'))->toBe(['url' => ['You can add one Discord webhook.']])
        ->and($other->status())->toBe(201)
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(6);
});

it('holds the per-user lock across the count, so a concurrent registration at the limit cannot make a sixth (P4 lock)', function (): void {
    $user = Accounts::user();
    for ($i = 0; $i < 4; $i++) {
        AlertRows::endpoint($user);
    }
    config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_rival');
    $rival->statement("SET lock_timeout = '250ms'");
    $outcome = null;
    DB::listen(function (QueryExecuted $query) use (&$outcome, $rival, $user): void {
        if ($outcome !== null || $query->connectionName === 'pgsql_rival' || ! preg_match('/count\(\*\)/i', $query->sql) || ! str_contains($query->sql, 'webhook_endpoints')) {
            return;
        }
        $outcome = 'counting';
        try {
            $result = WebhookEndpoints::create($rival, $user->id, 'standard', T3_URL, 'https://hooks.example.com/…', null, AlertRows::secret(), CarbonImmutable::now());
            $outcome = 'rival finished: '.var_export($result, true);
        } catch (QueryException $e) {
            $outcome = (string) $e->getCode();
        }
    });

    $created = t3Store($this, $user, ['kind' => 'standard', 'url' => T3_URL]);
    DB::purge('pgsql_rival');

    // 55P03 lock_not_available: the rival waited on the per-user lock the request held while it counted.
    expect($outcome)->toBe('55P03')
        ->and($created->status())->toBe(201)
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(5);
});

it('refuses an unverified person 403 email-not-verified on register and test, while list and delete still work', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);
    $id = AlertRows::endpoint($user);

    $store = t3Store($this, $user, ['kind' => 'standard', 'url' => T3_URL]);
    $test = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");
    $list = t3Index($this, $user);

    expect([$store->status(), $store->json('type'), $store->json('title')])->toBe([403, ProblemDetails::BASE.'email-not-verified', 'Verify Your Email First'])
        ->and([$test->status(), $test->json('type')])->toBe([403, ProblemDetails::BASE.'email-not-verified'])
        ->and($list->status())->toBe(200)
        ->and(array_column($list->json('data'), 'id'))->toBe([$id])
        ->and(DB::table('deliveries')->count())->toBe(0);

    t3As($this, $user)->deleteJson("/v1/webhooks/{$id}")->assertNoContent();
    expect(DB::table('webhook_endpoints')->count())->toBe(0);
});

it('refuses the demo 403 demo-read-only on every webhook write, its own numeric id included, and lists nothing', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $other = Accounts::user(['email' => 'other@example.com']);
    $otherId = AlertRows::endpoint($other);

    $answers = [
        'store' => t3Store($this, $demo, ['kind' => 'standard', 'url' => T3_URL]),
        'delete own id' => t3As($this, $demo)->deleteJson("/v1/webhooks/{$demo->id}"),
        'test own id' => t3As($this, $demo)->postJson("/v1/webhooks/{$demo->id}/test"),
        'delete another' => t3As($this, $demo)->deleteJson("/v1/webhooks/{$otherId}"),
    ];
    $list = t3Index($this, $demo);

    expect(array_map(fn (TestResponse $r): array => [$r->status(), $r->json('type')], $answers))
        ->toBe(array_fill_keys(array_keys($answers), [403, ProblemDetails::BASE.'demo-read-only']))
        ->and($list->json())->toBe(['data' => [], 'meta' => ['count' => 0, 'limit' => 5]])
        ->and(DB::table('webhook_endpoints')->pluck('id')->all())->toBe([$otherId])
        ->and(DB::table('deliveries')->count())->toBe(0);
});

it('declares {id} with a numeric constraint on both id routes, so the demo enumeration reaches DemoReadOnly', function (): void {
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'v1/webhooks')) {
            $routes[implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri()] = [$route->getName(), $route->wheres];
        }
    }
    ksort($routes);

    expect($routes)->toBe([
        'DELETE v1/webhooks/{id}' => ['webhooks.destroy', ['id' => '[0-9]{1,18}']],
        'GET v1/webhooks' => ['webhooks.index', []],
        'POST v1/webhooks' => ['webhooks.store', []],
        'POST v1/webhooks/{id}/test' => ['webhooks.test', ['id' => '[0-9]{1,18}']],
    ]);
});

it('gates exactly the alert-creating webhook routes behind the create-alerts Gate', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array(RequireAlertEligibility::class, Route::gatherRouteMiddleware($route), true))
        ->map(fn ($route): ?string => $route->getName())->sort()->values()->all();

    expect($names)->toBe(['alerts.put', 'notifications.put', 'webhooks.store', 'webhooks.test'])
        ->and(AlertEligibility::GATE)->toBe('create-alerts');
});

it('never reveals another user\'s webhook: delete is a no-op 204, test is 404 webhook-not-found', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $aId = AlertRows::endpoint($a);

    $delete = t3As($this, $b)->deleteJson("/v1/webhooks/{$aId}");
    $test = t3As($this, $b)->postJson("/v1/webhooks/{$aId}/test");

    expect($delete->status())->toBe(204)
        ->and(DB::table('webhook_endpoints')->where('id', $aId)->exists())->toBeTrue()
        ->and([$test->status(), $test->json('type'), $test->json('title')])->toBe([404, ProblemDetails::BASE.'webhook-not-found', 'Webhook Not Found'])
        ->and(DB::table('deliveries')->count())->toBe(0);
});

it('answers 404 webhook-not-found to a test of a deleted endpoint and an unknown id', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user);
    t3As($this, $user)->deleteJson("/v1/webhooks/{$id}")->assertNoContent();

    $deleted = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");
    $unknown = t3As($this, $user)->postJson('/v1/webhooks/999999999999999999/test');
    $again = t3As($this, $user)->deleteJson("/v1/webhooks/{$id}");

    expect([$deleted->status(), $deleted->json('type')])->toBe([404, ProblemDetails::BASE.'webhook-not-found'])
        ->and([$unknown->status(), $unknown->json('type')])->toBe([404, ProblemDetails::BASE.'webhook-not-found'])
        ->and($again->status())->toBe(204);
});

it('has no route for a non-numeric or 19-digit id: 404 not-found', function (string $id): void {
    $user = Accounts::user();

    $test = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");

    expect([$test->status(), $test->json('type')])->toBe([404, ProblemDetails::BASE.'not-found'])
        ->and(fn () => Route::getRoutes()->match(Request::create("/v1/webhooks/{$id}", 'DELETE')))->toThrow(NotFoundHttpException::class);
})->with(['non-numeric' => 'abc', '19 digits' => '1234567890123456789', 'negative' => '-1']);

it('deletes the caller\'s own endpoint and its pending deliveries', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user);
    t3As($this, $user)->postJson("/v1/webhooks/{$id}/test")->assertStatus(202);

    t3As($this, $user)->deleteJson("/v1/webhooks/{$id}")->assertNoContent();

    expect(DB::table('webhook_endpoints')->count())->toBe(0)
        ->and(DB::table('deliveries')->count())->toBe(0);
});

it('queues a webhook.test message for a signed webhook: 202 with its message id, due now', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user);

    $response = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");

    $response->assertStatus(202);
    $delivery = Delivery::query()->where('message_id', $response->json('data.message_id'))->firstOrFail();
    expect($response->json())->toBe(['data' => ['message_id' => $delivery->message_id]])
        ->and($delivery->message_id)->toMatch('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and([$delivery->kind, $delivery->channel, $delivery->endpoint_id, $delivery->user_id, $delivery->status, $delivery->day])
        ->toBe(['webhook.test', 'webhook', $id, $user->id, 'pending', null])
        ->and($delivery->body)->toBe('{"type":"webhook.test","timestamp":"2026-10-15T12:00:00Z","data":{"message":"PokéPrices test message. It carries no price."}}')
        ->and($delivery->next_attempt_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and(t3Index($this, $user)->json('data.0.last_delivery'))->toBe(['status' => 'pending', 'at' => '2026-10-15T12:00:00Z']);
});

it('queues the Discord test envelope for a Discord webhook', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user, 'discord');

    $response = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");

    $response->assertStatus(202);
    $delivery = Delivery::query()->where('message_id', $response->json('data.message_id'))->firstOrFail();
    expect([$delivery->kind, $delivery->channel, $delivery->endpoint_id])->toBe(['webhook.test', 'discord', $id])
        ->and($delivery->body)->toBe('{"content":"PokéPrices test message. Your alerts and daily digest arrive here when you choose Discord.","username":"PokéPrices","allowed_mentions":{"parse":[]}}');
});

it('answers 409 webhook-disabled to a test of a turned-off endpoint', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user, 'standard', disabled: true);

    $response = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");

    expect([$response->status(), $response->json('type'), $response->json('title')])->toBe([409, ProblemDetails::BASE.'webhook-disabled', 'Webhook Turned Off'])
        ->and(DB::table('deliveries')->count())->toBe(0);
});

it('limits test messages to 5 a minute per user: the sixth is 429 with Retry-After', function (): void {
    $user = Accounts::user();
    $id = AlertRows::endpoint($user);
    $other = Accounts::user(['email' => 'other@example.com']);
    $otherId = AlertRows::endpoint($other);

    $statuses = [];
    for ($i = 0; $i < 5; $i++) {
        $statuses[] = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test")->status();
    }
    $sixth = t3As($this, $user)->postJson("/v1/webhooks/{$id}/test");
    $someoneElse = t3As($this, $other)->postJson("/v1/webhooks/{$otherId}/test");

    expect($statuses)->toBe([202, 202, 202, 202, 202])
        ->and([$sixth->status(), $sixth->json('type')])->toBe([429, ProblemDetails::BASE.'too-many-requests'])
        ->and($sixth->headers->has('Retry-After'))->toBeTrue()
        ->and($someoneElse->status())->toBe(202)
        ->and(DB::table('deliveries')->where('user_id', $user->id)->count())->toBe(5);
});

it('writes no URL, path, secret or Discord token to any log line of these flows', function (): void {
    $user = Accounts::user();
    $standard = t3Store($this, $user, ['kind' => 'standard', 'url' => T3_URL, 'description' => 'Home bot']);
    $discord = t3Store($this, $user, ['kind' => 'discord', 'url' => T3_DISCORD]);
    t3Store($this, $user, ['kind' => 'standard', 'url' => 'https://nowhere.example.com/secret-path-0000']);
    t3As($this, $user)->postJson('/v1/webhooks/'.$standard->json('data.id').'/test')->assertStatus(202);
    t3As($this, $user)->postJson('/v1/webhooks/'.$discord->json('data.id').'/test')->assertStatus(202);
    t3Index($this, $user)->assertOk();
    t3As($this, $user)->deleteJson('/v1/webhooks/'.$standard->json('data.id'))->assertNoContent();

    $logs = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($logs)->not->toContain('/pokeprices')
        ->and($logs)->not->toContain('secret-path-0000')
        ->and($logs)->not->toContain('whsec_')
        ->and($logs)->not->toContain((string) $standard->json('data.secret'))
        ->and($logs)->not->toContain('abcDEF_ghi-123')
        ->and($logs)->not->toContain('api/webhooks');
});
