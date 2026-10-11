<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function edgeRunId(string $connection): string
{
    /** @var array<string, mixed> $info */
    $info = Redis::connection($connection)->info('server');
    $server = $info['Server'] ?? $info;
    $runId = is_array($server) ? ($server['run_id'] ?? '') : '';

    return is_string($runId) ? $runId : '';
}

/**
 * @return list<string>
 */
function edgeKeysOn(string $connection, string $needle): array
{
    /** @var list<string> $keys */
    $keys = Redis::connection($connection)->client()->keys('*'.$needle.'*');

    return $keys;
}

it('runs the default and cache connections against two distinct Redis servers', function (): void {
    $queue = edgeRunId('default');
    $cache = edgeRunId('cache');

    expect($queue)->not->toBe('')
        ->and($cache)->not->toBe('')
        ->and($queue)->not->toBe($cache)
        ->and(config('database.redis.default.host'))->not->toBe(config('database.redis.cache.host'));
});

it('stores cache data only on the redis-cache server and locks only on redis-queue', function (): void {
    $id = (string) Str::ulid();

    Cache::store('redis')->put('pp-edge-cache-'.$id, 'v', 60);
    $lock = Cache::store('redis')->lock('pp-edge-lock-'.$id, 30);
    expect($lock->get())->toBeTrue();

    $queueCacheKeys = edgeKeysOn('default', 'pp-edge-cache-'.$id);
    $cacheCacheKeys = edgeKeysOn('cache', 'pp-edge-cache-'.$id);
    $queueLockKeys = edgeKeysOn('default', 'pp-edge-lock-'.$id);
    $cacheLockKeys = edgeKeysOn('cache', 'pp-edge-lock-'.$id);

    $lock->release();
    Cache::store('redis')->forget('pp-edge-cache-'.$id);

    expect($cacheCacheKeys)->toHaveCount(1)
        ->and($queueCacheKeys)->toBe([])
        ->and($queueLockKeys)->toHaveCount(1)
        ->and($cacheLockKeys)->toBe([]);
});

it('keeps the locks store and the rate limiter counters on redis-queue and never on redis-cache', function (): void {
    $id = (string) Str::ulid();
    $name = 'pp-edge-limit-'.$id;

    $lock = Cache::store('locks')->lock('pp-edge-locks-'.$id, 30);
    expect($lock->get())->toBeTrue();

    $limiter = resolve(RateLimiter::class);
    $limiter->hit($name, 60);
    $attempts = (int) $limiter->attempts($name);

    $queueLimiter = edgeKeysOn('default', $name);
    $cacheLimiter = edgeKeysOn('cache', $name);
    $queueLock = edgeKeysOn('default', 'pp-edge-locks-'.$id);
    $cacheLock = edgeKeysOn('cache', 'pp-edge-locks-'.$id);

    $limiter->clear($name);
    $lock->release();

    expect($attempts)->toBe(1)
        ->and($queueLimiter)->not->toBe([])
        ->and($cacheLimiter)->toBe([])
        ->and($queueLock)->toHaveCount(1)
        ->and($cacheLock)->toBe([]);
});

it('keeps every .env.example assignment free of secrets, inline comments and stray whitespace', function (): void {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [];
    $seen = [];

    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        expect($line)->toMatch('/^[A-Z][A-Z0-9_]*=/');
        [$key, $value] = explode('=', $line, 2);
        $seen[] = $key;

        expect($value)->not->toContain(' #')
            ->and($value)->toBe(trim($value));

        if (preg_match('/(KEY|PASSWORD|SECRET|TOKEN)$/', $key) === 1 && $key !== 'REDIS_PASSWORD') {
            expect($value)->toBe('');
        }

        expect($value)->not->toMatch('/^base64:/')
            ->and($value)->not->toMatch('/[A-Za-z0-9+\/=_-]{32,}/')
            ->and($value)->not->toMatch('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
            ->and($value)->not->toContain('/'.'home'.'/');
    }

    expect($seen)->toBe(array_values(array_unique($seen)))
        ->and(env('REDIS_PASSWORD'))->toBeNull();
});

it('names the Composer package pokeprices/api and describes it as PokéPrices', function (): void {
    /** @var array{name: string, description: string} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['name'])->toBe('pokeprices/api')
        ->and($composer['description'])->toContain('PokéPrices')
        ->and($composer['description'])->not->toContain('Laravel');
});

it('pins brianium/paratest to exactly v7.26.0 in composer.lock', function (): void {
    /** @var array{packages-dev: list<array{name: string, version: string}>} $lock */
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
    $versions = array_column($lock['packages-dev'], 'version', 'name');

    expect($versions['brianium/paratest'] ?? null)->toBe('v7.26.0');
});
