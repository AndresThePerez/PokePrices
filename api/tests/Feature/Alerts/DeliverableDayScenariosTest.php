<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan P5-i1 T01 criterion 5 (D2, R192, Amendment 1 A01, A09): the deliverable day through the dispatch command,
// one test per scenario, rows inserted directly, the app clock at 2026-10-15 unless stated. Only the newest
// finalized day is ever delivered, never during a crash repair, never a day passed over, never an event written
// after the day's first dispatch, never a future-dated day.

const P5_A = 'base1-4:holo:-:-';
const P5_B = 'base1-2:holo:-:-';
const P5_C = 'base1-7:holo:-:-';
const P5_D = 'base1-9:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/**
 * One eligible watcher of the given variants with one standard endpoint; returns the endpoint id.
 *
 * @param  list<string>  $variants
 */
function p5Watcher(string $email, array $variants): int
{
    $user = AlertRows::user($email);
    foreach ($variants as $variant) {
        AccountRows::watch($user, $variant);
    }

    return AlertRows::endpoint($user);
}

/**
 * The event ids of the recorded price.moved deliveries, sorted.
 *
 * @return list<int>
 */
function p5DeliveredEvents(): array
{
    $ids = array_map(fn (array $pair): int => $pair[0], AlertRows::moved());
    sort($ids);

    return array_values(array_unique($ids));
}

/** @return list<array{level: string, message: string, context: array<string, mixed>}> */
function p5Skips(string $reason): array
{
    return array_values(array_filter(AlertRows::logged('alerts_dispatch_skipped'), fn (array $r): bool => ($r['context']['reason'] ?? null) === $reason));
}

test('(a) first start: only the newest finalized day is delivered, and its ceiling is recorded', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    AlertRows::event(P5_A, '2026-10-10');
    AlertRows::event(P5_B, '2026-10-11');
    $twelveA = AlertRows::event(P5_A, '2026-10-12');
    $twelveC = AlertRows::event(P5_C, '2026-10-12');
    $endpoint = p5Watcher('watcher@example.com', [P5_A, P5_B, P5_C]);

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and(AlertRows::moved())->toBe([[$twelveA, $endpoint], [$twelveC, $endpoint]])
        ->and(AlertRows::days())->toBe([['tcgplayer', '2026-10-12', max($twelveA, $twelveC)]])
        ->and($line)->toMatchArray([
            'deliverable_day' => '2026-10-12', 'newest_day' => '2026-10-12', 'floor' => '2026-10-12', 'watermark' => null,
            'ceiling' => max($twelveA, $twelveC), 'events' => 2, 'created' => ['price.moved' => 2], 'skipped' => null, 'passed_over' => [],
        ]);
});

test('(b) crash repair: no repaired older day is ever delivered, and the re-finalized newest day adds nothing', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    AlertRows::event(P5_A, '2026-10-10');
    AlertRows::event(P5_B, '2026-10-11');
    AlertRows::event(P5_A, '2026-10-12');
    AlertRows::event(P5_C, '2026-10-12');
    p5Watcher('watcher@example.com', [P5_A, P5_B, P5_C]);
    AlertRows::dispatch();
    $first = AlertRows::moved();

    AlertRows::supersedeAll();
    $steps = [];
    $repairEvents = [];
    foreach (['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        AlertRows::run($day);
        if ($day === '2026-10-10' || $day === '2026-10-11') {
            // The repair is momentarily the newest day, so DetectMovement writes crossings it had not fired first time.
            $repairEvents[] = AlertRows::event(P5_C, $day);
        }
        [$code, $line] = AlertRows::dispatch();
        $steps[$day] = [$code, $line['deliverable_day'], $line['skipped'], AlertRows::moved()];
    }

    $repair = fn (string $day): string => "repair in progress: newest finalized day {$day} is older than 2026-10-12";
    expect($first)->toHaveCount(2)
        ->and($steps['2026-10-09'])->toBe([0, null, $repair('2026-10-09'), $first])
        ->and($steps['2026-10-10'])->toBe([0, null, $repair('2026-10-10'), $first])
        ->and($steps['2026-10-11'])->toBe([0, null, $repair('2026-10-11'), $first])
        ->and($steps['2026-10-12'])->toBe([0, '2026-10-12', null, $first])
        ->and(array_intersect($repairEvents, p5DeliveredEvents()))->toBe([])
        ->and(p5Skips('repair in progress'))->toHaveCount(3)
        ->and(p5Skips('repair in progress')[0]['level'])->toBe('warning');
});

