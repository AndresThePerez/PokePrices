<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// A reset link is good for 60 minutes (config auth.passwords.users.expire); its password_reset_tokens row stays
// until the person asks again or resets. The framework's auth:clear-resets removes the expired rows; the schedule
// runs it once a day as pokeprices-prune-resets, at 03:17 UTC, off the minutes the price jobs run at.

/** The one scheduled event named pokeprices-prune-resets. */
function rtpEvent(): Event
{
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e): bool => $e->description === 'pokeprices-prune-resets')->values();
    expect($events)->toHaveCount(1);

    return $events->first();
}

/** The names of the scheduled events due at $time (UTC), sorted. */
function rtpDueAt(string $time): array
{
    Carbon::setTestNow(Carbon::parse($time, 'UTC'));
    $due = app(Schedule::class)->dueEvents(app())->map(fn (Event $e): string => (string) $e->description)->sort()->values()->all();
    Carbon::setTestNow();

    return $due;
}

it('clears expired password-reset tokens once a day as pokeprices-prune-resets', function (): void {
    $event = rtpEvent();

    expect($event->expression)->toBe('17 3 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->command)->toEndWith("'artisan' auth:clear-resets");
});

it('runs the prune at a minute no price job runs at, and only then', function (): void {
    expect(rtpDueAt('2026-11-23 03:17:00'))->toBe(['pokeprices-deliveries', 'pokeprices-probe', 'pokeprices-prune-resets'])
        ->and(rtpDueAt('2026-11-23 03:40:00'))->not->toContain('pokeprices-prune-resets')
        ->and(rtpDueAt('2026-11-23 03:40:00'))->toContain('pokeprices-ingest-pending')
        ->and(rtpDueAt('2026-11-23 00:00:00'))->not->toContain('pokeprices-prune-resets')
        ->and(rtpDueAt('2026-11-23 04:17:00'))->not->toContain('pokeprices-prune-resets');
});

it('removes a token past its 60 minutes and keeps a live one when the scheduled command runs', function (): void {
    DB::table('password_reset_tokens')->insert([
        ['email' => 'expired@example.com', 'token' => Hash::make('fake-token-0000'), 'created_at' => now()->subMinutes(61)],
        ['email' => 'live@example.com', 'token' => Hash::make('fake-token-1111'), 'created_at' => now()->subMinutes(59)],
    ]);
    $command = (string) preg_replace('/^.*\'artisan\' /', '', (string) rtpEvent()->command);

    $code = Artisan::call($command);

    expect($code)->toBe(0)
        ->and(DB::table('password_reset_tokens')->orderBy('email')->pluck('email')->all())->toBe(['live@example.com']);
});
