<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\PriceJobProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

// What is wrong with the price job, read from ingest_runs and the clock alone: a newest day past the freshness limit
// (late), a calendar day between the first and the newest ok day without an ok run (missing_day), a day folder whose
// newest run is a refusal (refused), and a run left running or a day on hold after repeated failures (stuck).

/** Inserts one ingest run and returns its id; the marker defaults to 20:05:44 UTC on the run's day. */
function pjpRun(string $status, string $day, string|false|null $marker = false, ?string $startedAt = null, ?string $finishedAt = null, ?string $error = null, string $mode = 'queued'): int
{
    $marker = $marker === false ? "{$day}T20:05:44Z" : $marker;

    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $marker, 'mode' => $mode,
        'status' => $status, 'started_at' => $startedAt ?? "{$day}T21:40:00Z",
        'finished_at' => $finishedAt, 'error' => $error, 'counts' => '{}',
    ]);
}

/** Sets the clock. */
function pjpAt(string $instant): CarbonImmutable
{
    $now = CarbonImmutable::parse($instant);
    Carbon::setTestNow($now);

    return $now;
}

/** One ok run on each of the days. */
function pjpOk(string ...$days): void
{
    foreach ($days as $day) {
        pjpRun('ok', $day, false, null, "{$day}T21:41:00Z");
    }
}

