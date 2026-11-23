<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\DemoSamples;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use App\Http\Middleware\DemoReadOnly;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\DeliveryRows;

// Edge tests for the read-only demo's samples: the write wall taken from the route file and the router, with valid,
// empty and invalid bodies, another account's ids and a method override; both directions between the demo and an
// ordinary account on every touched route, by listing and by guessing ids; the seeder run three times and after a
// tampered row, beside another account's rows, with every outbound fake; the sweep sending a real account's due row
// and never the demo's, and its retention boundary; the sample secret against near misses, the app key and a real
// endpoint's secret; and the samples route refusing every method but GET.

const T08E_ALERT_KEY = 'ex5-17:reverse:-:-';

const T08E_PERSON_KEY = 'base1-2:holo:unlimited:-';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** Every demo variant with a close, and one movement event of the alert card; returns the event id. */
function t08eWorld(): int
{
    foreach (DemoAccount::watchlist() as $key) {
        if ($key !== 'base1-4:holo:1st:-') {
            AccountRows::price($key, '10.00', '2026-10-12');
        } else {
            AccountRows::variant($key);
        }
    }

    return AlertRows::event(T08E_ALERT_KEY, '2026-10-12', [
        'close' => '26.34', 'previous_close' => '5.71', 'change' => '20.63', 'change_pct' => '361.30',
        'as_of' => '2026-10-12 20:05:44+00', 'previous_as_of' => '2026-10-11 20:05:45+00',
    ]);
}

function t08eSeed(): string
{
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    return Artisan::output();
}

function t08eDemo(): User
{
    return User::query()->where('is_demo', true)->firstOrFail();
}

/**
 * A second, ordinary, verified account with a watchlist row and alert, a holding, a signed webhook, a pending and a
 * delivered message and its notification settings; returns [the user, its endpoint id, its delivery ids].
 *
 * @return array{0: User, 1: int, 2: list<int>}
 */
function t08ePerson(string $email = 'person@example.com'): array
{
    $person = Accounts::user(['email' => $email]);
    AccountRows::watch($person, T08E_PERSON_KEY);
    DB::table('watchlist_items')->where('user_id', $person->id)->update(['alert_pct' => 20]);
    DB::table('portfolio_holdings')->insert(['user_id' => $person->id, 'variant_key' => T08E_PERSON_KEY, 'quantity' => 4]);
    $endpoint = DeliveryRows::endpoint($person, 'https://hooks.example.com/person-0000');
    DB::table('webhook_endpoints')->where('id', $endpoint)->update(['url_hint' => 'https://hooks.example.com/…', 'description' => 'person-marker hook']);
    $deliveries = [
        DeliveryRows::delivery($endpoint, ['status' => 'delivered', 'delivered_at' => '2026-10-12 21:00:00+00', 'body' => '{"person-marker":"delivered"}']),
        DeliveryRows::delivery($endpoint, ['next_attempt_at' => '2099-01-01 00:00:00+00', 'body' => '{"person-marker":"pending"}']),
    ];

    return [$person, $endpoint, $deliveries];
}

/** @return array<string, mixed> every row of every table the demo's samples and an account's writes live in */
function t08eSnapshot(): array
{
    $all = fn (string $table, string $order): array => DB::table($table)->orderBy($order)->get()->map(fn (object $r): array => (array) $r)->all();

    return [
        'users' => DB::table('users')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all(),
        'watchlist' => DB::table('watchlist_items')->orderBy('user_id')->orderBy('variant_key')->get()->map(fn (object $r): array => (array) $r)->all(),
        'portfolio' => DB::table('portfolio_holdings')->orderBy('user_id')->orderBy('variant_key')->get()->map(fn (object $r): array => (array) $r)->all(),
        'endpoints' => $all('webhook_endpoints', 'id'),
        'deliveries' => $all('deliveries', 'id'),
        'settings' => $all('notification_settings', 'user_id'),
    ];
}

/** @return array{0: int, 1: string|null, 2: mixed} status, content type and problem type of an answer */
function t08eAnswer(TestResponse $response): array
{
    $type = str_contains((string) $response->headers->get('Content-Type'), 'json') && $response->getContent() !== '' ? $response->json('type') : null;

    return [$response->getStatusCode(), $response->headers->get('Content-Type'), $type];
}

/**
 * Every recorded outbound request that is not the faked PokéSearch (the catalogue reads of the list pages).
 *
 * @return list<string>
 */
