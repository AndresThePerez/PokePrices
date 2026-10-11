<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class QueueProbe extends Command
{
    protected $signature = 'pokeprices:queue-probe {--timeout=10 : Seconds to wait} {--token= : Reuse a known token}';

    protected $description = 'Dispatch a probe job and wait until the PokéPrices worker has processed it.';

    public function handle(): int
    {
        $token = is_string($this->option('token')) && $this->option('token') !== '' ? $this->option('token') : (string) Str::ulid();
        $deadline = microtime(true) + max(1, (int) $this->option('timeout'));
        ProbeJob::dispatch($token);
        while (microtime(true) < $deadline) {
            if (Cache::store('redis')->get('pokeprices:probe:'.$token) !== null) {
                $this->info('probe processed');

                return self::SUCCESS;
            }
            usleep(200_000);
        }
        $this->error('probe not processed before the timeout');

        return self::FAILURE;
    }
}
