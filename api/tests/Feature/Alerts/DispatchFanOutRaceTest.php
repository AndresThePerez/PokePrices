<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Catalog\CatalogRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan Q1-i5 T06 (B8): the catalog read sits between the "missing" SELECTs and the INSERTs. An endpoint or a person
// deleted in that gap is skipped; every other delivery of the run is still created and the run throws nothing.

const T06R_VARIANT = 'base1-4:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    AlertRows::runs('2026-10-11', '2026-10-12');
    $this->event = AlertRows::event(T06R_VARIANT, '2026-10-12');
});

afterEach(function (): void {
    Event::forget(RequestSending::class);
    CatalogCache::flush($this->catalogPrefix);
});

/**
 * A verified user watching the variant at a 5 % threshold, with the digest on the given channel.
 */
function t06rWatcher(string $email, string $channel = 'email'): User
{
    $user = AlertRows::user($email);
    AccountRows::watch($user, T06R_VARIANT);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 5]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => $channel, 'digest' => true]);

    return $user;
}

/**
 * Runs $gap during the catalog read (between the missing SELECTs and the INSERTs), then one dispatch run.
 *
 * @return array<string, mixed>
 */
function t06rRunWith(Closure $gap): array
{
    Event::listen(RequestSending::class, $gap);

    return DispatchEvents::run(DB::connection(), app(CatalogRepository::class), CarbonImmutable::now());
}

/**
 * Every delivery as [kind, user_id, endpoint_id], ordered.
 *
 * @return list<array{0: string, 1: int, 2: int|null}>
 */
function t06rDeliveries(): array
{
    $rows = DB::select('SELECT kind, user_id, endpoint_id FROM deliveries ORDER BY kind, user_id, endpoint_id');

    return array_map(fn (object $r): array => [(string) $r->kind, (int) $r->user_id, $r->endpoint_id === null ? null : (int) $r->endpoint_id], $rows);
}

it('skips an endpoint deleted during the catalog read and creates every other price.moved delivery', function (): void {
    $a = AlertRows::user('a@example.com');
    AccountRows::watch($a, T06R_VARIANT);
    $ea = AlertRows::endpoint($a);
    $b = AlertRows::user('b@example.com');
    AccountRows::watch($b, T06R_VARIANT);
    $gone = AlertRows::endpoint($b);
    $kept = AlertRows::endpoint($b);

    $summary = t06rRunWith(function () use ($gone): void {
        DB::table('webhook_endpoints')->where('id', $gone)->delete();
    });

    expect(AlertRows::moved())->toBe([[$this->event, $ea], [$this->event, $kept]])
        ->and(DB::table('deliveries')->where('endpoint_id', $gone)->count())->toBe(0)
        ->and(DB::table('webhook_endpoints')->where('id', $gone)->exists())->toBeFalse()
        ->and($summary['created'])->toBe(['price.moved' => 2, 'alert.triggered' => 0, 'digest' => 0])
        ->and($summary['skipped'])->toBeNull();
});

it('skips an alert whose Discord endpoint was deleted during the catalog read and creates the rest', function (): void {
    $mail = t06rWatcher('mail@example.com');
    $discord = t06rWatcher('discord@example.com', 'discord');
    $gone = AlertRows::endpoint($discord, 'discord');

    $summary = t06rRunWith(function () use ($gone): void {
        DB::table('webhook_endpoints')->where('id', $gone)->delete();
    });

    expect(t06rDeliveries())->toBe([
        ['alert.triggered', (int) $mail->id, null],
        ['digest', (int) $mail->id, null],
    ])
        ->and(DB::table('deliveries')->where('endpoint_id', $gone)->count())->toBe(0)
        ->and($summary['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 1, 'digest' => 1]);
});

it('skips a person deleted during the catalog read and creates everyone else\'s deliveries', function (): void {
    $a = t06rWatcher('a@example.com');
    $ea = AlertRows::endpoint($a);
    $b = t06rWatcher('b@example.com');
    AlertRows::endpoint($b);
    $c = t06rWatcher('c@example.com');
    $ec = AlertRows::endpoint($c);
    $goneId = (int) $b->id;

    $summary = t06rRunWith(function () use ($goneId): void {
        DB::table('users')->where('id', $goneId)->delete();
    });

    expect(t06rDeliveries())->toBe([
        ['alert.triggered', (int) $a->id, null],
        ['alert.triggered', (int) $c->id, null],
        ['digest', (int) $a->id, null],
        ['digest', (int) $c->id, null],
        ['price.moved', (int) $a->id, $ea],
        ['price.moved', (int) $c->id, $ec],
    ])
        ->and(DB::table('deliveries')->where('user_id', $goneId)->count())->toBe(0)
        ->and(DB::table('users')->where('id', $goneId)->exists())->toBeFalse()
        ->and($summary['created'])->toBe(['price.moved' => 2, 'alert.triggered' => 2, 'digest' => 2]);
});

it('creates nothing twice when the next run finds the same gap closed', function (): void {
    $a = t06rWatcher('a@example.com');
    AlertRows::endpoint($a);
    $b = t06rWatcher('b@example.com');
    $gone = AlertRows::endpoint($b);

    t06rRunWith(function () use ($gone): void {
        DB::table('webhook_endpoints')->where('id', $gone)->delete();
    });
    Event::forget(RequestSending::class);
    $second = DispatchEvents::run(DB::connection(), app(CatalogRepository::class), CarbonImmutable::now());

    expect($second['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(DB::table('deliveries')->count())->toBe(5);
});
