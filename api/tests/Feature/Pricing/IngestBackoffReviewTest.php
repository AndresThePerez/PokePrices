<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\IngestPending;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Plan Q1-i3 T05 review tests: the streak k, the hold boundaries under another session zone, --queued, per-marker
// isolation, and the statement count / no-write promises of plan().

const RV_DAY = '2026-10-11';
const RV_MARKER = '2026-10-11 20:05:45+00:00';

/** @var list<string> */
$GLOBALS['rv_roots'] = [];

function rvRoot(array $days): string
{
    $root = storage_path('framework/testing/pp-rv-'.bin2hex(random_bytes(6)));
    foreach ($days as $day) {
        mkdir("{$root}/{$day}", 0775, true);
        file_put_contents("{$root}/{$day}/SUCCESS", '');
        file_put_contents("{$root}/{$day}/marker.txt", "{$day}T20:05:45+0000\n");
    }
    $GLOBALS['rv_roots'][] = $root;

    return $root;
}

function rvRun(string $status, CarbonImmutable $finishedAt, ?string $error = null, string $day = RV_DAY, ?string $marker = RV_MARKER): int
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

/** @return list<string> */
function rvScanAt(string $root, CarbonImmutable $at): array
{
    test()->travelTo($at);

    return (new IngestPending($root, 'database'))->scan()['dispatched'];
}

/** @return list<array<string, mixed>> */
function rvWarnings(ArrayObject $log): array
{
    $out = [];
    foreach ($log as $e) {
        if ($e->level === 'warning' && $e->message === 'ingest_failed_repeatedly') {
            $out[] = $e->context;
        }
    }

    return $out;
}

beforeEach(function (): void {
    Bus::fake();
    $this->log = new ArrayObject;
    $log = $this->log;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($log): void {
        $log->append($e);
    });
    $this->t0 = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC');
});