it('lists nothing when every day has its ok run and the newest is inside the limit', function (): void {
    pjpOk('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    // Runs that are not a problem: a superseded one, an unchanged re-run, and a single failure a later ok run mended.
    pjpRun('superseded', '2026-10-10');
    pjpRun('failed', '2026-10-11', false, '2026-10-11T21:00:00Z', '2026-10-11T21:01:00Z', 'boom');
    pjpRun('ok', '2026-10-11', false, '2026-10-11T22:40:00Z', '2026-10-11T22:41:00Z');
    pjpRun('skipped_unchanged', '2026-10-12', false, '2026-10-12T22:40:00Z', '2026-10-12T22:40:01Z');
    // One microsecond inside the limit.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T20:05:44Z')->addHours(26)->subMicrosecond());

    expect(PriceJobProblems::all())->toBe([]);
});

it('lists the newest day as late at exactly the limit and one minute over, by the freshness rule', function (int $extraSeconds): void {
    pjpOk('2026-10-11', '2026-10-12');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T20:05:44Z')->addHours(26)->addSeconds($extraSeconds));

    expect(PriceJobProblems::all())->toBe([[
        'kind' => 'late',
        'day' => '2026-10-12',
        'key' => 'late:2026-10-12',
        'sentence' => 'The newest prices are from 2026-10-12, which is past the limit of 26 hours.',
    ]]);

    // The limit is the freshness setting, read and never restated: a longer one makes the same state fine.
    config(['pricing.freshness.slo_hours' => 30]);
    expect(PriceJobProblems::all())->toBe([]);
})->with(['exactly the limit' => [0], 'one minute over' => [60]]);

it('lists late without a day when no price day was ever ingested', function (): void {
    pjpRun('failed', '2026-10-12', false, '2026-10-12T21:40:00Z', '2026-10-12T21:41:00Z', 'boom');
    pjpAt('2026-10-12T22:00:00Z');

    expect(PriceJobProblems::all())->toBe([[
        'kind' => 'late',
        'day' => null,
        'key' => 'late:none',
        'sentence' => 'No price day has been ingested yet.',
    ]]);
});

it('lists every calendar day between the first and the newest ok day that has no ok run', function (array $okDays, array $missing): void {
    pjpOk(...$okDays);
    pjpAt(end($okDays).'T21:05:44Z');

    $problems = PriceJobProblems::all();

    expect(array_column($problems, 'kind'))->toBe(array_fill(0, count($missing), 'missing_day'))
        ->and(array_column($problems, 'day'))->toBe($missing)
        ->and(array_column($problems, 'key'))->toBe(array_map(fn (string $day): string => "missing_day:{$day}", $missing))
        ->and($problems[0]['sentence'])->toBe("No price run finished for {$missing[0]}.")
        ->and(PriceJobProblems::missingDays())->toBe($missing);
})->with([
    'one day' => [['2026-10-09', '2026-10-10', '2026-10-12'], ['2026-10-11']],
    'three in a row' => [['2026-10-09', '2026-10-13', '2026-10-14'], ['2026-10-10', '2026-10-11', '2026-10-12']],
    'the last day of a month' => [['2026-10-30', '2026-11-01'], ['2026-10-31']],
    'the first day of a month, after a short February' => [['2027-02-28', '2027-03-02'], ['2027-03-01']],
    'two separate gaps' => [['2026-12-30', '2027-01-01', '2027-01-03'], ['2026-12-31', '2027-01-02']],
]);

it('does not count a superseded, failed, refused or running run as the day\'s ok run', function (string $status): void {
    pjpOk('2026-10-09', '2026-10-11');
    pjpRun($status, '2026-10-10', false, '2026-10-11T21:30:00Z', $status === 'running' ? null : '2026-10-10T21:41:00Z');
    pjpAt('2026-10-11T21:45:00Z');

    expect(PriceJobProblems::missingDays())->toBe(['2026-10-10']);
})->with(['superseded', 'failed', 'refused', 'running', 'skipped_unchanged']);

it('lists a refused day folder with its reason, and nothing once a later run of the day is ok', function (): void {
    pjpOk('2026-10-11');
    $error = 'marker.txt is on another UTC date than the folder';
    pjpRun('refused', '2026-10-12', '2026-10-12T20:05:44Z', '2026-10-12T21:40:00Z', '2026-10-12T21:40:01Z', $error);
    pjpAt('2026-10-12T22:00:00Z');

    expect(PriceJobProblems::all())->toBe([[
        'kind' => 'refused',
        'day' => '2026-10-12',
        'key' => 'refused:2026-10-12:2026-10-12T20:05:44Z:'.substr(sha1($error), 0, 12),
        'sentence' => "The folder of 2026-10-12 was refused: {$error}.",
    ]]);

    // The folder was repaired and ingested: the refusal is history.
    pjpRun('ok', '2026-10-12', '2026-10-12T20:05:44Z', '2026-10-12T22:40:00Z', '2026-10-12T22:41:00Z');
    pjpAt('2026-10-12T23:00:00Z');
    expect(PriceJobProblems::all())->toBe([]);
});

it('keeps a refusal that follows an ok run of the day, names a refusal without a marker, and cuts a long reason', function (): void {
    pjpOk('2026-10-10', '2026-10-11', '2026-10-12');
    $long = str_repeat('x', 400);
    pjpRun('refused', '2026-10-11', null, '2026-10-13T08:40:00Z', '2026-10-13T08:40:01Z', 'SUCCESS is absent');
    pjpRun('refused', '2026-10-12', '2026-10-12T20:09:00Z', '2026-10-13T08:40:02Z', '2026-10-13T08:40:03Z', $long);
    pjpAt('2026-10-13T09:00:00Z');

    $problems = PriceJobProblems::all();

    expect(array_column($problems, 'kind'))->toBe(['refused', 'refused'])
        ->and($problems[0]['key'])->toBe('refused:2026-10-11:none:'.substr(sha1('SUCCESS is absent'), 0, 12))
        ->and($problems[0]['sentence'])->toBe('The folder of 2026-10-11 was refused: SUCCESS is absent.')
        ->and($problems[1]['key'])->toBe('refused:2026-10-12:2026-10-12T20:09:00Z:'.substr(sha1($long), 0, 12))
        ->and($problems[1]['sentence'])->toBe('The folder of 2026-10-12 was refused: '.str_repeat('x', 200).'….');
});

it('lists a run left running past the stall limit and one scan as stuck, and leaves a younger one alone', function (): void {
    pjpOk('2026-10-11', '2026-10-12', '2026-10-13');
    // 2026-10-12 was collected a second time; the run of its new marker never finished.
    $runId = pjpRun('running', '2026-10-12', '2026-10-12T23:01:00Z', '2026-10-13T20:10:00Z');
    $limit = CarbonImmutable::parse('2026-10-13T20:10:00Z')->addMinutes(IngestPending::STALLED_AFTER_MINUTES + 60);

    Carbon::setTestNow($limit->subSecond());
    expect(PriceJobProblems::STUCK_AFTER_MINUTES)->toBe(IngestPending::STALLED_AFTER_MINUTES + 60)
        ->and(PriceJobProblems::all())->toBe([]);

    Carbon::setTestNow($limit);
    expect(PriceJobProblems::all())->toBe([[
        'kind' => 'stuck',
        'day' => '2026-10-12',
        'key' => "stuck:2026-10-12:run:{$runId}",
        'sentence' => "Run {$runId} of 2026-10-12 has not finished and blocks its day.",
    ]]);
});

it('lists a day on hold after repeated failures as stuck until the hold ends', function (int $failures, int $holdHours): void {
    pjpOk('2026-10-11');
    $last = 0;
    for ($i = 0; $i < $failures; $i++) {
        $last = pjpRun('failed', '2026-10-12', false, "2026-10-12T2{$i}:40:00Z", "2026-10-12T2{$i}:41:00Z", 'the chunk broke');
    }
    $failedAt = CarbonImmutable::parse('2026-10-12T2'.($failures - 1).':41:00Z');
    $times = $failures === 2 ? 'twice' : "{$failures} times";

    Carbon::setTestNow($failedAt->addHours($holdHours)->subSecond());
    // Inside the hold the newest day may be late as well; only the stuck entry is read here.
    $stuck = array_values(array_filter(PriceJobProblems::all(), fn (array $p): bool => $p['kind'] === 'stuck'));
    expect($stuck)->toBe([[
        'kind' => 'stuck',
        'day' => '2026-10-12',
        'key' => "stuck:2026-10-12:hold:{$last}",
        'sentence' => "2026-10-12 failed {$times} in a row and is on hold.",
    ]]);

    Carbon::setTestNow($failedAt->addHours($holdHours));
    expect(array_column(PriceJobProblems::all(), 'kind'))->not->toContain('stuck');
})->with([
    'two failures' => [2, IngestPending::FAILED_TWICE_HOLD_HOURS],
    'three failures' => [3, IngestPending::FAILED_REPEATEDLY_HOLD_HOURS],
]);

it('holds no day after a single failure, or after failures an ok run of the marker interrupted', function (): void {
    pjpOk('2026-10-11');
    pjpRun('failed', '2026-10-12', false, '2026-10-12T20:40:00Z', '2026-10-12T20:41:00Z', 'boom');
    pjpAt('2026-10-12T20:45:00Z');
    expect(PriceJobProblems::all())->toBe([]);

    // failed, ok, failed: the newest chain is one failure long.
    pjpRun('ok', '2026-10-12', false, '2026-10-12T21:40:00Z', '2026-10-12T21:41:00Z');
    pjpRun('failed', '2026-10-12', false, '2026-10-12T22:40:00Z', '2026-10-12T22:41:00Z', 'boom');
    pjpAt('2026-10-12T22:45:00Z');
    expect(PriceJobProblems::all())->toBe([]);
});

it('answers a busy state in two queries, in one order, the same every time', function (): void {
    pjpOk('2026-10-08', '2026-10-09', '2026-10-13');
    $error = 'prices/3.json.zst is not readable';
    pjpRun('refused', '2026-10-10', false, '2026-10-14T08:40:00Z', '2026-10-14T08:40:01Z', $error);
    $running = pjpRun('running', '2026-10-11', false, '2026-10-14T04:00:00Z');
    pjpRun('failed', '2026-10-12', false, '2026-10-14T20:00:00Z', '2026-10-14T20:01:00Z', 'boom');
    $failed = pjpRun('failed', '2026-10-12', false, '2026-10-14T21:00:00Z', '2026-10-14T21:01:00Z', 'boom');
    pjpAt('2026-10-14T23:00:00Z');

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $first = PriceJobProblems::all();
    $count = count($statements);
    $second = PriceJobProblems::all();

    expect($count)->toBe(2)
        ->and($second)->toBe($first)
        ->and(array_column($first, 'key'))->toBe([
            'late:2026-10-13',
            'missing_day:2026-10-10',
            'refused:2026-10-10:2026-10-10T20:05:44Z:'.substr(sha1($error), 0, 12),
            'missing_day:2026-10-11',
            "stuck:2026-10-11:run:{$running}",
            'missing_day:2026-10-12',
            "stuck:2026-10-12:hold:{$failed}",
        ]);
    foreach ($first as $problem) {
        expect(array_keys($problem))->toBe(['kind', 'day', 'key', 'sentence'])
            ->and($problem['sentence'])->not->toMatch('/\d{2}:\d{2}/');
    }
});

it('writes nothing while it reads', function (): void {
    pjpOk('2026-10-09', '2026-10-11');
    pjpRun('running', '2026-10-10', false, '2026-10-10T21:40:00Z');
    pjpAt('2026-10-11T21:00:00Z');
    $before = DB::table('ingest_runs')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    $problems = PriceJobProblems::all();

    expect(array_column($problems, 'kind'))->toBe(['missing_day', 'stuck'])
        ->and(DB::table('ingest_runs')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before);
});
