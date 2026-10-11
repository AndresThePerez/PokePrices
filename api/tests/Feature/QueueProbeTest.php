<?php

declare(strict_types=1);

use App\Jobs\ProbeJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

it('writes its marker to the cache store with a TTL', function (): void {
    $token = (string) Str::ulid();
    (new ProbeJob($token))->handle();
    expect(Cache::store('redis')->get('pokeprices:probe:'.$token))->toBeString();
    Cache::store('redis')->forget('pokeprices:probe:'.$token);
});

it('dispatches the probe on the redis queue and fails when nothing processes it', function (): void {
    Bus::fake();
    $this->artisan('pokeprices:queue-probe', ['--timeout' => 1])->assertExitCode(1);
    Bus::assertDispatched(ProbeJob::class, fn (ProbeJob $job): bool => $job->connection === 'redis' && $job->queue === 'default');
});

it('succeeds as soon as the marker exists', function (): void {
    Bus::fake();
    $token = (string) Str::ulid();
    (new ProbeJob($token))->handle();
    $this->artisan('pokeprices:queue-probe', ['--timeout' => 1, '--token' => $token])->assertExitCode(0);
    Cache::store('redis')->forget('pokeprices:probe:'.$token);
});

it('schedules the probe every minute under a stable name', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e): bool => $e->description === 'pokeprices-probe');
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
