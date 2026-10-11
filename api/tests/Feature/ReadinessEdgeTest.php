<?php

declare(strict_types=1);

use App\Support\Readiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('answers 503 within 2 seconds when postgres is unreachable', function (): void {
    config(['database.connections.pgsql.host' => '192.0.2.10']);
    DB::purge();
    $started = microtime(true);
    $response = $this->get('/v1/ready');
    $elapsed = microtime(true) - $started;
    config(['database.connections.pgsql.host' => env('DB_HOST')]);
    DB::purge();
    $response->assertStatus(503);
    expect($elapsed)->toBeLessThan(2.0)
        ->and($response->json('checks.database'))->toBe('fail');
});

it('answers 503 within 2 seconds when redis-queue is unreachable', function (): void {
    config(['database.redis.default.host' => '192.0.2.10']);
    Redis::purge('default');
    $started = microtime(true);
    $response = $this->get('/v1/ready');
    $elapsed = microtime(true) - $started;
    Redis::purge('default');
    $response->assertStatus(503);
    expect($elapsed)->toBeLessThan(2.0)
        ->and($response->json('checks.redis_queue'))->toBe('fail');
});

it('answers 503 within 2 seconds with all three dependencies unreachable (budget 1 + 0.5 + 0.5)', function (): void {
    config([
        'database.connections.pgsql.host' => '192.0.2.10',
        'database.redis.default.host' => '192.0.2.10',
        'database.redis.cache.host' => '192.0.2.10',
    ]);
    DB::purge();
    Redis::purge('default');
    Redis::purge('cache');
    $started = microtime(true);
    $response = $this->get('/v1/ready');
    $elapsed = microtime(true) - $started;
    config(['database.connections.pgsql.host' => env('DB_HOST')]);
    DB::purge();
    Redis::purge('default');
    Redis::purge('cache');
    $response->assertStatus(503);
    expect($elapsed)->toBeLessThan(2.5)
        ->and($response->json('checks'))->toBe(['database' => 'fail', 'redis_queue' => 'fail', 'redis_cache' => 'fail']);
});

it('reports fail rather than a 500 when a check throws an Error', function (): void {
    $this->app->instance(Readiness::class, new Readiness([
        'database' => static function (): void {
            throw new TypeError('sentinel-type-error');
        },
    ]));
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->json('checks'))->toBe(['database' => 'fail'])
        ->and($response->getContent())->not->toContain('sentinel-type-error')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->json('request_id'))->not->toBe('');
});

it('keeps the 200 body to status and checks only and sends no-store', function (): void {
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect(array_keys($response->json()))->toBe(['status', 'checks'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api');
});

it('resolves a fresh Readiness per request so config changes take effect', function (): void {
    expect(app(Readiness::class))->not->toBe(app(Readiness::class));
});

it('answers HEAD like GET without a body', function (): void {
    $response = $this->call('HEAD', '/v1/ready');
    $response->assertOk();
    expect($response->getContent())->toBe('');
});

it('applies the bounded timeouts to the real configuration', function (): void {
    expect(config('database.connections.pgsql.options.'.PDO::ATTR_TIMEOUT))->toBe(1)
        ->and(config('database.redis.default.timeout'))->toBe(0.5)
        ->and(config('database.redis.default.read_timeout'))->toBe(0.5)
        ->and(config('database.redis.cache.timeout'))->toBe(0.5)
        ->and(config('database.redis.cache.read_timeout'))->toBe(0.5);
});
