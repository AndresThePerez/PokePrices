<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan P5-i1 T06 criterion 1 (D2, R192, Amendment 1 A01, A09): every R192 scenario of T01 criterion 5, re-run with
// a 5 % threshold on every watched variant and the daily digest on. Alerts and digests read the same deliverable day
// as price.moved: never a repaired older day, a day passed over, an event above the day's ceiling or a future day.

const T6S_A = 'base1-4:holo:-:-';
const T6S_B = 'base1-2:holo:-:-';
const T6S_C = 'base1-7:holo:-:-';
const T6S_D = 'base1-9:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/**
 * An eligible person watching $variants with a 5 % threshold on each and the daily digest on (email).
 *
 * @param  list<string>  $variants
 */
function t6sSubscriber(string $email, array $variants): User
{
    $user = AlertRows::user($email);
    foreach ($variants as $variant) {
        AccountRows::watch($user, $variant);
    }
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 5]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => 'email', 'digest' => true]);

    return $user;
}

/**
 * The alert.triggered deliveries as [user_id, event_id], ordered.
 *
 * @return list<array{0: int, 1: int}>
 */
function t6sAlerts(): array
{
    return array_map(
        fn (object $r): array => [(int) $r->user_id, (int) $r->event_id],
        DB::select("SELECT user_id, event_id FROM deliveries WHERE kind = 'alert.triggered' ORDER BY user_id, event_id"),
    );
}

/**
 * The digests as [user_id, day, card labels of its move lines], ordered.
 *
 * @return list<array{0: int, 1: string, 2: list<string>}>
 */
function t6sDigests(): array
{
    $rows = DB::select("SELECT user_id, day::text AS day, body FROM deliveries WHERE kind = 'digest' ORDER BY user_id, day");

    return array_map(function (object $r): array {
        $lines = explode("\n", (string) json_decode((string) $r->body, true)['text']);
        $moves = array_slice($lines, 1, count($lines) - 2);

        return [(int) $r->user_id, (string) $r->day, array_map(fn (string $l): string => explode(' · ', $l)[0], $moves)];
    }, $rows);
}

test('(a) first start: alerts and the digest only for the newest finalized day', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    AlertRows::event(T6S_A, '2026-10-10');
    AlertRows::event(T6S_B, '2026-10-11');
    $twelveA = AlertRows::event(T6S_A, '2026-10-12');
    $twelveC = AlertRows::event(T6S_C, '2026-10-12');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B, T6S_C]);

    [, $line] = AlertRows::dispatch();

    expect(t6sAlerts())->toBe([[$user->id, $twelveA], [$user->id, $twelveC]])
        ->and(t6sDigests())->toBe([[$user->id, '2026-10-12', ['Name of base1-4', 'Name of base1-7']]])
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 2, 'digest' => 1]);
});

test('(b) crash repair: no alert or digest for a repaired older day, and nothing new once the newest day is back', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    AlertRows::event(T6S_A, '2026-10-10');
    AlertRows::event(T6S_B, '2026-10-11');
    AlertRows::event(T6S_A, '2026-10-12');
    AlertRows::event(T6S_C, '2026-10-12');
    t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B, T6S_C]);
    AlertRows::dispatch();
    $alerts = t6sAlerts();
    $digests = t6sDigests();

    AlertRows::supersedeAll();
    $steps = [];
    $repairEvents = [];
    foreach (['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        AlertRows::run($day);
        if ($day === '2026-10-10' || $day === '2026-10-11') {
            $repairEvents[] = AlertRows::event(T6S_C, $day);
        }
        [, $line] = AlertRows::dispatch();
        $steps[$day] = [$line['created'], t6sAlerts(), t6sDigests()];
    }

    $none = ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0];
    expect($alerts)->toHaveCount(2)
        ->and($digests)->toHaveCount(1)
        ->and($steps)->toBe([
            '2026-10-09' => [$none, $alerts, $digests],
            '2026-10-10' => [$none, $alerts, $digests],
            '2026-10-11' => [$none, $alerts, $digests],
            '2026-10-12' => [$none, $alerts, $digests],
        ])
        ->and(array_intersect($repairEvents, array_column(t6sAlerts(), 1)))->toBe([]);
});

test('(c) scheduler gap: alerts and the digest of the newest day only, never of the day passed over', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $twelve = AlertRows::event(T6S_A, '2026-10-12');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B, T6S_C]);
    AlertRows::dispatch();

    AlertRows::runs('2026-10-13', '2026-10-14');
    $thirteen = AlertRows::event(T6S_B, '2026-10-13');
    $fourteen = AlertRows::event(T6S_C, '2026-10-14');
    AlertRows::dispatch();
    AlertRows::dispatch();

    expect(t6sAlerts())->toBe([[$user->id, $twelve], [$user->id, $fourteen]])
        ->and(array_column(t6sAlerts(), 1))->not->toContain($thirteen)
        ->and(t6sDigests())->toBe([
            [$user->id, '2026-10-12', ['Name of base1-4']],
            [$user->id, '2026-10-14', ['Name of base1-7']],
        ]);
});

