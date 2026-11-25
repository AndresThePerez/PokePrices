<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Domain\Alerts\DemoSamples;
use App\Http\CachePolicy;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\DeliveryRows;

use function Pest\Laravel\getJson;

// The demo's watchlist, portfolio and alert sample as public reads: no session, no cookie, nothing private, the
// same rows the demo's own signed-in reads answer, the guest read limit and the public cache headers. Every /v1
// answer is also validated against openapi/api.json by Tests\TestCase.

/** Each public read and the signed-in read it mirrors. */
const DPR_ROUTES = [
    '/v1/demo/watchlist' => '/v1/watchlist',
    '/v1/demo/portfolio' => '/v1/portfolio',
    '/v1/demo/alerts' => '/v1/demo/samples',
];

/** A user id no other row of these tests reaches, so a scan for it finds only a leak. */
const DPR_DEMO_ID = 987_655;

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo-public@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** Every demo variant priced except the 1st Edition Charizard, one move of the alert card, and the seeded demo. */
function dprSeed(): User
{
    foreach (DemoAccount::watchlist() as $i => $key) {
        if ($key === 'base1-4:holo:1st:-') {
            AccountRows::variant($key);
        } else {
            AccountRows::price($key, (10 + $i).'.00', '2026-10-12', (9 + $i).'.50', '2026-10-11', '0.50', '5.00');
        }
    }
    AlertRows::event(DemoSamples::FALLBACK, '2026-10-12', [
        'close' => '26.34', 'previous_close' => '5.71', 'change' => '20.63', 'change_pct' => '361.30',
        'as_of' => '2026-10-12 20:05:44+00', 'previous_as_of' => '2026-10-11 20:05:45+00',
    ]);
    DB::statement('SELECT setval(\'users_id_seq\', '.(DPR_DEMO_ID - 1).')');
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    return User::query()->where('is_demo', true)->firstOrFail();
}

/** A public read with no cookie and no first-party headers. */
function dprGet(string $uri, array $headers = []): TestResponse
{
    Accounts::forgetGuards();

    return getJson($uri, $headers);
}

/**
 * Every value (as text) and every key of a decoded answer, at any depth, with JSON strings inside it decoded too.
 *
 * @return array{values: list<string>, keys: list<string>}
 */
function dprScan(mixed $value): array
{
    if (is_string($value)) {
        $inner = json_decode($value, true);
        $nested = is_array($inner) ? dprScan($inner) : ['values' => [], 'keys' => []];

        return ['values' => [$value, ...$nested['values']], 'keys' => $nested['keys']];
    }
    if (! is_array($value)) {
        return ['values' => [(string) json_encode($value)], 'keys' => []];
    }
    $found = ['values' => [], 'keys' => []];
    foreach ($value as $key => $item) {
        $inner = dprScan($item);
        $found['keys'] = [...$found['keys'], (string) $key, ...$inner['keys']];
        $found['values'] = [...$found['values'], ...$inner['values']];
    }

    return $found;
}

it('(a) answers each public read signed out with what the demo\'s own signed-in read answers', function (): void {
    $demo = dprSeed();

    foreach (DPR_ROUTES as $public => $signedIn) {
        $own = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson($signedIn)->assertOk();
        $answer = dprGet($public)->assertOk();

        expect($answer->json())->toBe($own->json(), $public);
    }
    $portfolio = dprGet('/v1/demo/portfolio')->json();
    expect(array_column(dprGet('/v1/demo/watchlist')->json('data'), 'variant_key'))->toBe(AccountRows::watched($demo))
        ->and($portfolio['meta']['total_value'])->not->toBeNull()
        ->and($portfolio['meta']['change'])->not->toBeNull()
        ->and($portfolio['meta']['change_pct'])->not->toBeNull()
        ->and(dprGet('/v1/demo/alerts')->json('data.alert.variant_key'))->toBe(DemoSamples::FALLBACK);
});

it('(a) answers a demo row whose variant has no close, with a null price', function (): void {
    dprSeed();

    $rows = collect(dprGet('/v1/demo/watchlist')->assertOk()->json('data'))->keyBy('variant_key');

    expect($rows['base1-4:holo:1st:-']['price'])->toBeNull()
        ->and($rows['base1-2:holo:unlimited:-']['price']['close'])->toBe('11.00');
});

it('(a) answers the same body to a caller signed in as someone else', function (): void {
    dprSeed();
    $person = Accounts::user(['email' => 'person-public@example.com']);
    AccountRows::watch($person, 'base1-4:holo:1st:-');

    foreach (array_keys(DPR_ROUTES) as $public) {
        $anonymous = dprGet($public)->assertOk();
        $signedIn = AccountRows::signIn($this, $person)->withHeaders(Accounts::FIRST_PARTY)->getJson($public)->assertOk();

        expect($signedIn->getContent())->toBe($anonymous->getContent(), $public)
            ->and($signedIn->headers->get('ETag'))->toBe($anonymous->headers->get('ETag'));
    }
});

it('(b) sets no cookie and writes no sessions row', function (): void {
    dprSeed();
    $before = DB::table('sessions')->count();

    foreach (array_keys(DPR_ROUTES) as $public) {
        $response = dprGet($public, Accounts::FIRST_PARTY)->assertOk();

        expect($response->headers->has('Set-Cookie'))->toBeFalse($public)
            ->and($response->headers->getCookies())->toBe([]);
    }
    expect(DB::table('sessions')->count())->toBe($before);
});

