<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\IngestPending;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Plan Q1-i3 T05 (review b b3, HG-40 #8, b6): a day whose marker keeps failing is retried at the next :40 scan after one
// failure, held 6 h after two and 24 h after three or more; one ingest_failed_repeatedly warning per newest failed run;
// the scan's plan reads ingest_runs with a constant number of statements however many days are collected.

const BK_DAY = '2026-10-11';
const BK_MARKER = '2026-10-11 20:05:45+00:00';

/** A scratch snapshot root holding only SUCCESS and marker.txt per day (the scan reads nothing else for planning). */
function bkRoot(array $days): string
{
    $root = storage_path('framework/testing/pp-bk-'.bin2hex(random_bytes(6)));
    foreach ($days as $day) {
        mkdir("{$root}/{$day}", 0775, true);
        file_put_contents("{$root}/{$day}/SUCCESS", '');
        file_put_contents("{$root}/{$day}/marker.txt", "{$day}T20:05:45+0000\n");
    }
    bkRoots($root);

    return $root;
}

function bkRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

function bkRun(string $status, CarbonImmutable $finishedAt, ?string $error = null, string $day = BK_DAY, ?string $marker = BK_MARKER): int
{
    return (int) DB::table('ingest_runs')->insertGetId([
        'source' => (string) config('pricing.source'),
        'feed' => (string) config('pricing.feed'),
        'day' => $day,
        'marker' => $marker,
        'mode' => 'queued',
        'status' => $status,
        'started_at' => $finishedAt->subMinutes(5)->format('Y-m-d H:i:sP'),
        'finished_at' => $status === 'running' ? null : $finishedAt->format('Y-m-d H:i:sP'),
        'error' => $error,
    ]);
}

/** @return list<string> the days the scan dispatched at that instant */
function bkScanAt(string $root, CarbonImmutable $at): array
{
    CarbonImmutable::setTestNow($at);

    return (new IngestPending($root, 'database'))->scan()['dispatched'];
}

/** @return list<array<string, mixed>> */
function bkWarnings(): array
{
    return array_values(array_map(fn (MessageLogged $e): array => $e->context,
        array_filter(bkLogged(), fn (MessageLogged $e): bool => $e->level === 'warning' && $e->message === 'ingest_failed_repeatedly')));
}

/** @return list<MessageLogged> the records logged since the last reset */
function bkLogged(?MessageLogged $add = null, bool $reset = false): array
{
    static $logged = [];
    if ($reset) {
        $logged = [];
    }
    if ($add !== null) {
        $logged[] = $add;
    }

    return $logged;
}

beforeEach(function (): void {
    Bus::fake();
    bkLogged(null, true);
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => bkLogged($e));
    $this->t0 = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC');
});

