<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\DemoSamples;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use App\Domain\Alerts\WebhookSecret;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\DeliveryRows;

// The read-only demo's seeded alert, sample delivered message and sample signed payload: what the seeder writes,
// what GET /v1/demo/samples answers, that every write stays refused, that no other account and the demo ever read
// each other's rows, and that nothing is ever queued or sent for the demo. Every /v1 answer is also validated
// against openapi/api.json by Tests\TestCase.

const T08_ALERT_KEY = 'ex5-17:reverse:-:-';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** Every seeded variant with a close, and one movement event of the alert card (the dev data's shape). */
function t08World(): int
{
    foreach (DemoAccount::watchlist() as $key) {
        if ($key !== 'base1-4:holo:1st:-') {
            AccountRows::price($key, '10.00', '2026-10-12');
        } else {
            AccountRows::variant($key);
        }
    }

    return AlertRows::event(T08_ALERT_KEY, '2026-10-12', [
        'close' => '26.34', 'previous_close' => '5.71', 'change' => '20.63', 'change_pct' => '361.30',
        'as_of' => '2026-10-12 20:05:44+00', 'previous_as_of' => '2026-10-11 20:05:45+00',
    ]);
}

function t08Seed(): string
{
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    return Artisan::output();
}

function t08Demo(): User
{
    return User::query()->where('is_demo', true)->firstOrFail();
}

/** The Standard Webhooks check as docs/ALERTS.md writes it, independent of the signer. */
function t08Signature(string $secret, string $id, string $timestamp, string $body): string
{
    return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) base64_decode(substr($secret, 6)), true));
}

/** @return array<string, mixed> every row the seeder writes for the demo */
function t08Rows(User $demo): array
{
    $rows = fn (string $table, string $order): array => DB::table($table)->where('user_id', $demo->id)->orderBy($order)->get()
        ->map(fn (object $r): array => (array) $r)->all();

    return [
        'watchlist' => DB::table('watchlist_items')->where('user_id', $demo->id)->orderBy('variant_key')->get(['variant_key', 'alert_pct'])->map(fn (object $r): array => (array) $r)->all(),
        'portfolio' => DB::table('portfolio_holdings')->where('user_id', $demo->id)->orderBy('variant_key')->get(['variant_key', 'quantity'])->map(fn (object $r): array => (array) $r)->all(),
        'endpoints' => $rows('webhook_endpoints', 'id'),
        'deliveries' => $rows('deliveries', 'id'),
        'settings' => $rows('notification_settings', 'user_id'),
    ];
}

/** A second, ordinary account with an alert, a signed webhook and two delivered messages of its own. */
function t08Person(): User
{
    $person = Accounts::user(['email' => 'person@example.com']);
    AccountRows::watch($person, 'base1-2:holo:unlimited:-');
    DB::table('watchlist_items')->where('user_id', $person->id)->update(['alert_pct' => 20]);
    $endpoint = DeliveryRows::endpoint($person, 'https://person-hooks.example.com/hook-0000');
    DB::table('webhook_endpoints')->where('id', $endpoint)->update(['url_hint' => 'https://person-hooks.example.com/…']);
    DeliveryRows::delivery($endpoint, ['message_id' => 'msg_PERSONWEBHOOK0000000000000', 'status' => 'delivered', 'delivered_at' => '2026-10-12 21:00:00+00', 'body' => '{"person-marker":"webhook"}']);
    DB::table('deliveries')->insert([
        'message_id' => 'msg_PERSONEMAIL00000000000000', 'user_id' => $person->id, 'kind' => 'alert.triggered', 'channel' => 'email',
        'event_id' => 999_999, 'day' => '2026-10-12', 'body' => '{"subject":"person-marker","text":"person-marker"}',
        'status' => 'delivered', 'next_attempt_at' => '2026-10-12 21:00:00+00', 'delivered_at' => '2026-10-12 21:00:00+00',
    ]);

    return $person;
}

it('holds 12 to 20 distinct cards on the demo watchlist, base1-4 1st Edition holo among them, and a portfolio drawn from it', function (): void {
    $watchlist = DemoAccount::watchlist();
    $cards = array_map(fn (string $key): string => explode(':', $key)[0], $watchlist);

    expect(count($watchlist))->toBeGreaterThanOrEqual(12)->toBeLessThanOrEqual(20)
        ->and(array_values(array_unique($cards)))->toBe($cards)
        ->and($watchlist)->toContain('base1-4:holo:1st:-')
        ->and(array_diff(array_keys(DemoAccount::portfolio()), $watchlist))->toBe([])
        ->and(count(DemoAccount::portfolio()))->toBeGreaterThanOrEqual(1);
});

