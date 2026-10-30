<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// failed_jobs and job_batches are kept 30 days (720 h), then pruned once a day by the framework's own commands; the
// only other scheduled prune is model:prune for superseded runs' chunks (IngestChunkRetentionTest).

/** The one scheduled event named $name. */
function t06PruneEvent(string $name): Event
{
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e): bool => $e->description === $name)->values();
    expect($events)->toHaveCount(1);

    return $events->first();
}

it('prunes failed jobs older than 720 hours once a day as pokeprices-prune-failed', function (): void {
    $event = t06PruneEvent('pokeprices-prune-failed');

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->command)->toEndWith("'artisan' queue:prune-failed --hours=720");
});

it('prunes job batches older than 720 hours once a day as pokeprices-prune-batches', function (): void {
    $event = t06PruneEvent('pokeprices-prune-batches');

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->command)->toEndWith("'artisan' queue:prune-batches --hours=720");
});

it('schedules no other prune command', function (): void {
    $prunes = collect(app(Schedule::class)->events())
        ->map(fn (Event $e): string => (string) $e->command)
        ->filter(fn (string $command): bool => str_contains($command, 'prune'))
        ->map(fn (string $command): string => (string) preg_replace('/^.*\'artisan\' /', '', $command))
        ->sort()->values()->all();

    expect($prunes)->toBe(["model:prune --model='App\\Domain\\Pricing\\Models\\IngestRunChunk'", 'queue:prune-batches --hours=720', 'queue:prune-failed --hours=720']);
});