afterEach(function (): void {
    foreach (bkRoots() as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('five consecutive failures: retried at the next scan after one, held 6 h after two, 24 h after three, four and five', function (): void {
    $root = bkRoot([BK_DAY]);
    $t0 = $this->t0;
    $fresh = bkScanAt($root, $t0);

    bkRun('failed', $t0, 'boom');
    $afterOne = bkScanAt($root, $t0->addHour());

    $t1 = $t0->addHour();
    bkRun('failed', $t1, 'boom');
    $twoEarly = bkScanAt($root, $t1->addHours(5)->addMinutes(59));
    $twoDue = bkScanAt($root, $t1->addHours(6));

    $t2 = $t1->addHours(6);
    bkRun('failed', $t2, 'boom');
    $threeHeld = bkScanAt($root, $t2->addHours(6));
    $threeEarly = bkScanAt($root, $t2->addHours(23));
    $threeLast = bkScanAt($root, $t2->addHours(23)->addMinutes(59)->addSeconds(59));
    $threeDue = bkScanAt($root, $t2->addHours(24));

    $t3 = $t2->addHours(24);
    bkRun('failed', $t3, 'boom');
    $fourEarly = bkScanAt($root, $t3->addHours(23));
    $fourDue = bkScanAt($root, $t3->addHours(24));

    $t4 = $t3->addHours(24);
    bkRun('failed', $t4, 'boom');
    $fiveEarly = bkScanAt($root, $t4->addHours(6));
    $fiveDue = bkScanAt($root, $t4->addHours(24));

    expect($fresh)->toBe([BK_DAY])
        ->and($afterOne)->toBe([BK_DAY])
        ->and($twoEarly)->toBe([])
        ->and($twoDue)->toBe([BK_DAY])
        ->and($threeHeld)->toBe([])
        ->and($threeEarly)->toBe([])
        ->and($threeLast)->toBe([])
        ->and($threeDue)->toBe([BK_DAY])
        ->and($fourEarly)->toBe([])
        ->and($fourDue)->toBe([BK_DAY])
        ->and($fiveEarly)->toBe([])
        ->and($fiveDue)->toBe([BK_DAY])
        ->and(DB::table('ingest_runs')->count())->toBe(5);
});

test('a failed run without finished_at is held from its started_at', function (): void {
    $root = bkRoot([BK_DAY]);
    $t0 = $this->t0;
    bkRun('failed', $t0->subHour(), 'boom');
    $id = bkRun('failed', $t0, 'boom');
    DB::table('ingest_runs')->where('id', $id)->update(['finished_at' => null]);
    $started = $t0->subMinutes(5);

    expect(bkScanAt($root, $started->addHours(5)->addMinutes(59)))->toBe([])
        ->and(bkScanAt($root, $started->addHours(6)))->toBe([BK_DAY]);
});

test('three scans at three failures log exactly one ingest_failed_repeatedly warning with its context, and a fourth failure logs one more', function (): void {
    $root = bkRoot([BK_DAY]);
    $t0 = $this->t0;
    bkRun('failed', $t0, 'old error');
    bkRun('failed', $t0->addHour(), 'older error');
    $newest = bkRun('failed', $t0->addHours(7), 'chunk 23821 failed: boom');

    $scans = [
        bkScanAt($root, $t0->addHours(8)),
        bkScanAt($root, $t0->addHours(9)),
        bkScanAt($root, $t0->addHours(10)),
    ];
    $afterThree = bkWarnings();

    bkRun('failed', $t0->addHours(31), 'chunk 23821 failed: again');
    bkScanAt($root, $t0->addHours(32));
    bkScanAt($root, $t0->addHours(33));

    expect($scans)->toBe([[], [], []])
        ->and($afterThree)->toBe([[
            'day' => BK_DAY,
            'marker' => BK_MARKER,
            'failures' => 3,
            'error' => 'chunk 23821 failed: boom',
            'held_until' => '2026-10-21T19:00:00Z',
        ]])
        ->and(Cache::has('pricing:ingest_failed_repeatedly:'.$newest))->toBeTrue()
        ->and(bkWarnings())->toHaveCount(2)
        ->and(bkWarnings()[1])->toMatchArray(['failures' => 4, 'error' => 'chunk 23821 failed: again', 'held_until' => '2026-10-22T19:00:00Z']);
});

test('two failures log no warning', function (): void {
    $root = bkRoot([BK_DAY]);
    bkRun('failed', $this->t0, 'boom');
    bkRun('failed', $this->t0->addHour(), 'boom');

    expect(bkScanAt($root, $this->t0->addHours(2)))->toBe([])
        ->and(bkWarnings())->toBe([]);
});

test('a cache store that throws does not stop the scan: the warning is still logged and the hold still applies', function (): void {
    $root = bkRoot([BK_DAY, '2026-10-12']);
    bkRun('failed', $this->t0, 'boom');
    bkRun('failed', $this->t0->addHour(), 'boom');
    bkRun('failed', $this->t0->addHours(7), 'boom');
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache store down'));

    $dispatched = bkScanAt($root, $this->t0->addHours(8));

    expect($dispatched)->toBe(['2026-10-12'])
        ->and(bkWarnings())->toHaveCount(1)
        ->and(bkWarnings()[0]['failures'])->toBe(3);
});

test('a new marker for the same day resets the count: the re-collected folder is started at once', function (): void {
    $root = bkRoot([BK_DAY]);
    bkRun('failed', $this->t0, 'boom');
    bkRun('failed', $this->t0->addHour(), 'boom');
    bkRun('failed', $this->t0->addHours(7), 'boom');
    $held = bkScanAt($root, $this->t0->addHours(8));
    file_put_contents("{$root}/".BK_DAY.'/marker.txt', BK_DAY."T21:10:00+0000\n");

    $newMarker = bkScanAt($root, $this->t0->addHours(9));
    bkRun('failed', $this->t0->addHours(9), 'boom', BK_DAY, '2026-10-11 21:10:00+00:00');
    $afterOneOnNewMarker = bkScanAt($root, $this->t0->addHours(10));

    expect($held)->toBe([])
        ->and($newMarker)->toBe([BK_DAY])
        ->and($afterOneOnNewMarker)->toBe([BK_DAY]);
});

test('k counts only the failures newer than the newest run that is not failed; skipped_unchanged runs are ignored', function (): void {
    $root = bkRoot([BK_DAY]);
    bkRun('failed', $this->t0, 'boom');
    bkRun('failed', $this->t0->addMinutes(10), 'boom');
    bkRun('superseded', $this->t0->addMinutes(20));
    bkRun('failed', $this->t0->addMinutes(30), 'boom');
    bkRun('skipped_unchanged', $this->t0->addMinutes(40));
    bkRun('failed', $this->t0->addHour(), 'boom');

    // k = 2 (not 4, and the skipped_unchanged run does not break it): held 6 h, no warning.
    expect(bkScanAt($root, $this->t0->addHours(6)->addMinutes(59)))->toBe([])
        ->and(bkScanAt($root, $this->t0->addHours(7)))->toBe([BK_DAY])
        ->and(bkWarnings())->toBe([]);
});

test('--resume on a held day still re-opens its newest failed run', function (): void {
    $root = bkRoot([BK_DAY]);
    bkRun('failed', $this->t0, 'boom');
    bkRun('failed', $this->t0->addHour(), 'boom');
    $newest = bkRun('failed', $this->t0->addHours(7), 'boom');
    $held = bkScanAt($root, $this->t0->addHours(8));

    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => BK_DAY, '--resume' => true, '--queue-connection' => 'database', '--snapshots' => $root]);
    $output = Artisan::output();

    expect($held)->toBe([])
        ->and($code)->toBe(0, $output)
        ->and(DB::table('ingest_runs')->where('id', $newest)->value('status'))->toBe('running');
});

test('the plan reads ingest_runs with the same constant number of statements for 3 and for 12 collected days', function (): void {
    $statements = 0;
    DB::listen(function (QueryExecuted $q) use (&$statements): void {
        $statements += str_contains($q->sql, 'ingest_runs') ? 1 : 0;
    });
    $count = function (int $n) use (&$statements): array {
        DB::table('ingest_runs')->delete();
        $days = array_map(fn (int $i): string => CarbonImmutable::parse('2026-09-01')->addDays($i)->format('Y-m-d'), range(0, $n - 1));
        $root = bkRoot($days);
        foreach ($days as $i => $day) {
            $marker = "{$day} 20:05:45+00:00";
            match ($i % 4) {
                0 => bkRun('ok', $this->t0->subDays(3), null, $day, $marker),
                1 => [bkRun('failed', $this->t0->subHours(3), 'boom', $day, $marker), bkRun('failed', $this->t0->subHours(2), 'boom', $day, $marker)],
                2 => bkRun('refused', $this->t0->subHours(2), 'bad_document:groups.json', $day, $marker),
                default => null,
            };
        }
        $this->travelTo($this->t0);
        $statements = 0;
        $dispatched = (new IngestPending($root, 'database'))->scan()['dispatched'];

        return [$statements, $dispatched];
    };

    [$three, $threeDispatched] = $count(3);
    [$twelve, $twelveDispatched] = $count(12);

    // C = 2: one per-marker read and one per-day read, whatever the number of days.
    expect($three)->toBe(2)
        ->and($twelve)->toBe(2)
        ->and($threeDispatched)->toBe(['2026-09-03'])
        ->and($twelveDispatched)->toBe(['2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-11', '2026-09-12']);
});
