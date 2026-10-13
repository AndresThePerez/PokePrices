<?php

declare(strict_types=1);

use App\Domain\Catalog\CircuitBreaker;
use App\Support\Readiness;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081']);
    resetReadinessCache();
    $locks = Cache::store(CircuitBreaker::STORE);
    foreach (['', ':open', ':since', ':probe'] as $part) {
        $locks->forget('breaker:pokesearch'.$part);
    }
});

afterEach(function (): void {
    resetReadinessCache();
    $locks = Cache::store(CircuitBreaker::STORE);
    foreach (['', ':open', ':since', ':probe'] as $part) {
        $locks->forget('breaker:pokesearch'.$part);
    }
});

it('does not let an open request breaker make readiness report pokesearch down', function (): void {
    Cache::store(CircuitBreaker::STORE)->put('breaker:pokesearch:open', Carbon::now()->getTimestamp() + 30, 60);
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect($response->json('checks.pokesearch'))->toBe('ok');
    Http::assertSentCount(1);
});

it('does not trip or count against the request breaker when readiness sees pokesearch fail', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    $locks = Cache::store(CircuitBreaker::STORE);
    for ($i = 0; $i < 7; $i++) {
        Cache::store('redis')->forget(Readiness::POKESEARCH_CACHE_KEY);
        $this->get('/v1/ready')->assertStatus(503);
    }
    Http::assertSentCount(7);
    expect($locks->get('breaker:pokesearch'))->toBeNull()
        ->and($locks->get('breaker:pokesearch:open'))->toBeNull()
        ->and($locks->get('breaker:pokesearch:since'))->toBeNull();
});

it('sends no api key and no credential header on the healthz call even when a key is configured', function (): void {
    config(['services.pokesearch.api_key' => 'sentinel-0000000000000000']);
    $headers = null;
    Http::fake(function (Request $request) use (&$headers) {
        $headers = $request->headers();

        return Http::response(['status' => 'ok'], 200);
    });
    $this->get('/v1/ready')->assertOk();
    $lower = array_change_key_case($headers ?? [], CASE_LOWER);
    expect($headers)->not->toBeNull()
        ->and($lower)->not->toHaveKey('x-api-key')
        ->and($lower)->not->toHaveKey('authorization')
        ->and(json_encode($headers))->not->toContain('sentinel-0000000000000000');
});

it('reports a recovered pokesearch ok once the 5 s window of a cached failure has passed', function (): void {
    $upstream = 500;
    Http::fake(['*' => function () use (&$upstream) {
        return Http::response(['status' => 'ok'], $upstream);
    }]);
    $this->get('/v1/ready')->assertStatus(503);
    $ttl = Redis::connection('cache')->ttl(Cache::store('redis')->getPrefix().Readiness::POKESEARCH_CACHE_KEY);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(5);

    $upstream = 200;
    $this->get('/v1/ready')->assertStatus(503);
    Http::assertSentCount(1);

    usleep(5_500_000);
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect($response->json('checks.pokesearch'))->toBe('ok');
    Http::assertSentCount(2);
});

it('treats a hostile healthz answer as fail', function (int $status, mixed $body): void {
    Http::fake(['*' => Http::response($body, $status, ['Location' => 'http://192.0.2.11/healthz'])]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks.pokesearch'))->toBe('fail');
})->with([
    'redirect with ok body' => [302, ['status' => 'ok']],
    '204 no content' => [204, ''],
    'status true' => [200, ['status' => true]],
    'status upper case' => [200, ['status' => 'OK']],
    'status array' => [200, ['status' => ['ok']]],
    'status nested' => [200, ['data' => ['status' => 'ok']]],
    'top level list' => [200, ['ok']],
    'json null' => [200, 'null'],
    'json string' => [200, '"ok"'],
]);

it('treats a huge non-json 200 body as fail without error', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 5_000_000), 200)]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks.pokesearch'))->toBe('fail');
});

it('ignores a garbage cached verdict and asks healthz', function (): void {
    Cache::store('redis')->put(Readiness::POKESEARCH_CACHE_KEY, 'maybe', 5);
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect($response->json('checks.pokesearch'))->toBe('ok');
    Http::assertSentCount(1);
});

it('reports fail without calling out when the pokesearch url is empty or missing', function (mixed $url): void {
    config(['services.pokesearch.url' => $url]);
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks.pokesearch'))->toBe('fail');
    Http::assertNothingSent();
})->with([
    'empty string' => [''],
    'null' => [null],
]);

it('keeps no-store and sets no etag on a 503 readiness answer', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('ETag'))->toBeNull();
});

it('keeps the verdict key on the redis-cache connection only, not on redis-queue', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $this->get('/v1/ready')->assertOk();
    $key = Cache::store('redis')->getPrefix().Readiness::POKESEARCH_CACHE_KEY;
    expect(Redis::connection('cache')->exists($key))->toBe(1)
        ->and(Redis::connection('default')->exists($key))->toBe(0);
});

it('exits non-zero and prints the failing line when only the database check fails', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    app()->instance(Readiness::class, new Readiness([
        'database' => static function (): void {
            throw new RuntimeException('db down');
        },
        'redis_queue' => static function (): void {},
        'redis_cache' => static function (): void {},
        'pokesearch' => static function (): void {},
    ]));
    $code = Artisan::call('pokeprices:ready-probe');
    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toBe("database: fail\nredis_queue: ok\nredis_cache: ok\npokesearch: ok\n");
});
