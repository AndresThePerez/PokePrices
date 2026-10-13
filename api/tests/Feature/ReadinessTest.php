<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Support\Readiness;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    resetReadinessCache();
});

it('reports ready with every real dependency up', function (): void {
    $response = $this->get('/v1/ready');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json())->toBe([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis_queue' => 'ok', 'redis_cache' => 'ok', 'pokesearch' => 'ok'],
        ]);
});

it('answers 503 problem+json naming the failed check without leaking the error', function (): void {
    $this->app->instance(Readiness::class, new Readiness([
        'database' => static function (): void {},
        'redis_queue' => static function (): void {},
        'redis_cache' => static function (): void {
            throw new RuntimeException('sentinel-redis-detail');
        },
    ]));
    $response = $this->get('/v1/ready');
    $response->assertStatus(503);
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'not-ready')
        ->and($response->json('checks'))->toBe(['database' => 'ok', 'redis_queue' => 'ok', 'redis_cache' => 'fail'])
        ->and($response->getContent())->not->toContain('sentinel-redis-detail');
});

it('answers 503 within 2 seconds when redis-cache is unreachable', function (): void {
    config(['database.redis.cache.host' => '192.0.2.10']);
    Redis::purge('cache');
    $started = microtime(true);
    $response = $this->get('/v1/ready');
    $elapsed = microtime(true) - $started;
    $response->assertStatus(503);
    expect($elapsed)->toBeLessThan(2.0)
        ->and($response->json('checks.redis_cache'))->toBe('fail');
});

it('rejects writes to the readiness endpoint', function (): void {
    $this->post('/v1/ready')->assertStatus(405);
});