test('(d) newest older than the watermark: no new alert or digest', function (): void {
    AlertRows::runs('2026-10-10', '2026-10-11');
    $runTwelve = AlertRows::run('2026-10-12');
    $twelve = AlertRows::event(T6S_A, '2026-10-12');
    AlertRows::event(T6S_B, '2026-10-11');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B]);
    AlertRows::dispatch();

    DB::table('ingest_runs')->where('id', $runTwelve)->delete();
    [, $line] = AlertRows::dispatch();

    expect($line['deliverable_day'])->toBeNull()
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(t6sAlerts())->toBe([[$user->id, $twelve]])
        ->and(t6sDigests())->toBe([[$user->id, '2026-10-12', ['Name of base1-4']]]);
});

test('(e) a replay-only newest day with no event: no alert and no digest', function (): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'replay');
    AlertRows::event(T6S_A, '2026-10-11');
    t6sSubscriber('subscriber@example.com', [T6S_A]);

    [, $line] = AlertRows::dispatch();

    expect($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(t6sAlerts())->toBe([])
        ->and(t6sDigests())->toBe([]);
});

test('(f) a watermark below the pre-crash newest day: nothing during the repair, then the newest day only', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11');
    $eleven = AlertRows::event(T6S_A, '2026-10-11');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B, T6S_C]);
    AlertRows::dispatch();

    AlertRows::run('2026-10-12');
    $twelveB = AlertRows::event(T6S_B, '2026-10-12');
    $twelveC = AlertRows::event(T6S_C, '2026-10-12');
    AlertRows::supersedeAll();
    $during = [];
    $extra = null;
    foreach (['2026-10-09', '2026-10-10', '2026-10-11'] as $day) {
        AlertRows::run($day);
        if ($day === '2026-10-11') {
            $extra = AlertRows::event(T6S_B, '2026-10-11');
        }
        AlertRows::dispatch();
        $during[] = [t6sAlerts(), t6sDigests()];
    }
    AlertRows::run('2026-10-12');
    AlertRows::dispatch();

    $before = [[[$user->id, $eleven]], [[$user->id, '2026-10-11', ['Name of base1-4']]]];
    expect($during)->toBe([$before, $before, $before])
        ->and(t6sAlerts())->toBe([[$user->id, $eleven], [$user->id, $twelveB], [$user->id, $twelveC]])
        ->and(array_column(t6sAlerts(), 1))->not->toContain($extra)
        ->and(t6sDigests())->toBe([
            [$user->id, '2026-10-11', ['Name of base1-4']],
            [$user->id, '2026-10-12', ['Name of base1-2', 'Name of base1-7']],
        ]);
});

test('(g) an event added to a dispatched day: no alert and no digest line for it, even for a new subscriber', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $old = AlertRows::event(T6S_A, '2026-10-12');
    $first = t6sSubscriber('first@example.com', [T6S_A, T6S_D]);
    AlertRows::dispatch();

    $added = AlertRows::event(T6S_D, '2026-10-12');
    $second = t6sSubscriber('second@example.com', [T6S_A, T6S_D]);
    [, $line] = AlertRows::dispatch();

    expect($added)->toBeGreaterThan($old)
        ->and(t6sAlerts())->toBe([[$first->id, $old], [$second->id, $old]])
        ->and(t6sDigests())->toBe([
            [$first->id, '2026-10-12', ['Name of base1-4']],
            [$second->id, '2026-10-12', ['Name of base1-4']],
        ])
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 1, 'digest' => 1]);
});

test('(h) a mistaken watermark sends nothing until the operator reset, then the newest day once', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $twelve = AlertRows::event(T6S_A, '2026-10-12');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A]);
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-14', 'event_id_ceiling' => null]);

    AlertRows::dispatch();
    $blocked = [t6sAlerts(), t6sDigests()];
    AlertRows::dispatch(['--reset-watermark' => '2026-10-12']);
    $afterReset = [t6sAlerts(), t6sDigests()];
    AlertRows::dispatch();
    AlertRows::dispatch();

    expect($blocked)->toBe([[], []])
        ->and($afterReset)->toBe([[], []])
        ->and(t6sAlerts())->toBe([[$user->id, $twelve]])
        ->and(t6sDigests())->toBe([[$user->id, '2026-10-12', ['Name of base1-4']]]);
});

test('(i) a future-dated day: no alert or digest line for it; the newest real day is sent', function (string $status): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::run('2026-10-20', $status);
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-20', 'event_id_ceiling' => null]);
    $twelve = AlertRows::event(T6S_A, '2026-10-12');
    $twenty = AlertRows::event(T6S_B, '2026-10-20');
    $user = t6sSubscriber('subscriber@example.com', [T6S_A, T6S_B]);

    AlertRows::dispatch();

    expect(t6sAlerts())->toBe([[$user->id, $twelve]])
        ->and(array_column(t6sAlerts(), 1))->not->toContain($twenty)
        ->and(t6sDigests())->toBe([[$user->id, '2026-10-12', ['Name of base1-4']]]);
})->with(['ok', 'superseded']);