test('(c) scheduler gap: only the newest day is delivered and the day passed over is named in a warning', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(P5_A, '2026-10-12');
    p5Watcher('watcher@example.com', [P5_A, P5_B, P5_C]);
    AlertRows::dispatch();
    $before = AlertRows::moved();

    AlertRows::runs('2026-10-13', '2026-10-14');
    $thirteen = AlertRows::event(P5_B, '2026-10-13');
    $fourteen = AlertRows::event(P5_C, '2026-10-14');
    [$code, $line] = AlertRows::dispatch();
    AlertRows::dispatch();

    $passed = p5Skips('days passed over');
    expect($code)->toBe(0)
        ->and($line)->toMatchArray(['deliverable_day' => '2026-10-14', 'watermark' => '2026-10-12', 'passed_over' => ['2026-10-13'], 'skipped' => null])
        ->and(p5DeliveredEvents())->toBe([$before[0][0], $fourteen])
        ->and(p5DeliveredEvents())->not->toContain($thirteen)
        ->and($passed)->not->toBe([])
        ->and($passed[0]['level'])->toBe('warning')
        ->and($passed[0]['context']['days'])->toBe(['2026-10-13']);
});

test('(d) newest older than the watermark: nothing is deliverable and the skip is a warning', function (): void {
    AlertRows::runs('2026-10-10', '2026-10-11');
    $twelve = AlertRows::run('2026-10-12');
    AlertRows::event(P5_A, '2026-10-12');
    AlertRows::event(P5_B, '2026-10-11');
    p5Watcher('watcher@example.com', [P5_A, P5_B]);
    AlertRows::dispatch();
    $before = AlertRows::moved();

    DB::table('ingest_runs')->where('id', $twelve)->delete();
    [$code, $line] = AlertRows::dispatch();

    $skips = p5Skips('newest older than the last dispatched day');
    expect($code)->toBe(0)
        ->and($line)->toMatchArray([
            'deliverable_day' => null, 'newest_day' => '2026-10-11', 'watermark' => '2026-10-12', 'ceiling' => null,
            'created' => ['price.moved' => 0],
            'skipped' => 'newest finalized day 2026-10-11 is older than the last dispatched day 2026-10-12',
        ])
        ->and(AlertRows::moved())->toBe($before)
        ->and($skips)->toHaveCount(1)
        ->and($skips[0]['level'])->toBe('warning');
});

test('(e) a replay-only newest day with no event: nothing is delivered and the day is recorded with a null ceiling', function (): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'replay');
    AlertRows::event(P5_A, '2026-10-11');
    p5Watcher('watcher@example.com', [P5_A]);

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and(AlertRows::moved())->toBe([])
        ->and(AlertRows::days())->toBe([['tcgplayer', '2026-10-12', null]])
        ->and($line)->toMatchArray(['deliverable_day' => '2026-10-12', 'ceiling' => null, 'events' => 0, 'created' => ['price.moved' => 0]]);
});

test('(f) a watermark below the pre-crash newest day: nothing during the repair, then only the newest day', function (): void {
    AlertRows::runs('2026-10-09', '2026-10-10', '2026-10-11');
    $eleven = AlertRows::event(P5_A, '2026-10-11');
    p5Watcher('watcher@example.com', [P5_A, P5_B, P5_C]);
    AlertRows::dispatch();
    expect(p5DeliveredEvents())->toBe([$eleven]);

    // 10-12 finalizes but its dispatch never runs; then a crash and an ascending repair.
    AlertRows::run('2026-10-12');
    $twelveB = AlertRows::event(P5_B, '2026-10-12');
    $twelveC = AlertRows::event(P5_C, '2026-10-12');
    AlertRows::supersedeAll();
    $during = [];
    $extra = null;
    foreach (['2026-10-09', '2026-10-10', '2026-10-11'] as $day) {
        AlertRows::run($day);
        if ($day === '2026-10-11') {
            $extra = AlertRows::event(P5_B, '2026-10-11');
        }
        [, $line] = AlertRows::dispatch();
        $during[] = [$line['deliverable_day'], p5DeliveredEvents()];
    }
    AlertRows::run('2026-10-12');
    [, $last] = AlertRows::dispatch();

    expect($during)->toBe([[null, [$eleven]], [null, [$eleven]], [null, [$eleven]]])
        ->and($last['deliverable_day'])->toBe('2026-10-12')
        ->and($last['created'])->toBe(['price.moved' => 2])
        ->and(p5DeliveredEvents())->toBe([$eleven, $twelveB, $twelveC])
        ->and(p5DeliveredEvents())->not->toContain($extra);
});