function t08eOutbound(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => (string) $pair[0]->url())
        ->filter(fn (string $url): bool => ! str_starts_with($url, AccountRows::BASE))
        ->values()
        ->all();
}

/** The Standard Webhooks check, written independently of the signer. */
function t08eVerifies(string $secret, string $id, string $timestamp, string $body, string $signature): bool
{
    $raw = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : false;
    if ($raw === false || $raw === '') {
        return false;
    }

    return hash_equals('v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $raw, true)), $signature);
}

it('refuses the demo every write route the route file and the router hold, with valid, empty and invalid bodies, another account\'s ids and a method override, changing no row anywhere', function (): void {
    Queue::fake();
    Mail::fake();
    Bus::fake();
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    [, $personEndpoint] = t08ePerson();

    // 1. Every write route written in api/routes/api.php is on the router and behind DemoReadOnly.
    $source = (string) file_get_contents(base_path('routes/api.php'));
    preg_match_all("/Route::(post|put|patch|delete)\\('([^']+)'/", $source, $found, PREG_SET_ORDER);
    $fromFile = array_map(fn (array $m): string => strtoupper($m[1]).' v1'.$m[2], $found);
    expect($fromFile)->not->toBeEmpty();

    $guarded = [];
    $unguarded = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'v1/__')) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $behindWall = in_array(DemoReadOnly::class, $middleware, true) || in_array('web', $middleware, true);
        foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
            if ($behindWall && $route->getName() !== 'logout') {
                $guarded[$method.' '.$route->uri()] = $route->uri();
            } else {
                $unguarded[] = $method.' '.$route->uri();
            }
        }
    }
    sort($unguarded);
    foreach ($fromFile as $write) {
        expect($fromFile === [] || array_key_exists($write, $guarded) || $write === 'POST v1/demo/session')->toBeTrue("{$write} is not behind DemoReadOnly");
    }
    // The only writes the demo is not refused: signing out, and entering the demo again.
    expect($unguarded)->toBe(['POST logout', 'POST v1/demo/session']);

    // 2. Each guarded write, three bodies, the other account's ids in the path: 403 demo-read-only, nothing changes.
    $before = t08eSnapshot();
    $valid = [
        'pct' => 20, 'quantity' => 2, 'kind' => 'standard', 'url' => 'https://example.test/other', 'description' => 'x',
        'channel' => 'email', 'digest' => true, 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
        'email' => 'demo@example.com', 'invite_code' => Accounts::INVITE, 'code' => '123456',
    ];
    $invalid = [
        'pct' => 'many', 'quantity' => -5, 'kind' => ['x'], 'url' => 123, 'channel' => 'pigeon', 'digest' => 'maybe',
        'password' => ['a'], 'email' => str_repeat('a', 400), 'code' => str_repeat('9', 5000),
    ];
    $answers = [];
    foreach ($guarded as $key => $uri) {
        $method = explode(' ', $key)[0];
        $path = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m): string => match ($m[1]) {
            'variantKey' => rawurlencode(T08E_PERSON_KEY),
            'id' => (string) $personEndpoint,
            default => 'example',
        }, $uri);
        foreach (['valid' => $valid, 'empty' => [], 'invalid' => $invalid] as $label => $body) {
            // Fresh limiter counts per request: the password-confirm tier (5 a minute) answers 429 before the wall
            // once it is spent, and this test sends far more than a person would.
            app('cache')->store('array')->flush();
            $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->json($method, $path, $body);
            $answers["{$key} {$label}"] = t08eAnswer($response);
        }
    }
    // A method override in the body or a header never turns a write into something the wall lets through.
    $overrides = [];
    foreach (["/v1/webhooks/{$personEndpoint}", '/v1/watchlist/'.rawurlencode(T08E_ALERT_KEY)] as $path) {
        app('cache')->store('array')->flush();
        $overrides[] = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->json('POST', $path, ['_method' => 'DELETE'])->getStatusCode();
        $overrides[] = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY + ['X-HTTP-Method-Override' => 'DELETE'])->json('POST', $path)->getStatusCode();
    }

    $refused = [403, 'application/problem+json', ProblemDetails::BASE.'demo-read-only'];
    expect(array_filter($answers, fn (array $answer): bool => $answer !== $refused))->toBe([])
        ->and(count($answers))->toBe(3 * count($guarded))
        ->and(array_diff($overrides, [403, 405]))->toBe([])
        ->and(t08eSnapshot())->toBe($before)
        ->and(t08eOutbound())->toBe([]);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('answers the samples route only to GET: every other method is refused for the demo, an ordinary account and a visitor, and changes nothing', function (): void {
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    [$person] = t08ePerson();
    $before = t08eSnapshot();

    $answers = [];
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $answers["demo {$method}"] = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->json($method, '/v1/demo/samples', ['sample_secret' => 'x'])->getStatusCode();
        $answers["person {$method}"] = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->json($method, '/v1/demo/samples')->getStatusCode();
    }
    Accounts::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $this->app['auth']->guard('web')->logout();
    $visitor = $this->withHeaders(Accounts::FIRST_PARTY)->json('DELETE', '/v1/demo/samples');

    expect($answers)->toBe(array_fill_keys(array_keys($answers), 405))
        ->and($visitor->getStatusCode())->toBeIn([401, 405])
        ->and(t08eSnapshot())->toBe($before);
});

