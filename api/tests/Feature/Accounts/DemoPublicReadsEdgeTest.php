<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Domain\Alerts\DemoSamples;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

use function Pest\Laravel\getJson;

// Review tests for the demo's public reads: what a stranger, a cache or a signed-in browser can really send.

const DPE_ROUTES = ['/v1/demo/watchlist', '/v1/demo/portfolio', '/v1/demo/alerts'];

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo-edge@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** The seeded demo with every watchlist variant priced and one move on the alert card. */
function dpeSeed(): User
{
    foreach (DemoAccount::watchlist() as $i => $key) {
        AccountRows::price($key, (10 + $i).'.00', '2026-10-12', (9 + $i).'.50', '2026-10-11', '0.50', '5.00');
    }
    AlertRows::event(DemoSamples::FALLBACK, '2026-10-12', [
        'close' => '26.34', 'previous_close' => '5.71', 'change' => '20.63', 'change_pct' => '361.30',
        'as_of' => '2026-10-12 20:05:44+00', 'previous_as_of' => '2026-10-11 20:05:45+00',
    ]);
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    return User::query()->where('is_demo', true)->firstOrFail();
}

/** A public read with no cookie. */
function dpeGet(string $uri, array $headers = []): TestResponse
{
    Accounts::forgetGuards();

    return getJson($uri, $headers);
}

function dpeForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

it('answers a request carrying another account\'s live session cookie with the same body, no Set-Cookie and an untouched session row', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    dpeForgetMemory();
    dpeSeed();
    Accounts::user(['email' => 'person-edge@example.com']);
    $cookie = (string) config('session.cookie');
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'person-edge@example.com', 'password' => Accounts::PASSWORD]);
    $sessionId = (string) Accounts::sessionId($login);
    expect($sessionId)->not->toBe('');
    DB::table('sessions')->where('id', $sessionId)->update(['last_activity' => now()->getTimestamp() - 3000]);
    $before = DB::table('sessions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    foreach (DPE_ROUTES as $route) {
        dpeForgetMemory();
        $anonymous = dpeGet($route)->assertOk();
        dpeForgetMemory();
        $withCookie = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $sessionId)->getJson($route)->assertOk();

        expect($withCookie->getContent())->toBe($anonymous->getContent(), $route)
            ->and($withCookie->headers->get('ETag'))->toBe($anonymous->headers->get('ETag'))
            ->and($withCookie->headers->has('Set-Cookie'))->toBeFalse($route)
            ->and($withCookie->headers->get('Cache-Control'))->toContain('public');
    }
    $after = DB::table('sessions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    expect($after)->toBe($before);
});

it('answers an empty demo with 200 and the shape intact: empty lists, a zero-or-null total, a null alert, message and webhook', function (): void {
    $demo = dpeSeed();
    DB::table('deliveries')->where('user_id', $demo->id)->delete();
    DB::table('webhook_endpoints')->where('user_id', $demo->id)->delete();
    DB::table('watchlist_items')->where('user_id', $demo->id)->delete();
    DB::table('portfolio_holdings')->where('user_id', $demo->id)->delete();

    $watchlist = dpeGet('/v1/demo/watchlist')->assertOk();
    $portfolio = dpeGet('/v1/demo/portfolio')->assertOk();
    $alerts = dpeGet('/v1/demo/alerts')->assertOk();

    expect($watchlist->json('data'))->toBe([])
        ->and($watchlist->json('meta.count'))->toBe(0)
        ->and($watchlist->json('meta.degraded'))->toBeFalse()
        ->and($portfolio->json('data'))->toBe([])
        ->and($portfolio->json('meta.count'))->toBe(0)
        ->and($portfolio->json('meta.priced'))->toBe(0)
        ->and(array_key_exists('total_value', $portfolio->json('meta')))->toBeTrue()
        ->and($alerts->json('data.alert'))->toBeNull()
        ->and($alerts->json('data.message'))->toBeNull()
        ->and($alerts->json('data.webhook'))->toBeNull()
        ->and($alerts->json('data.sample_secret'))->toBe(DemoSamples::secret())
        ->and($alerts->headers->get('ETag'))->not->toBeNull();
});