it('seeds the watchlist, one alert, a sample signed webhook and two delivered samples, the same rows on a second run, sending nothing', function (): void {
    Queue::fake();
    Mail::fake();
    $event = t08World();

    t08Seed();
    $first = t08Rows(t08Demo());
    t08Seed();
    $second = t08Rows(t08Demo());

    $alerts = array_values(array_filter($first['watchlist'], fn (array $r): bool => $r['alert_pct'] !== null));
    $sorted = DemoAccount::watchlist();
    sort($sorted);
    expect($second)->toBe($first)
        ->and(array_column($first['watchlist'], 'variant_key'))->toBe($sorted)
        ->and($alerts)->toBe([['variant_key' => T08_ALERT_KEY, 'alert_pct' => DemoSamples::ALERT_PCT]])
        ->and($first['endpoints'])->toHaveCount(1)
        ->and($first['endpoints'][0]['kind'])->toBe('standard')
        ->and($first['endpoints'][0]['url_hint'])->toBe('https://example.test/…')
        ->and(array_column($first['deliveries'], 'kind'))->toBe(['alert.triggered', 'price.moved'])
        ->and(array_column($first['deliveries'], 'status'))->toBe(['delivered', 'delivered'])
        ->and(array_column($first['deliveries'], 'event_id'))->toBe([$event, $event])
        ->and(Http::recorded())->toHaveCount(0);
    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('lets the demo read its alert, the delivered message and the signed payload, which verifies with the sample secret and nothing else', function (): void {
    $event = t08World();
    t08Seed();

    $response = AccountRows::signIn($this, t08Demo())->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');

    $response->assertOk();
    $data = $response->json('data');
    $webhook = $data['webhook'];
    $headers = $webhook['headers'];
    $payload = json_decode($webhook['body'], true);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($data['sample_secret'])->toBe(DemoSamples::secret())
        ->and($data['alert'])->toBe([
            'variant_key' => T08_ALERT_KEY, 'card_id' => 'ex5-17', 'finish' => 'reverse', 'edition' => '-', 'treatment' => '-',
            'card' => ['name' => 'Chimecho', 'set_name' => 'Hidden Legends', 'number' => '17'], 'alert_pct' => DemoSamples::ALERT_PCT,
        ])
        ->and($data['message']['kind'])->toBe('alert.triggered')
        ->and($data['message']['channel'])->toBe('email')
        ->and($data['message']['status'])->toBe('delivered')
        ->and($data['message']['delivered_at'])->toBe('2026-10-12T20:05:44Z')
        ->and($data['message']['subject'])->toBe('PokéPrices alert: Chimecho moved +361.30% (2026-10-12)')
        ->and($data['message']['text'])->toContain('Market price $26.34')
        ->and($data['message']['text'])->toContain('as of 2026-10-12 from TCGCSV (daily close)')
        ->and($data['message']['text'])->toContain('Your alert threshold: '.DemoSamples::ALERT_PCT.'% (and at least $1.00).')
        ->and($webhook['kind'])->toBe('price.moved')
        ->and($webhook['url_hint'])->toBe('https://example.test/…')
        ->and($webhook['status'])->toBe('delivered')
        ->and($webhook['delivered_at'])->toBe('2026-10-12T20:05:44Z')
        ->and($payload['type'])->toBe('price.moved')
        ->and($payload['data']['event_id'])->toBe('evt_'.$event)
        ->and($payload['data']['card'])->toBe(['name' => 'Chimecho', 'set_name' => 'Hidden Legends', 'number' => '17'])
        ->and($payload['data']['close'])->toBe('26.34')
        ->and($payload['data']['change_pct'])->toBe('361.30')
        ->and(array_keys($headers))->toBe(['webhook-id', 'webhook-timestamp', 'webhook-signature'])
        ->and($headers['webhook-id'])->toBe($webhook['message_id'])
        ->and($headers['webhook-id'])->toStartWith('msg_')
        ->and($headers['webhook-timestamp'])->toBe((string) strtotime('2026-10-12T20:05:44Z'))
        ->and($headers['webhook-signature'])->toBe(t08Signature(DemoSamples::secret(), $headers['webhook-id'], $headers['webhook-timestamp'], $webhook['body']))
        ->and($headers['webhook-signature'])->not->toBe(t08Signature(AlertRows::secret(), $headers['webhook-id'], $headers['webhook-timestamp'], $webhook['body']))
        ->and($headers['webhook-signature'])->not->toBe(t08Signature(WebhookSecret::generate(), $headers['webhook-id'], $headers['webhook-timestamp'], $webhook['body']))
        ->and($headers['webhook-signature'])->not->toBe(t08Signature(DemoSamples::secret(), $headers['webhook-id'], $headers['webhook-timestamp'], $webhook['body'].' '));
});

it('publishes a fixed sample secret that is a valid signing secret and no environment value', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass(DemoSamples::class))->getFileName());
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    expect(DemoSamples::secret())->toBe('whsec_'.base64_encode(DemoSamples::KEY))
        ->and(DemoSamples::secret())->toBe(DemoSamples::secret())
        ->and(strlen(WebhookSecret::key(DemoSamples::secret())))->toBe(32)
        ->and($source)->not->toContain('env(')
        ->and($source)->not->toContain('config(');
});

