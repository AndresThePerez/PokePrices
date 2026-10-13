<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

const READY_POKESEARCH_KEY = 'pokeprices:ready:pokesearch';

beforeEach(function (): void {
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081']);
    resetReadinessCache();
});

afterEach(function (): void {
    resetReadinessCache();
});

it('reports pokesearch ok when healthz answers 200 with status ok', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect($response->json())->toBe([
        'status' => 'ok',
        'checks' => ['database' => 'ok', 'redis_queue' => 'ok', 'redis_cache' => 'ok', 'pokesearch' => 'ok'],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'http://192.0.2.10:8081/healthz');
});

it('answers 503 problem+json when healthz answers 500', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 500)]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'not-ready')
        ->and($response->json('checks'))->toBe(['database' => 'ok', 'redis_queue' => 'ok', 'redis_cache' => 'ok', 'pokesearch' => 'fail']);
    Http::assertSentCount(1);
});

it('fails when healthz answers 200 without status ok', function (mixed $body): void {
    Http::fake(['*' => Http::response($body, 200)]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks.pokesearch'))->toBe('fail');
})->with([
    'wrong status' => [['status' => 'degraded']],
    'no status' => [['up' => true]],
    'not json' => ['<html>ok</html>'],
]);

it('fails on a connection exception and does not leak its message', function (): void {
    $asked = 0;
    Http::fake(['*' => function () use (&$asked): never {
        $asked++;

        throw new ConnectionException('sentinel-connect-detail');
    }]);
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks.pokesearch'))->toBe('fail')
        ->and($response->getContent())->not->toContain('sentinel-connect-detail')
        ->and($asked)->toBe(1);
});

it('asks healthz once for two calls within 5 seconds and holds the result under a TTL of at most 5 s', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $this->get('/v1/ready')->assertOk();
    $this->get('/v1/ready')->assertOk();
    Http::assertSentCount(1);
    $ttl = Redis::connection('cache')->ttl(Cache::store('redis')->getPrefix().READY_POKESEARCH_KEY);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
});

it('caches a failure for the same window', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    $this->get('/v1/ready')->assertStatus(503);
    $this->get('/v1/ready')->assertStatus(503);
    Http::assertSentCount(1);
});

it('asks healthz again once the cached result is gone', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $this->get('/v1/ready')->assertOk();
    Cache::store('redis')->forget(READY_POKESEARCH_KEY);
    $this->get('/v1/ready')->assertOk();
    Http::assertSentCount(2);
});

it('uses its own 1 s client with a 1 s connect timeout and no retry', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response('', 500);
    });
    $this->get('/v1/ready')->assertStatus(503);
    expect($seen)->toHaveCount(1)
        ->and($seen[0]['connect_timeout'])->toEqual(1)
        ->and($seen[0]['timeout'])->toEqual(1);
});

it('does not call healthz or fail the endpoint when the cache store is down, other checks still report', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    config(['database.redis.cache.host' => '192.0.2.10']);
    Redis::purge('cache');
    $started = microtime(true);
    $response = $this->get('/v1/ready');
    $elapsed = microtime(true) - $started;
    Redis::purge('cache');
    $response->assertStatus(503);
    expect($response->json('checks.redis_cache'))->toBe('fail')
        ->and($response->json('checks'))->toHaveKey('pokesearch')
        ->and($elapsed)->toBeLessThan(2.0);
});

it('prints one line per check and exits 0 when all are ok', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $code = Artisan::call('pokeprices:ready-probe');
    expect($code)->toBe(0)
        ->and(Artisan::output())->toBe("database: ok\nredis_queue: ok\nredis_cache: ok\npokesearch: ok\n");
});

it('exits non-zero and prints pokesearch: fail when healthz answers 500', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    $code = Artisan::call('pokeprices:ready-probe');
    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain("database: ok\n")->toContain("pokesearch: fail\n");
});

it('bypasses the 5 s cache in both directions', function (): void {
    $upstream = 200;
    Http::fake(['*' => function () use (&$upstream) {
        return Http::response(['status' => 'ok'], $upstream);
    }]);
    Cache::store('redis')->put(READY_POKESEARCH_KEY, 'fail', 5);
    $code = Artisan::call('pokeprices:ready-probe');
    expect($code)->toBe(0)->and(Artisan::output())->toContain("pokesearch: ok\n");
    Http::assertSentCount(1);

    Cache::store('redis')->put(READY_POKESEARCH_KEY, 'ok', 5);
    $upstream = 500;
    $code = Artisan::call('pokeprices:ready-probe');
    expect($code)->not->toBe(0)->and(Artisan::output())->toContain("pokesearch: fail\n");
});

it('finishes within 2 seconds and prints pokesearch: fail against an unroutable address over a real socket', function (): void {
    config(['services.pokesearch.url' => 'http://192.0.2.1:9']);
    $started = microtime(true);
    $code = Artisan::call('pokeprices:ready-probe');
    $elapsed = microtime(true) - $started;
    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain("pokesearch: fail\n")
        ->and($elapsed)->toBeLessThanOrEqual(2.0);
});

it('applies the pokesearch timeouts from configuration', function (): void {
    expect(config('services.pokesearch.timeouts.readiness.connect'))->toEqual(1)
        ->and(config('services.pokesearch.timeouts.readiness.total'))->toEqual(1);
});