it('shows nothing of a second webhook of the demo user: its address, its stored secret and its url hint stay out of every answer', function (): void {
    $demo = dpeSeed();
    $marker = 'https://edge-marker-hooks.example.net/path-0000';
    $secretMarker = 'whsec_'.base64_encode(str_repeat('E', 32));
    $extra = (int) DB::table('webhook_endpoints')->insertGetId([
        'user_id' => $demo->id, 'kind' => 'standard', 'url' => encrypt($marker), 'url_hint' => 'https://edge-marker-hooks.example.net/…',
        'secret' => encrypt($secretMarker), 'description' => 'edge extra', 'created_at' => now(),
    ]);
    DB::table('deliveries')->insert([
        'message_id' => 'msg_01K7EDGEEXTRA000000000000A', 'user_id' => $demo->id, 'kind' => 'price.moved', 'channel' => 'webhook',
        'endpoint_id' => $extra, 'event_id' => (int) DB::table('price_events')->value('id'), 'day' => '2026-10-12',
        'body' => '{"edge":"extra"}', 'status' => 'delivered', 'attempts' => 1, 'claims' => 1, 'next_attempt_at' => now(),
        'last_status' => 204, 'delivered_at' => now(), 'created_at' => now(),
    ]);

    foreach (DPE_ROUTES as $route) {
        $text = dpeGet($route)->assertOk()->getContent();

        expect($text)->not->toContain('edge-marker-hooks')
            ->and($text)->not->toContain($secretMarker)
            ->and($text)->not->toContain('msg_01K7EDGEEXTRA')
            ->and($text)->not->toContain('demo-edge@example.com')
            ->and($text)->not->toContain((string) config('app.key'));
    }
});

it('answers HEAD with the GET\'s validator and no body, and a stale If-None-Match with the full 200', function (): void {
    dpeSeed();

    foreach (DPE_ROUTES as $route) {
        $get = dpeGet($route)->assertOk();
        $etag = (string) $get->headers->get('ETag');
        Accounts::forgetGuards();
        $head = $this->call('HEAD', $route, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $stale = dpeGet($route, ['If-None-Match' => '"00000000000000000000000000000000"']);

        expect($head->getStatusCode())->toBe(200, $route)
            ->and($head->getContent())->toBe('')
            ->and($head->headers->get('ETag'))->toBe($etag, $route)
            ->and($head->headers->has('Set-Cookie'))->toBeFalse()
            ->and($stale->getStatusCode())->toBe(200, $route)
            ->and($stale->getContent())->toBe($get->getContent())
            ->and($stale->headers->has('Set-Cookie'))->toBeFalse();
    }
});

it('shares the guest read bucket with the other public reads, and its 429 is a problem document with no Set-Cookie', function (): void {
    $address = ['REMOTE_ADDR' => '192.0.2.44'];
    $limited = 0;
    for ($i = 0; $i < 120; $i++) {
        $status = $this->withServerVariables($address)->getJson($i % 2 === 0 ? '/v1/prices/freshness' : '/v1/demo/alerts')->getStatusCode();
        $limited += $status === 429 ? 1 : 0;
    }

    $over = $this->withServerVariables($address)->withHeaders(Accounts::FIRST_PARTY)->withCredentials()
        ->withCookie((string) config('session.cookie'), 'edge-cookie-0000')->getJson('/v1/demo/watchlist');

    expect($limited)->toBe(0)
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->headers->has('Set-Cookie'))->toBeFalse()
        ->and($over->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($over->headers->get('Retry-After'))->not->toBeNull()
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests');
});

it('answers 404 demo-unavailable, not a 500, when the demo flag is on but the demo row is gone, with no Set-Cookie even with a cookie sent', function (): void {
    $demo = dpeSeed();
    User::query()->whereKey($demo->id)->delete();
    expect(User::query()->where('is_demo', true)->exists())->toBeFalse();

    foreach (DPE_ROUTES as $route) {
        $response = dpeGet($route, Accounts::FIRST_PARTY + ['Cookie' => (string) config('session.cookie').'=edge-cookie-0000']);

        expect($response->getStatusCode())->toBe(404, $route)
            ->and($response->json('type'))->toBe(ProblemDetails::BASE.'demo-unavailable')
            ->and($response->headers->has('Set-Cookie'))->toBeFalse($route)
            ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public');
    }
});
