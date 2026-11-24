<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// A Redis outage is answered as degraded or as a 503 with Retry-After, never a bare 500. Redis has two roles here:
// "default" (redis-queue) holds the rate limiters and the catalogue's locks, "cache" (redis-cache) the catalogue's
// data. Each test points one of them at an address nothing answers on, so every request waits for the connect
// timeout: the requests are few on purpose.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->catalogPrefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    config(['database.redis.default.host' => env('REDIS_HOST', 'redis-queue'), 'database.redis.cache.host' => env('REDIS_CACHE_HOST', 'redis-cache')]);
    outageForgetRedis();
    CatalogCache::flush($this->catalogPrefix);
});

function outageForgetRedis(): void
{
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    Cache::forgetDriver(['redis', 'locks']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
}

/** Points one Redis role ("default" or "cache") at an address nothing answers on. */
function outageRedisDown(string $role): void
{
    config(["database.redis.{$role}.host" => '192.0.2.10']);
    outageForgetRedis();
}

/** @param TestResponse<Response> $response */
function outageExpectUnavailable(TestResponse $response, string $path): void
{
    expect($response->getStatusCode())->toBe(503, $path)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json', $path)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'http-503', $path)
        ->and($response->json('title'))->toBe('Service Unavailable', $path)
        ->and($response->headers->get('Retry-After'))->toBe('5', $path);
}

it('answers a throttled route whose limiter store is down with the 503 problem and Retry-After, never a 500', function (string $method, string $path): void {
    // The limiters count in the "locks" store, on the "default" role.
    config(['cache.limiter' => 'locks']);
    outageRedisDown('default');

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->json($method, $path);

    outageExpectUnavailable($response, $path);
    expect($response->getStatusCode())->toBe(503);
})->with([
    'public-read: the freshness of the prices' => ['GET', '/v1/prices/freshness'],
    'public-read: the price history of a card' => ['GET', '/v1/cards/base1-4/prices'],
    'public-read: the movers' => ['GET', '/v1/movers'],
    'public-read: a card' => ['GET', '/v1/cards/base1-4'],
    'search: the catalogue search' => ['GET', '/v1/cards?q=charizard'],
    'the guest limit, before any session: /v1/me' => ['GET', '/v1/me'],
    'the guest limit: the watchlist' => ['GET', '/v1/watchlist'],
    'the guest limit: the demo samples' => ['GET', '/v1/demo/samples'],
    'the demo-session limiter' => ['POST', '/v1/demo/session'],
    'the guest limit on a package route: the CSRF cookie' => ['GET', '/sanctum/csrf-cookie'],
    'the guest limit on a package route: sign-in help' => ['POST', '/forgot-password'],
    'the sign-in limiter' => ['POST', '/login'],
    'the registration limiter' => ['POST', '/register'],
]);

it('answers a signed-in route whose limiter store is down with the 503 problem, for each of two accounts', function (): void {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $cookies = [];
    foreach (['first@example.com', 'second@example.com'] as $email) {
        Accounts::forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Accounts::user(['email' => $email]);
        $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => Accounts::PASSWORD]);
        $login->assertOk();
        $cookies[$email] = (string) Accounts::sessionId($login);
    }
    config(['cache.limiter' => 'locks']);
    outageRedisDown('default');

    foreach ($cookies as $sessionId) {
        Accounts::forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $response = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie((string) config('session.cookie'), $sessionId)->getJson('/v1/me');

        outageExpectUnavailable($response, '/v1/me');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('answers a catalogue read whose cache is down with the 503 problem and Retry-After', function (string $path): void {
    outageRedisDown('cache');

    $response = $this->getJson($path);

    outageExpectUnavailable($response, $path);
    expect($response->getStatusCode())->toBe(503);
})->with([
    'a card' => ['/v1/cards/base1-4'],
    'a search past the last page' => ['/v1/cards?q=charizard&page=99'],
]);

it('answers the lists that only name their cards from the catalogue cache as degraded: 200, null names, meta.degraded true', function (): void {
    $first = Accounts::user(['email' => 'first@example.com']);
    $second = Accounts::user(['email' => 'second@example.com']);
    AccountRows::price('base1-4:holo:unlimited:-', '968.91');
    AccountRows::watch($first, 'base1-4:holo:unlimited:-');
    AccountRows::hold($second, 'base1-4:holo:unlimited:-', 2);
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'base1-4:holo:unlimited:-', 'card_id' => 'base1-4', 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
    outageRedisDown('cache');

    $movers = $this->getJson('/v1/movers');
    $watchlist = AccountRows::signIn($this, $first)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $portfolio = AccountRows::signIn($this, $second)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
    $othersWatchlist = AccountRows::signIn($this, $second)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');

    expect($movers->getStatusCode())->toBe(200)
        ->and($movers->json('data.0.card'))->toBeNull()
        ->and($movers->json('data.0.close'))->toBe('11.00')
        ->and($movers->json('meta.degraded'))->toBeTrue()
        ->and($movers->headers->get('Cache-Control'))->toContain('no-store')
        ->and($watchlist->getStatusCode())->toBe(200)
        ->and($watchlist->json('data.0.card'))->toBeNull()
        ->and($watchlist->json('data.0.price.close'))->toBe('968.91')
        ->and($watchlist->json('meta'))->toBe(['count' => 1, 'limit' => 100, 'degraded' => true])
        ->and($portfolio->getStatusCode())->toBe(200)
        ->and($portfolio->json('data.0.card'))->toBeNull()
        ->and($portfolio->json('meta.degraded'))->toBeTrue()
        ->and($othersWatchlist->getStatusCode())->toBe(200)
        ->and($othersWatchlist->json('data'))->toBe([]);
});

it('answers the routes that read no catalogue as without the outage while the catalogue cache is down, for each of two accounts', function (): void {
    $first = Accounts::user(['email' => 'first@example.com']);
    $second = Accounts::user(['email' => 'second@example.com']);
    AccountRows::price('base1-4:holo:unlimited:-', '968.91');
    $read = function () use ($first, $second): array {
        $statuses = [];
        foreach (['/v1/prices/freshness', '/v1/cards/base1-4/prices'] as $path) {
            $statuses[$path] = $this->getJson($path)->getStatusCode();
        }
        foreach ([$first, $second] as $user) {
            foreach (['/v1/me', '/v1/me/export', '/v1/demo/samples'] as $path) {
                $statuses["{$user->email} {$path}"] = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson($path)->getStatusCode();
            }
        }

        return $statuses;
    };

    $before = $read();
    outageRedisDown('cache');
    $during = $read();

    expect($during)->toBe($before)
        ->and($during['/v1/prices/freshness'])->toBe(200)
        ->and($during['/v1/cards/base1-4/prices'])->toBe(200)
        ->and($during['first@example.com /v1/me'])->toBe(200)
        ->and($during['second@example.com /v1/me/export'])->toBe(200)
        ->and($during['second@example.com /v1/demo/samples'])->toBe(404);
});

it('documents the 503 http-503 with Retry-After on every rate-limited operation of the contract, and on no other: only getReady has no rate limit', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $storeOutage = ProblemDetails::BASE.'http-503';
    $limited = 0;
    $unlimited = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $unavailable = $operation['responses']['503'] ?? $operation['responses'][503] ?? null;
            if (! array_key_exists('429', $operation['responses'])) {
                $unlimited[] = $operation['operationId'];
                expect(str_contains((string) ($unavailable['description'] ?? ''), $storeOutage))->toBeFalse("{$method} {$path}");

                continue;
            }
            $limited++;
            expect($unavailable)->not->toBeNull("{$method} {$path}")
                ->and(str_contains((string) $unavailable['description'], $storeOutage))->toBeTrue("{$method} {$path}")
                ->and(array_keys($unavailable['content']))->toBe(['application/problem+json'], "{$method} {$path}")
                ->and($unavailable['headers']['Retry-After']['required'])->toBeTrue("{$method} {$path}")
                ->and($unavailable['headers']['Retry-After']['schema'])->toBe(['type' => 'integer'], "{$method} {$path}");
        }
    }

    expect($unlimited)->toBe(['getReady'])
        ->and($limited)->toBe(24);
});
