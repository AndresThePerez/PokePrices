<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function envExampleValue(string $key): ?string
{
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        if (str_starts_with($line, $key.'=')) {
            return trim(substr($line, strlen($key) + 1), '"');
        }
    }

    return null;
}

it('points the default Redis connection at redis-queue and the cache connection at redis-cache', function (): void {
    expect(config('database.redis.default.host'))->toBe('redis-queue')
        ->and(config('database.redis.cache.host'))->toBe('redis-cache');
});

it('keeps cache data on redis-cache and locks and the rate limiter on redis-queue', function (): void {
    expect(config('cache.stores.redis.connection'))->toBe('cache')
        ->and(config('cache.stores.redis.lock_connection'))->toBe('default')
        ->and(config('cache.stores.locks.driver'))->toBe('redis')
        ->and(config('cache.stores.locks.connection'))->toBe('default')
        ->and(config('cache.limiter'))->toBe('locks');
});

it('declares Redis queue and cache with database sessions in .env.example, with no key', function (): void {
    expect(envExampleValue('QUEUE_CONNECTION'))->toBe('redis')
        ->and(envExampleValue('CACHE_STORE'))->toBe('redis')
        ->and(envExampleValue('SESSION_DRIVER'))->toBe('database')
        ->and(envExampleValue('DB_CONNECTION'))->toBe('pgsql')
        ->and(envExampleValue('APP_NAME'))->toBe('PokéPrices')
        ->and(envExampleValue('APP_KEY'))->toBe('')
        ->and(envExampleValue('DB_PASSWORD'))->toBe('');
});

it('writes cache keys to redis-cache only, with a TTL', function (): void {
    $key = 'pp-roles-'.Str::ulid();
    Cache::store('redis')->put($key, 'v', 60);
    $stored = config('cache.prefix').$key;
    expect(Redis::connection('cache')->exists($stored))->toBe(1)
        ->and(Redis::connection('default')->exists($stored))->toBe(0)
        ->and(Redis::connection('cache')->ttl($stored))->toBeGreaterThan(0);
    Cache::store('redis')->forget($key);
});