it('never lets an ordinary account reach the demo\'s samples by guessing ids, nor the demo reach the account\'s, and answers a visitor 401 on every touched read', function (): void {
    Queue::fake();
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    [$person, $personEndpoint, $personDeliveries] = t08ePerson();
    $demoEndpoint = (int) DB::table('webhook_endpoints')->where('user_id', $demo->id)->value('id');
    $demoKey = rawurlencode(T08E_ALERT_KEY);
    $before = t08eSnapshot();

    // The ordinary account aims every id route at the demo's rows.
    $person1 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->deleteJson("/v1/webhooks/{$demoEndpoint}");
    $person2 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->postJson("/v1/webhooks/{$demoEndpoint}/test");
    $person3 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->deleteJson("/v1/alerts/{$demoKey}");
    $person4 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    $person5 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples?user_id='.$demo->id.'&id='.$demoEndpoint);
    $personReads = [
        AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks'),
        AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist'),
        AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio'),
        AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export'),
        AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/notifications'),
        $person4, $person5, $person2,
    ];
    // The demo reads, and reads with the account's ids in the query.
    $demoReads = [
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples?user_id='.$person->id.'&endpoint_id='.$personEndpoint.'&id='.$personDeliveries[0]),
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks?user_id='.$person->id),
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist?user_id='.$person->id),
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio?user_id='.$person->id),
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export'),
        AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/notifications'),
    ];
    $demoDeliveryIds = DB::table('deliveries')->where('user_id', $demo->id)->pluck('message_id')->all();
    $personMessageIds = DB::table('deliveries')->where('user_id', $person->id)->pluck('message_id')->all();

    expect($person1->getStatusCode())->toBe(204)
        ->and(t08eAnswer($person2))->toBe([404, 'application/problem+json', ProblemDetails::BASE.'webhook-not-found'])
        ->and($person3->getStatusCode())->toBe(204)
        ->and(t08eAnswer($person4))->toBe([404, 'application/problem+json', ProblemDetails::BASE.'not-found'])
        ->and(t08eAnswer($person5))->toBe([404, 'application/problem+json', ProblemDetails::BASE.'not-found'])
        ->and(t08eSnapshot())->toBe($before)
        ->and(t08eOutbound())->toBe([])
        ->and($demoReads[0]->json('data.webhook.message_id'))->toBe(DemoSamples::WEBHOOK_MESSAGE_ID)
        ->and($demoReads[1]->json('data.*.id'))->toBe([$demoEndpoint])
        ->and(count($demoDeliveryIds))->toBe(2);
    Queue::assertNothingPushed();
    foreach ($personReads as $answer) {
        foreach ([...$demoDeliveryIds, 'example.test', 'Chimecho', 'Sample signed webhook', 'whsec_', 'demo@example.com', DemoSamples::KEY] as $marker) {
            expect((string) $answer->getContent())->not->toContain($marker);
        }
    }
    foreach ($demoReads as $answer) {
        expect($answer->getStatusCode())->toBe(200);
        foreach ([...$personMessageIds, 'person-marker', 'person-0000', 'hooks.example.com', 'person@example.com', AlertRows::secret(), T08E_PERSON_KEY.'","alert_pct":20'] as $marker) {
            expect((string) $answer->getContent())->not->toContain($marker);
        }
    }

    // A visitor with no session, on each touched read: 401 problem+json.
    Accounts::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $this->app['auth']->guard('web')->logout();
    foreach (['/v1/demo/samples', '/v1/webhooks', '/v1/watchlist', '/v1/portfolio', '/v1/me/notifications'] as $uri) {
        expect(t08eAnswer($this->withHeaders(Accounts::FIRST_PARTY)->getJson($uri)))->toBe([401, 'application/problem+json', ProblemDetails::BASE.'unauthenticated']);
    }
});

