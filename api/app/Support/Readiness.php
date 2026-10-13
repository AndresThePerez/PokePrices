<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Readiness probes for GET /v1/ready: Postgres, both Redis roles and PokéSearch.
 *
 * The PokéSearch probe has its own client (connect 1 s, total 1 s, no retry, no breaker). Its verdict is held
 * 5 s on the redis-cache store so a probe storm costs PokéSearch one request per window; the ready-probe
 * command skips that cache.
 */
final class Readiness
{
    public const POKESEARCH_CACHE_KEY = 'pokeprices:ready:pokesearch';

    public const POKESEARCH_CACHE_SECONDS = 5;

    private bool $useCache = true;

    /** Set once the redis-cache check failed, so a dead cache store is not asked again within one run. */
    private bool $cacheDown = false;

    /** @var array<string, Closure(): void> */
    private readonly array $checks;

    /** @param array<string, Closure(): void>|null $checks */
    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? [
            'database' => static function (): void {
                DB::connection()->select('select 1');
            },
            'redis_queue' => static function (): void {
                Redis::connection('default')->ping();
            },
            'redis_cache' => static function (): void {
                Redis::connection('cache')->ping();
            },
            'pokesearch' => fn () => $this->probePokeSearch(),
        ];
    }

    /**
     * @param  bool  $useCache  false skips the 5 s PokéSearch cache (the ready-probe command)
     * @return array<string, 'ok'|'fail'>
     */
    public function run(bool $useCache = true): array
    {
        $this->useCache = $useCache;
        $this->cacheDown = false;
        $results = [];
        foreach ($this->checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable) {
                $results[$name] = 'fail';
                if ($name === 'redis_cache') {
                    $this->cacheDown = true;
                }
            }
        }

        return $results;
    }

    /** @throws Throwable when PokéSearch is not ready, or when the cached verdict says so */
    private function probePokeSearch(): void
    {
        $cache = $this->useCache && ! $this->cacheDown ? Cache::store('redis') : null;
        $verdict = null;
        if ($cache !== null) {
            try {
                $verdict = $cache->get(self::POKESEARCH_CACHE_KEY);
            } catch (Throwable) {
                $this->cacheDown = true;
                $cache = null;
            }
        }
        if ($verdict !== 'ok' && $verdict !== 'fail') {
            $verdict = $this->askPokeSearch() ? 'ok' : 'fail';
            if ($cache !== null) {
                try {
                    $cache->put(self::POKESEARCH_CACHE_KEY, $verdict, self::POKESEARCH_CACHE_SECONDS);
                } catch (Throwable) {
                    $this->cacheDown = true;
                }
            }
        }
        if ($verdict !== 'ok') {
            throw new RuntimeException('pokesearch not ready');
        }
    }

    /** One GET /healthz; true only for a 200 whose JSON body says status ok. Never throws. */
    private function askPokeSearch(): bool
    {
        $url = config('services.pokesearch.url');
        $connect = config('services.pokesearch.timeouts.readiness.connect', 1);
        $total = config('services.pokesearch.timeouts.readiness.total', 1);
        if (! is_string($url) || $url === '' || ! is_numeric($connect) || ! is_numeric($total)) {
            return false;
        }
        try {
            $response = Http::connectTimeout((float) $connect)
                ->timeout((float) $total)
                ->acceptJson()
                ->get(rtrim($url, '/').'/healthz');

            return $response->status() === 200 && $response->json('status') === 'ok';
        } catch (Throwable) {
            return false;
        }
    }
}