test('(g) an event added to a dispatched day is never delivered, even to a new watcher', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $old = AlertRows::event(P5_A, '2026-10-12');
    $first = p5Watcher('first@example.com', [P5_A, P5_D]);
    AlertRows::dispatch();

    $added = AlertRows::event(P5_D, '2026-10-12');
    $second = p5Watcher('second@example.com', [P5_A, P5_D]);
    [, $line] = AlertRows::dispatch();

    expect($added)->toBeGreaterThan($old)
        ->and(AlertRows::moved())->toBe([[$old, $first], [$old, $second]])
        ->and(AlertRows::days())->toBe([['tcgplayer', '2026-10-12', $old]])
        ->and($line)->toMatchArray(['ceiling' => $old, 'events' => 1, 'created' => ['price.moved' => 1]]);
});

test('(h) the operator reset re-opens a mistaken watermark; a later or malformed date is refused', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $twelve = AlertRows::event(P5_A, '2026-10-12');
    $endpoint = p5Watcher('watcher@example.com', [P5_A]);
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-14', 'event_id_ceiling' => null]);

    [, $blocked] = AlertRows::dispatch();
    $refused = [];
    foreach (['2026-10-13', '2026-10-14', '2026-13-01', '2026-02-30', 'yesterday', ''] as $date) {
        [$code, , $output] = AlertRows::dispatch(['--reset-watermark' => $date]);
        $refused[$date] = [$code, AlertRows::days(), str_contains($output, '"deleted"')];
    }
    [$resetCode, $reset] = AlertRows::dispatch(['--reset-watermark' => '2026-10-12']);
    $afterReset = AlertRows::moved();
    [, $line] = AlertRows::dispatch();

    $kept = [['tcgplayer', '2026-10-14', null]];
    expect($blocked)->toMatchArray(['deliverable_day' => null, 'skipped' => 'newest finalized day 2026-10-12 is older than the last dispatched day 2026-10-14'])
        ->and($refused)->toBe([
            '2026-10-13' => [1, $kept, false],
            '2026-10-14' => [1, $kept, false],
            '2026-13-01' => [1, $kept, false],
            '2026-02-30' => [1, $kept, false],
            'yesterday' => [1, $kept, false],
            '' => [1, $kept, false],
        ])
        ->and($resetCode)->toBe(0)
        ->and($reset)->toBe(['reset_after' => '2026-10-12', 'deleted' => 1])
        ->and($afterReset)->toBe([])
        ->and($line)->toMatchArray(['deliverable_day' => '2026-10-12', 'created' => ['price.moved' => 1]])
        ->and(AlertRows::moved())->toBe([[$twelve, $endpoint]]);
});

test('(i) a future-dated day is ignored by newest, floor and watermark, with a warning', function (string $status): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::run('2026-10-20', $status);
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-20', 'event_id_ceiling' => null]);
    $twelve = AlertRows::event(P5_A, '2026-10-12');
    $twenty = AlertRows::event(P5_B, '2026-10-20');
    $endpoint = p5Watcher('watcher@example.com', [P5_A, P5_B]);

    [$code, $line] = AlertRows::dispatch();

    $future = p5Skips('future-dated run ignored');
    expect($code)->toBe(0)
        ->and($line)->toMatchArray(['deliverable_day' => '2026-10-12', 'newest_day' => '2026-10-12', 'floor' => '2026-10-12', 'watermark' => null, 'skipped' => null])
        ->and(AlertRows::moved())->toBe([[$twelve, $endpoint]])
        ->and(p5DeliveredEvents())->not->toContain($twenty)
        ->and($future)->toHaveCount(1)
        ->and($future[0]['level'])->toBe('warning')
        ->and($future[0]['context']['days'])->toBe(['2026-10-20']);
})->with(['ok', 'superseded']);
