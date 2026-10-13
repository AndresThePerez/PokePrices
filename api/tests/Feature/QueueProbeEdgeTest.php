<?php

declare(strict_types=1);

use App\Jobs\ProbeJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('writes the marker only to redis-cache with a TTL of at most 300 seconds', function (): void {
    $token = 'edge-'.Str::ulid();
    $key = 'pokeprices:probe:'.$token;
    (new ProbeJob($token))->handle();
    $cache = Redis::connection('cache');
    $ttl = (int) $cache->ttl(Cache::store('redis')->getPrefix().$key);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(300)
        ->and(Redis::connection('default')->keys('*'.$key))->toBe([])
        ->and(Redis::connection('cache')->keys('*'.$key))->toHaveCount(1);
    Cache::store('redis')->forget($key);
});

it('never waits less than one second and fails for zero, negative and non-numeric timeouts', function (string $timeout): void {
    Bus::fake();
    $start = microtime(true);
    $this->artisan('pokeprices:queue-probe', ['--timeout' => $timeout, '--token' => 'edge-never-'.Str::ulid()])->assertExitCode(1);
    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeGreaterThanOrEqual(0.95)->toBeLessThan(3.0);
})->with(['0', '-5', 'abc', '']);

it('uses hostile tokens verbatim and does not hang', function (string $token): void {
    Bus::fake();
    $key = 'pokeprices:probe:'.$token;
    (new ProbeJob($token))->handle();
    expect(Cache::store('redis')->get($key))->toBeString();
    $this->artisan('pokeprices:queue-probe', ['--timeout' => 1, '--token' => $token])->assertExitCode(0);
    Bus::assertDispatched(ProbeJob::class, fn (ProbeJob $job): bool => $job->token === $token);
    Cache::store('redis')->forget($key);
})->with([
    'spaces' => ['a b  c'],
    'newline' => ["a\nb"],
    'glob' => ['*'],
    'ten kilobytes' => [str_repeat('x', 10_240)],
]);

// P2-i1 T08 adds the hourly pending price scan beside the probe, and T10 the hourly freshness log line (same-commit
// edits of this P0 count, R186).
it('keeps a single probe schedule entry beside the pending price scan and the freshness log line and removes the inspire command', function (): void {
    expect(collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->description)->sort()->values()->all())
        ->toBe(['pokeprices-freshness', 'pokeprices-ingest-pending', 'pokeprices-probe'])
        ->and(array_key_exists('inspire', Artisan::all()))->toBeFalse();
});
