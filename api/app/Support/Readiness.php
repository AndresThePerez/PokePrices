<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Readiness probes for GET /v1/ready: Postgres and both Redis roles in P0; PokéSearch joins in P1. */
final class Readiness
{
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
        ];
    }

    /** @return array<string, 'ok'|'fail'> */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable) {
                $results[$name] = 'fail';
            }
        }

        return $results;
    }
}