it('answers an unverified account 404 on the samples route, and a deleted account\'s session 401, as before', function (): void {
    t08eWorld();
    t08eSeed();
    $unverified = Accounts::user(['email' => 'unverified@example.com', 'email_verified_at' => null]);
    $gone = Accounts::user(['email' => 'gone@example.com']);

    $first = AccountRows::signIn($this, $unverified)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    AccountRows::signIn($this, $gone);
    DB::table('users')->where('id', $gone->id)->delete();
    Accounts::forgetGuards();
    $second = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');

    expect(t08eAnswer($first))->toBe([404, 'application/problem+json', ProblemDetails::BASE.'not-found'])
        ->and((string) $first->getContent())->not->toContain('whsec_')
        ->and($second->getStatusCode())->toBe(401)
        ->and((string) $second->getContent())->not->toContain('whsec_');
});

it('seeds the same rows three times and after tampered rows, never touches another account\'s rows, and sends or queues nothing', function (): void {
    Queue::fake();
    Mail::fake();
    Bus::fake();
    t08eWorld();
    [$person] = t08ePerson();
    $personRows = fn (): array => array_map(
        fn (array $rows): array => array_values(array_filter($rows, fn (array $r): bool => ($r['user_id'] ?? $r['id'] ?? null) === $person->id)),
        t08eSnapshot(),
    );
    $personBefore = $personRows();
    $demoRows = function (): array {
        $snapshot = t08eSnapshot();
        $id = t08eDemo()->id;
        unset($snapshot['users']);

        return array_map(fn (array $rows): array => array_values(array_filter($rows, fn (array $r): bool => $r['user_id'] === $id)), $snapshot);
    };

    t08eSeed();
    $first = $demoRows();
    t08eSeed();
    t08eSeed();
    $third = $demoRows();

    // Tamper: a second alert, a stray webhook and a stray delivery of the demo, the sample endpoint disabled, one
    // sample delivery deleted, the other's body rewritten, and a seeded watchlist row removed.
    $demo = t08eDemo();
    DB::table('watchlist_items')->where('user_id', $demo->id)->where('variant_key', 'base1-58:normal:unlimited:-')->update(['alert_pct' => 50]);
    DB::table('watchlist_items')->where('user_id', $demo->id)->where('variant_key', 'base3-4:holo:unlimited:-')->delete();
    $sampleEndpoint = (int) $first['endpoints'][0]['id'];
    DB::table('webhook_endpoints')->where('id', $sampleEndpoint)->update(['disabled_at' => '2026-10-13 00:00:00+00']);
    $stray = DeliveryRows::endpoint($demo, 'https://example.test/stray');
    DeliveryRows::delivery($stray, ['status' => 'delivered', 'delivered_at' => '2026-10-12 21:00:00+00']);
    DB::table('deliveries')->where('message_id', DemoSamples::ALERT_MESSAGE_ID)->delete();
    DB::table('deliveries')->where('message_id', DemoSamples::WEBHOOK_MESSAGE_ID)->update(['body' => '{"tampered":true}', 'status' => 'failed']);

    t08eSeed();
    $after = $demoRows();
    $samples = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples')->assertOk();

    $strip = function (array $rows): array {
        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['message_id'], (string) $b['message_id']));

        return array_map(fn (array $r): array => array_diff_key($r, ['id' => 0]), $rows);
    };
    expect($third)->toBe($first)
        ->and($first['watchlist'])->toHaveCount(14)
        ->and(array_values(array_filter(array_column($after['watchlist'], 'alert_pct'))))->toBe([DemoSamples::ALERT_PCT])
        ->and(array_column($after['watchlist'], 'variant_key'))->toBe(array_column($first['watchlist'], 'variant_key'))
        ->and(array_column($after['endpoints'], 'id'))->toBe([$sampleEndpoint])
        ->and($after['endpoints'][0]['disabled_at'])->toBeNull()
        ->and($strip($after['deliveries']))->toBe($strip($first['deliveries']))
        ->and(array_column($after['deliveries'], 'message_id'))->toEqualCanonicalizing([DemoSamples::ALERT_MESSAGE_ID, DemoSamples::WEBHOOK_MESSAGE_ID])
        ->and($samples->json('data.webhook.body'))->not->toContain('tampered')
        ->and($personRows())->toBe($personBefore)
        ->and(t08eOutbound())->toBe([]);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('seeds without failing when demo cards are missing from the catalogue tables and never reads the catalogue', function (): void {
    t08eWorld();
    DB::table('price_latest')->whereIn('variant_key', ['swsh7-95:holo:-:-', 'sv8pt5-161:holo:-:-'])->delete();
    DB::table('card_variants')->whereIn('variant_key', ['swsh7-95:holo:-:-', 'sv8pt5-161:holo:-:-'])->delete();
    AccountRows::$missing = ['swsh7-95', 'sv8pt5-161'];

    $output = t08eSeed();
    $demo = t08eDemo();

    expect(DB::table('watchlist_items')->where('user_id', $demo->id)->count())->toBe(12)
        ->and($output)->toContain('swsh7-95:holo:-:-')
        ->and(DB::table('deliveries')->where('user_id', $demo->id)->count())->toBe(2)
        ->and(t08eOutbound())->toBe([]);
    AccountRows::$missing = [];
});