afterEach(function (): void {
    foreach ($GLOBALS['rv_roots'] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
    $GLOBALS['rv_roots'] = [];
});

test('a refused or ok run newer than the failures breaks the streak: no hold, no warning', function (): void {
    $refusedRoot = rvRoot([RV_DAY]);
    rvRun('failed', $this->t0, 'boom');
    rvRun('failed', $this->t0->addMinutes(10), 'boom');
    rvRun('failed', $this->t0->addMinutes(20), 'boom');
    rvRun('refused', $this->t0->addMinutes(30), 'bad_document:other.json');
    $afterRefused = rvScanAt($refusedRoot, $this->t0->addMinutes(40));

    rvRun('ok', $this->t0->addMinutes(50));
    $afterOk = rvScanAt($refusedRoot, $this->t0->addMinutes(60));

    expect($afterRefused)->toBe([RV_DAY])
        ->and($afterOk)->toBe([])
        ->and(rvWarnings($this->log))->toBe([]);
});

test('the streak of the folder marker is not cut or extended by another marker interleaved by id', function (): void {
    $root = rvRoot([RV_DAY]);
    $other = '2026-10-11 19:00:00+00:00';
    rvRun('failed', $this->t0, 'boom');
    rvRun('failed', $this->t0->addMinutes(1), 'other', RV_DAY, $other);
    rvRun('failed', $this->t0->addMinutes(2), 'other', RV_DAY, $other);
    rvRun('failed', $this->t0->addMinutes(3), 'other', RV_DAY, $other);
    rvRun('failed', $this->t0->addMinutes(4), 'boom');

    // The folder's marker has k = 2 (the other marker's three failures neither join nor break it): held 6 h from :04.
    expect(rvScanAt($root, $this->t0->addHours(6)->addMinutes(3)))->toBe([])
        ->and(rvScanAt($root, $this->t0->addHours(6)->addMinutes(4)))->toBe([RV_DAY])
        ->and(rvWarnings($this->log))->toBe([]);
});

test('the hold boundary and the warning hold under a session time zone that is not UTC', function (): void {
    $before = (string) DB::selectOne('SHOW TIME ZONE')->TimeZone;
    DB::statement("SET TIME ZONE 'America/Chicago'");

    try {
        $root = rvRoot([RV_DAY]);
        rvRun('failed', $this->t0, 'boom');
        rvRun('failed', $this->t0->addHour(), 'boom');
        rvRun('failed', $this->t0->addHours(2), 'boom');
        $heldAt = rvScanAt($root, $this->t0->addHours(2)->addHours(23)->addMinutes(59));
        $freeAt = rvScanAt($root, $this->t0->addHours(2)->addHours(24));
    } finally {
        DB::statement("SET TIME ZONE '{$before}'");
    }

    expect($heldAt)->toBe([])
        ->and($freeAt)->toBe([RV_DAY])
        ->and(rvWarnings($this->log))->toHaveCount(1)
        ->and(rvWarnings($this->log)[0]['held_until'])->toBe('2026-10-21T14:00:00Z')
        ->and(rvWarnings($this->log)[0]['marker'])->toBe(RV_MARKER);
});

test('two held days each warn once, a null error is logged as null, and a held day does not stop the days around it', function (): void {
    $root = rvRoot(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']);
    foreach (['2026-10-10', '2026-10-12'] as $day) {
        foreach ([0, 1, 2] as $i) {
            rvRun('failed', $this->t0->addMinutes($i), $i === 2 ? null : 'boom', $day, "{$day} 20:05:45+00:00");
        }
    }
    rvRun('failed', $this->t0, 'boom', '2026-10-11', '2026-10-11 20:05:45+00:00');

    $first = rvScanAt($root, $this->t0->addHours(2));
    $second = rvScanAt($root, $this->t0->addHours(3));
    $warnings = rvWarnings($this->log);

    expect($first)->toBe(['2026-10-11', '2026-10-13'])
        ->and($second)->toBe(['2026-10-11', '2026-10-13'])
        ->and($warnings)->toHaveCount(2)
        ->and(array_column($warnings, 'day'))->toBe(['2026-10-10', '2026-10-12'])
        ->and($warnings[0]['error'])->toBeNull()
        ->and($warnings[0]['failures'])->toBe(3);
});

test('--queued by hand starts a run on a day the scan holds', function (): void {
    $root = rvRoot([RV_DAY]);
    rvRun('failed', $this->t0, 'boom');
    rvRun('failed', $this->t0->addHour(), 'boom');
    $last = rvRun('failed', $this->t0->addHours(2), 'boom');
    $held = rvScanAt($root, $this->t0->addHours(3));

    Artisan::call('pokeprices:prices:ingest', ['--day' => RV_DAY, '--queued' => true, '--queue-connection' => 'database', '--snapshots' => $root]);
    $output = Artisan::output();

    expect($held)->toBe([])
        ->and(DB::table('ingest_runs')->where('id', '>', $last)->count())->toBe(1, $output);
});

test('planning costs two ingest_runs statements for days without any run, none without days, and writes no ingest_runs row', function (): void {
    $statements = 0;
    DB::listen(function (QueryExecuted $q) use (&$statements): void {
        $statements += str_contains($q->sql, 'ingest_runs') ? 1 : 0;
    });

    $empty = rvRoot([]);
    mkdir($empty, 0775, true);
    $statements = 0;
    $none = rvScanAt($empty, $this->t0);
    $noneCount = $statements;

    $root = rvRoot(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05']);
    $statements = 0;
    $fresh = rvScanAt($root, $this->t0);
    $freshCount = $statements;

    foreach ([0, 1, 2] as $i) {
        rvRun('failed', $this->t0->addMinutes($i), 'boom', '2026-10-02', '2026-10-02 20:05:45+00:00');
    }
    rvRun('ok', $this->t0, null, '2026-10-03', '2026-10-03 20:05:45+00:00');
    $before = json_encode(DB::table('ingest_runs')->orderBy('id')->get(), JSON_THROW_ON_ERROR);
    $statements = 0;
    rvScanAt($root, $this->t0->addHours(1));
    $after = json_encode(DB::table('ingest_runs')->orderBy('id')->get(), JSON_THROW_ON_ERROR);

    expect($none)->toBe([])
        ->and($noneCount)->toBe(0)
        ->and($fresh)->toBe(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'])
        ->and($freshCount)->toBe(2)
        ->and($after)->toBe($before);
});
