<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the worker consumes redis-queue and writes to redis-cache (TTL on every key, R13). Timeout 30 s, below the
 * redis queue's retry_after (90 s); three tries, as the worker's --tries gave it before (plan Q1-i1 T02).
 */
#[Tries(3)]
#[Timeout(30)]
final class ProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function handle(): void
    {
        Cache::store('redis')->put('pokeprices:probe:'.$this->token, now()->toIso8601String(), 300);
    }
}