it('sends a real account\'s due delivery and never the demo\'s, and its retention sweep keeps the demo\'s samples while deleting an ordinary account\'s rows just past the boundary', function (): void {
    DeliveryRows::resolver();
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    $person = Accounts::user(['email' => 'sweep@example.com']);
    $endpoint = DeliveryRows::endpoint($person);
    $now = CarbonImmutable::parse('2027-01-01T00:00:00Z');
    $cutoff = $now->subDays(30);
    $due = DeliveryRows::delivery($endpoint, ['next_attempt_at' => $now->subMinute()->format('Y-m-d H:i:sP'), 'created_at' => $now->subMinute()->format('Y-m-d H:i:sP')]);
    $old = DeliveryRows::delivery($endpoint, ['status' => 'delivered', 'created_at' => $cutoff->subSecond()->format('Y-m-d H:i:sP')]);
    $edge = DeliveryRows::delivery($endpoint, ['status' => 'delivered', 'created_at' => $cutoff->addSecond()->format('Y-m-d H:i:sP')]);
    $demoBefore = DB::table('deliveries')->where('user_id', $demo->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
    // A plain receiver in place of the faked catalogue, and the app clock at the sweep's instant (its budget).
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 204)]);
    DeliveryRows::jitter('max');
    $this->travelTo($now);

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $now);

    $sent = Http::recorded();
    expect($summary['claimed'])->toBe(1)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0][0]->url())->toBe(DeliveryRows::URL)
        ->and(DB::table('deliveries')->where('id', $due)->value('status'))->toBe('delivered')
        ->and(DB::table('deliveries')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('deliveries')->where('id', $edge)->exists())->toBeTrue()
        ->and(DB::table('deliveries')->where('user_id', $demo->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all())->toBe($demoBefore);
    foreach ($sent as [$request]) {
        expect($request->url())->not->toContain('example.test');
    }
});