it('(c) carries no email, user id, webhook address or stored secret at any depth', function (): void {
    $demo = dprSeed();
    $person = Accounts::user(['email' => 'person-public@example.com']);
    $endpoint = DeliveryRows::endpoint($person, 'https://person-hooks.example.com/hook-0000');
    $personStored = (string) DB::table('webhook_endpoints')->where('id', $endpoint)->value('secret');
    $demoStored = (string) DB::table('webhook_endpoints')->where('user_id', $demo->id)->value('secret');
    $demoUrl = (string) DB::table('webhook_endpoints')->where('user_id', $demo->id)->value('url');

    expect($demo->id)->toBe(DPR_DEMO_ID);
    foreach (array_keys(DPR_ROUTES) as $public) {
        $found = dprScan(dprGet($public)->assertOk()->json());
        $text = implode("\n", [...$found['values'], ...$found['keys']]);

        expect($text)->not->toContain('demo-public@example.com')
            ->and($text)->not->toContain('person-public@example.com')
            ->and($text)->not->toContain((string) DPR_DEMO_ID)
            ->and($text)->not->toContain(DemoSamples::URL)
            ->and($text)->not->toContain('person-hooks.example.com')
            ->and($text)->not->toContain(AlertRows::secret())
            ->and($text)->not->toContain($personStored)
            ->and($text)->not->toContain($demoStored)
            ->and($text)->not->toContain($demoUrl)
            ->and($text)->not->toContain((string) config('app.key') ?: 'no app key set')
            ->and(array_values(array_intersect($found['keys'], ['user_id', 'email', 'id', 'endpoint_id', 'secret', 'url'])))->toBe([], $public);
    }
});

it('(d) answers 404 demo-unavailable on all three when the demo is disabled or not seeded', function (): void {
    foreach (array_keys(DPR_ROUTES) as $public) {
        $unseeded = dprGet($public);

        expect($unseeded->getStatusCode())->toBe(404, $public)
            ->and($unseeded->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and($unseeded->json('type'))->toBe(ProblemDetails::BASE.'demo-unavailable');
    }

    dprSeed();
    config(['accounts.demo.enabled' => false]);
    foreach (array_keys(DPR_ROUTES) as $public) {
        $disabled = dprGet($public);

        expect($disabled->getStatusCode())->toBe(404, $public)
            ->and($disabled->json('type'))->toBe(ProblemDetails::BASE.'demo-unavailable');
    }
});

it('(e) answers the 121st request in a minute from one address with 429', function (): void {
    // The guest read limit is one bucket per address across every public read, so each route gets its own address.
    foreach (array_keys(DPR_ROUTES) as $n => $public) {
        $statuses = [];
        for ($i = 0; $i < 121; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(10 + $n)])->getJson($public)->getStatusCode();
        }

        expect(array_count_values(array_slice($statuses, 0, 120)))->toBe([404 => 120], $public)
            ->and($statuses[120])->toBe(429);
    }
});

it('(e) sends an ETag and the public history Cache-Control, and answers a matching If-None-Match with 304', function (): void {
    dprSeed();

    foreach (array_keys(DPR_ROUTES) as $public) {
        $first = dprGet($public)->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $again = dprGet($public, ['If-None-Match' => $etag]);

        expect($etag)->not->toBe('', $public)
            ->and($first->headers->get('Cache-Control'))->toBe('max-age=300, public, stale-while-revalidate=600')
            ->and($again->getStatusCode())->toBe(304)
            ->and($again->headers->get('ETag'))->toBe($etag);
    }
});

it('(e) sends a degraded copy no-store and without an ETag', function (): void {
    dprSeed();
    AccountRows::$down = true;
    CatalogCache::flush($this->prefix);

    $response = dprGet('/v1/demo/watchlist')->assertOk();

    expect($response->json('meta.degraded'))->toBeTrue()
        ->and((string) $response->headers->get('Cache-Control'))->toContain(CachePolicy::NO_STORE)
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('reads each list in a bounded number of queries', function (): void {
    dprSeed();
    dprGet('/v1/demo/watchlist')->assertOk();

    foreach (array_keys(DPR_ROUTES) as $public) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        dprGet($public)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThanOrEqual(4, $public);
    }
});

it('(b, e) routes the three reads outside the account group, with throttle:public-read and conditional-get:history', function (): void {
    foreach (['demo.watchlist.public', 'demo.portfolio.public', 'demo.alerts.public'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull($name);
        $middleware = Route::gatherRouteMiddleware($route);
        $names = array_map(fn (mixed $m): string => is_string($m) ? $m : 'closure', $route->gatherMiddleware());

        expect($route->methods())->toContain('GET')
            ->and($names)->toContain('throttle:public-read')
            ->and($names)->toContain('conditional-get:history')
            ->and(implode(' ', $middleware))->not->toContain('StartSession')
            ->and(implode(' ', $middleware))->not->toContain('EnsureFrontendRequestsAreStateful')
            ->and(implode(' ', $middleware))->not->toContain('NoStore')
            ->and(implode(' ', $middleware))->not->toContain('Authenticate');
    }
});

it('(f) documents the three reads with 200, 304, 404 and 429', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach (['/demo/watchlist', '/demo/portfolio', '/demo/alerts'] as $path) {
        $responses = $spec['paths'][$path]['get']['responses'] ?? [];

        expect(array_keys($responses))->toContain(200, 304, 404, 429)
            ->and($responses)->not->toHaveKey(401);
    }
});
