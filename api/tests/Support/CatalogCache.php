<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Redis as PhpRedis;

/**
 * Gives a Feature test its own slice of the real Redis the catalog cache uses (plan T42).
 *
 * The cache prefix becomes unique to the parallel-test token and the test, so no test reads another's catalog
 * keys, and flush() deletes every key under it from both Redis roles afterwards.
 */
final class CatalogCache
{
    /** Points the redis and locks cache stores at a fresh prefix and returns it. */
    public static function isolate(): string
    {
        $token = ParallelTesting::token();
        $prefix = 'pokeprices-test-'.(is_string($token) && $token !== '' ? $token : 'serial').'-'.Str::lower((string) Str::ulid()).':';
        config(['cache.prefix' => $prefix]);
        Cache::forgetDriver(['redis', 'locks']);

        return $prefix;
    }

    /**
     * Every key under the prefix on one Redis connection ("cache" or "default"), as the cache store names it
     * (cache prefix included, connection prefix stripped), sorted.
     *
     * @return list<string>
     */
    public static function keys(string $connection, string $prefix): array
    {
        $client = Redis::connection($connection)->client();
        $connectionPrefix = (string) $client->getOption(PhpRedis::OPT_PREFIX);
        $keys = array_map(
            fn (string $key): string => substr($key, strlen($connectionPrefix)),
            (array) $client->keys($prefix.'*'),
        );
        sort($keys);

        return $keys;
    }

    public static function flush(string $prefix): void
    {
        foreach (['cache', 'default'] as $connection) {
            foreach (self::keys($connection, $prefix) as $key) {
                Redis::connection($connection)->del($key);
            }
        }
    }

    /**
     * Caches the seed ref through the repository against a faked /api/meta, then leaves a fresh fake factory
     * that refuses stray requests, so the test's own fakes and request counts start from zero.
     */
    public static function primeSeedRef(string $baseUrl, ?string $ref = '0123456789abcdef0123456789abcdef01234567'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([$baseUrl.'/api/meta' => Http::response([
            'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 1,
            'seed' => $ref === null ? null : ['ref' => $ref, 'seeded_at' => '2026-10-11T20:05:45Z'],
            'request_id' => 'prime-seed-ref',
        ])]);
        app(CatalogRepository::class)->seedRef();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }
}