it('creates no delivery for the demo when its alert card moves again on a later day, while an ordinary account watching the same card gets one', function (): void {
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    $person = Accounts::user(['email' => 'watcher@example.com']);
    AccountRows::watch($person, T08E_ALERT_KEY);
    DB::table('watchlist_items')->where('user_id', $person->id)->update(['alert_pct' => 10]);
    $before = DB::table('deliveries')->where('user_id', $demo->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
    AlertRows::runs('2026-10-12', '2026-10-13');
    AlertRows::event(T08E_ALERT_KEY, '2026-10-13');

    [$code] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and(DB::table('deliveries')->where('user_id', $demo->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all())->toBe($before)
        ->and(DB::table('deliveries')->where('user_id', $person->id)->count())->toBeGreaterThanOrEqual(1);
});

it('signs the shown payload with the published sample secret only: not a one-character change, an empty secret, the app key or a real endpoint\'s secret, and not a re-serialised body', function (): void {
    DeliveryRows::resolver();
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    [$person] = t08ePerson();
    $created = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => DeliveryRows::URL.'/real']);
    $created->assertCreated();
    $realSecret = (string) $created->json('data.secret');
    $personList = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/webhooks');
    $samples = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples')->assertOk();

    $secret = (string) $samples->json('data.sample_secret');
    $webhook = $samples->json('data.webhook');
    $id = $webhook['headers']['webhook-id'];
    $ts = $webhook['headers']['webhook-timestamp'];
    $sig = $webhook['headers']['webhook-signature'];
    $body = $webhook['body'];
    $last = substr($secret, -2, 1);
    $oneOff = substr($secret, 0, -2).($last === 'A' ? 'B' : 'A').substr($secret, -1);
    $appKey = (string) config('app.key');
    $resorted = json_encode(json_decode($body, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decoded = json_decode($body, true);
    krsort($decoded['data']);
    $reordered = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $demoEndpointSecret = Crypt::decryptString((string) DB::table('webhook_endpoints')->where('user_id', $demo->id)->value('secret'));
    $personStored = array_map(fn ($s): string => Crypt::decryptString((string) $s), DB::table('webhook_endpoints')->where('user_id', $person->id)->pluck('secret')->all());

    expect($secret)->toBe('whsec_'.base64_encode('pokeprices-demo-sample-secret-01'))
        ->and(t08eVerifies($secret, $id, $ts, $body, $sig))->toBeTrue()
        ->and(t08eVerifies($oneOff, $id, $ts, $body, $sig))->toBeFalse()
        ->and(t08eVerifies('', $id, $ts, $body, $sig))->toBeFalse()
        ->and(t08eVerifies('whsec_', $id, $ts, $body, $sig))->toBeFalse()
        ->and(t08eVerifies('whsec_'.substr($appKey, 7), $id, $ts, $body, $sig))->toBeFalse()
        ->and(t08eVerifies($realSecret, $id, $ts, $body, $sig))->toBeFalse()
        ->and(t08eVerifies($secret, $id, $ts, (string) $resorted, $sig))->toBeFalse()
        ->and(t08eVerifies($secret, $id, $ts, (string) $reordered, $sig))->toBeFalse()
        ->and(t08eVerifies($secret, $id, (string) ((int) $ts + 1), $body, $sig))->toBeFalse()
        ->and($ts)->toBe((string) CarbonImmutable::parse($webhook['delivered_at'])->getTimestamp())
        ->and($body)->toBe(DB::table('deliveries')->where('message_id', DemoSamples::WEBHOOK_MESSAGE_ID)->value('body'))
        ->and($demoEndpointSecret)->toBe($secret)
        ->and($personStored)->not->toContain($secret)
        ->and($realSecret)->not->toBe($secret)
        ->and((string) $personList->getContent())->not->toContain('whsec_')
        ->and(parse_url((string) Crypt::decryptString((string) DB::table('webhook_endpoints')->where('user_id', $demo->id)->value('url')), PHP_URL_HOST))->toBe('example.test')
        ->and($webhook['url_hint'])->toStartWith('https://example.test/');
    // The payload carries no account's data.
    foreach (['demo@example.com', 'person@example.com', '"user_id"', '"email"', 'whsec_', $realSecret, 'msg_'] as $marker) {
        expect($body)->not->toContain($marker);
    }
    expect($samples->json('data.message.text'))->not->toContain('demo@example.com')
        ->and($samples->json('data.message.text'))->toContain('as of 2026-10-12 from');
});

it('answers 500 problem+json without the sample secret or the table name when the webhooks table cannot be read, and 404 for an ordinary account whatever the samples hold', function (): void {
    t08eWorld();
    t08eSeed();
    $demo = t08eDemo();
    [$person] = t08ePerson();

    $person404 = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');
    DB::statement('ALTER TABLE webhook_endpoints RENAME TO webhook_endpoints_t08e_gone');
    $broken = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/demo/samples');

    expect(t08eAnswer($person404))->toBe([404, 'application/problem+json', ProblemDetails::BASE.'not-found'])
        ->and(array_keys($person404->json()))->not->toContain('data')
        ->and(t08eAnswer($broken))->toBe([500, 'application/problem+json', ProblemDetails::BASE.'server-error'])
        ->and((string) $broken->getContent())->not->toContain('whsec_')
        ->and((string) $broken->getContent())->not->toContain('webhook_endpoints');
});
