<?php

declare(strict_types=1);

use App\Support\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Forgets the cached PokéSearch readiness verdict, then drops the resolved Redis manager and cache store so a later
 * config() change to a Redis host still takes effect (the manager reads its config once, when it is first resolved).
 */
function resetReadinessCache(): void
{
    config(['database.redis.cache.host' => env('REDIS_CACHE_HOST', 'redis-cache')]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    Cache::forgetDriver('redis');
    Cache::store('redis')->forget(Readiness::POKESEARCH_CACHE_KEY);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    Cache::forgetDriver('redis');
}