it('shows the demo its alert on the watchlist and its sample webhook in the list, delivered', function (): void {
    t08World();
    t08Seed();
    $demo = t08Demo();

    $watchlist = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist')->assertOk();
    $webhooks = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks')->assertOk();

    $alerted = array_values(array_filter($watchlist->json('data'), fn (array $row): bool => $row['alert_pct'] !== null));
    expect($watchlist->json('meta.count'))->toBe(count(DemoAccount::watchlist()))
        ->and(array_column($alerted, 'variant_key'))->toBe([T08_ALERT_KEY])
        ->and($alerted[0]['alert_pct'])->toBe(DemoSamples::ALERT_PCT)
        ->and($webhooks->json('data'))->toHaveCount(1)
        ->and($webhooks->json('data.0.url_hint'))->toBe('https://example.test/…')
        ->and($webhooks->json('data.0.description'))->toBe(DemoSamples::DESCRIPTION)
        ->and($webhooks->json('data.0.last_delivery'))->toBe(['status' => 'delivered', 'at' => '2026-10-12T20:05:44Z']);
});

it('seeds no sample message on a database with no move of a demo card, and the answer says so with nulls', function (): void {
    foreach (DemoAccount::watchlist() as $key) {
        AccountRows::variant($key);
    }

    $output = t08Seed();
    $response = AccountRows::signIn($this, t08Demo())->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');

    $response->assertOk();
    expect($output)->toContain('No sample messages')
        ->and($response->json('data.message'))->toBeNull()
        ->and($response->json('data.webhook'))->toBeNull()
        ->and($response->json('data.sample_secret'))->toBe(DemoSamples::secret())
        ->and(DB::table('deliveries')->count())->toBe(0);
});

it('refuses every write route of the alerts, webhooks, settings, account and lists to the demo as problem+json, changing no row and sending nothing', function (): void {
    Queue::fake();
    Mail::fake();
    t08World();
    t08Seed();
    $demo = t08Demo();
    $before = t08Rows($demo);
    $endpoint = (int) $before['endpoints'][0]['id'];
    $key = rawurlencode(T08_ALERT_KEY);
    // Taken from api/routes/api.php and the Fortify routes the demo could reach.
    $writes = [
        ['PUT', "/v1/alerts/{$key}", ['pct' => 20]],
        ['DELETE', "/v1/alerts/{$key}", []],
        ['POST', '/v1/webhooks', ['kind' => 'standard', 'url' => 'https://example.test/other']],
        ['DELETE', "/v1/webhooks/{$endpoint}", []],
        ['POST', "/v1/webhooks/{$endpoint}/test", []],
        ['PUT', '/v1/me/notifications', ['channel' => 'email', 'digest' => true]],
        ['POST', '/v1/me/deletion', ['password' => Accounts::PASSWORD]],
        ['PUT', "/v1/watchlist/{$key}", []],
        ['DELETE', "/v1/watchlist/{$key}", []],
        ['PUT', "/v1/portfolio/{$key}", ['quantity' => 2]],
        ['DELETE', "/v1/portfolio/{$key}", []],
        ['POST', '/user/two-factor-authentication', []],
        ['DELETE', '/user/two-factor-authentication', []],
        ['POST', '/user/confirmed-two-factor-authentication', ['code' => '123456']],
        ['POST', '/user/two-factor-recovery-codes', []],
        ['POST', '/user/confirm-password', ['password' => Accounts::PASSWORD]],
        ['POST', '/email/verification-notification', []],
    ];

    $answers = [];
    foreach ($writes as [$method, $uri, $body]) {
        $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->json($method, $uri, $body);
        $answers["{$method} {$uri}"] = [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->json('type')];
    }

    expect($answers)->toBe(array_fill_keys(array_keys($answers), [403, 'application/problem+json', ProblemDetails::BASE.'demo-read-only']))
        ->and(t08Rows($demo))->toBe($before)
        ->and(Http::recorded())->toHaveCount(0);
    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
});

