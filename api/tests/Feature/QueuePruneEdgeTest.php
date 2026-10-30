<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Plan Q1-i5 T06 review (HG-45 #1): the scheduled prunes delete exactly the framework's finished, older-than-720-hour
// bookkeeping rows and nothing else, and fire once a day. Everything runs on the test database (RefreshDatabase).

/** @return array<string, ScheduledEvent> the scheduled events by name */
function t06pEvents(): array
{
    $events = [];
    foreach (app(Schedule::class)->events() as $event) {
        $events[(string) $event->description] = $event;
    }

    return $events;
}

/** Runs a scheduled prune's artisan name and options in-process, as parsed from the scheduled command string. */
function t06pRun(string $name): int
{
    $event = t06pEvents()[$name] ?? null;
    expect($event)->toBeInstanceOf(ScheduledEvent::class);
    preg_match('/(queue:prune-[a-z]+)\'? \'?--hours=(\d+)\'?\s*$/', (string) $event->command, $m);
    expect($m)->not->toBe([]);

    return Artisan::call($m[1], ['--hours' => (int) $m[2]]);
}

function t06pFailed(string $uuid, CarbonImmutable $at): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'example', 'payload' => '{}', 'exception' => 'example', 'failed_at' => $at->toDateTimeString(),
    ]);
}

function t06pBatch(string $id, int $created, ?int $finished): void
{
    DB::table('job_batches')->insert([
        'id' => $id, 'name' => 'example', 'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]',
        'options' => null, 'cancelled_at' => null, 'created_at' => $created, 'finished_at' => $finished,
    ]);
}

it('deletes only failed jobs older than 720 hours and touches no other table', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    t06pFailed('00000000-0000-0000-0000-000000000719', $now->subHours(719));
    t06pFailed('00000000-0000-0000-0000-000000000721', $now->subHours(721));
    t06pFailed('00000000-0000-0000-0000-000000009999', $now->subYears(3));
    t06pFailed('00000000-0000-0000-0000-000000000001', $now);
    t06pBatch('batch-old-finished', $now->subHours(900)->getTimestamp(), $now->subHours(800)->getTimestamp());
    DB::table('jobs')->insert(['queue' => 'example', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);
    $before = [DB::table('job_batches')->count(), DB::table('jobs')->count(), DB::table('users')->count(), DB::table('ingest_runs')->count()];

    $code = t06pRun('pokeprices-prune-failed');

    expect($code)->toBe(0)
        ->and(DB::table('failed_jobs')->orderBy('uuid')->pluck('uuid')->all())
        ->toBe(['00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000719'])
        ->and([DB::table('job_batches')->count(), DB::table('jobs')->count(), DB::table('users')->count(), DB::table('ingest_runs')->count()])->toBe($before);
});

it('deletes only finished job batches older than 720 hours and leaves unfinished ones and failed jobs alone', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    t06pBatch('batch-719', $now->subHours(800)->getTimestamp(), $now->subHours(719)->getTimestamp());
    t06pBatch('batch-721', $now->subHours(800)->getTimestamp(), $now->subHours(721)->getTimestamp());
    t06pBatch('batch-unfinished-ancient', $now->subYears(2)->getTimestamp(), null);
    t06pFailed('00000000-0000-0000-0000-000000009998', $now->subYears(3));

    $code = t06pRun('pokeprices-prune-batches');

    expect($code)->toBe(0)
        ->and(DB::table('job_batches')->orderBy('id')->pluck('id')->all())->toBe(['batch-719', 'batch-unfinished-ancient'])
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('is due once a day at midnight and not on any other minute, beside the minute jobs that stay as they were', function (): void {
    $events = t06pEvents();
    $due = function (string $at) use ($events): array {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));

        return array_keys(array_filter($events, fn (ScheduledEvent $e): bool => $e->isDue(app())));
    };

    $midnight = $due('2026-10-16 00:00:00');
    $afterMidnight = $due('2026-10-16 00:01:00');
    $noon = $due('2026-10-16 12:00:00');

    expect($midnight)->toContain('pokeprices-prune-failed')->toContain('pokeprices-prune-batches')
        ->and($afterMidnight)->not->toContain('pokeprices-prune-failed')->not->toContain('pokeprices-prune-batches')
        ->and($noon)->not->toContain('pokeprices-prune-failed')->not->toContain('pokeprices-prune-batches')
        ->and($afterMidnight)->toContain('pokeprices-deliveries')->toContain('pokeprices-probe')
        ->and(array_keys($events))->toHaveCount(8);
});