it('never lets another account read the demo\'s samples, nor the demo read another account\'s rows, on every touched route', function (): void {
    t08World();
    t08Seed();
    $demo = t08Demo();
    $person = t08Person();

    $personSamples = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    $personWebhooks = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks');
    $personWatchlist = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $personExport = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');
    $demoSamples = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    $demoWebhooks = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks');
    $demoWatchlist = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $demoExport = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    $demoIds = DB::table('deliveries')->where('user_id', $demo->id)->pluck('message_id')->all();
    expect($personSamples->getStatusCode())->toBe(404)
        ->and($personSamples->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($personSamples->json('type'))->toBe(ProblemDetails::BASE.'not-found')
        ->and($personWebhooks->json('data.*.url_hint'))->toBe(['https://person-hooks.example.com/…'])
        ->and($personWatchlist->json('data.*.variant_key'))->toBe(['base1-2:holo:unlimited:-'])
        ->and($demoSamples->getStatusCode())->toBe(200)
        ->and($demoWebhooks->json('data.*.url_hint'))->toBe(['https://example.test/…'])
        ->and($demoWatchlist->json('data.*.variant_key'))->not->toContain('zz')
        ->and(count($demoIds))->toBe(2);
    foreach ([$personSamples, $personWebhooks, $personWatchlist, $personExport] as $answer) {
        foreach ([...$demoIds, 'example.test', 'Chimecho', DemoSamples::secret()] as $marker) {
            expect((string) $answer->getContent())->not->toContain($marker);
        }
    }
    foreach ([$demoSamples, $demoWebhooks, $demoWatchlist, $demoExport] as $answer) {
        foreach (['msg_PERSONWEBHOOK0000000000000', 'msg_PERSONEMAIL00000000000000', 'person-marker', 'person-hooks', 'person@example.com', AlertRows::secret()] as $marker) {
            expect((string) $answer->getContent())->not->toContain($marker);
        }
    }
});

it('answers 401 signed out and 500 as problem+json when the samples cannot be read', function (): void {
    t08World();
    t08Seed();
    $demo = t08Demo();

    $signedOut = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    DB::statement('ALTER TABLE deliveries RENAME TO deliveries_t08_gone');
    $broken = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');

    expect($signedOut->getStatusCode())->toBe(401)
        ->and($signedOut->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($signedOut->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($broken->getStatusCode())->toBe(500)
        ->and($broken->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($broken->json('type'))->toBe(ProblemDetails::BASE.'server-error')
        ->and((string) $broken->getContent())->not->toContain(DemoSamples::secret());
});

it('keeps the demo\'s samples through the retention sweep and the dispatch, which never send or create anything for it', function (): void {
    t08World();
    AlertRows::run('2026-10-12');
    t08Seed();
    $demo = t08Demo();
    $person = t08Person();
    $before = t08Rows($demo);
    Http::fake();

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), CarbonImmutable::parse('2027-01-01T00:00:00Z'));
    [$code] = AlertRows::dispatch();

    expect($summary['claimed'])->toBe(0)
        ->and($code)->toBe(0)
        ->and(t08Rows($demo))->toBe($before)
        ->and(DB::table('deliveries')->where('user_id', $person->id)->whereIn('message_id', ['msg_PERSONWEBHOOK0000000000000', 'msg_PERSONEMAIL00000000000000'])->count())->toBe(0)
        ->and(Http::recorded())->toHaveCount(0)
        ->and(Str::of(DemoSamples::DESCRIPTION)->isNotEmpty())->toBeTrue();
});
