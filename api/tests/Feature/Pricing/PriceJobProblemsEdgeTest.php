<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceJobProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// The dates and the keys of the price job's problems: a gap across a year's first day and a leap day, a history of one
// day, folders refused outside the span of ok days, and a key that names one cause for as long as the cause lasts.

/** Inserts one ingest run and returns its id; the marker defaults to 20:05:44 UTC on the run's day. */
function pjxRun(string $status, string $day, ?string $startedAt = null, ?string $finishedAt = null, ?string $error = null, ?string $marker = null): int
{
    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $marker ?? "{$day}T20:05:44Z", 'mode' => 'queued',
        'status' => $status, 'started_at' => $startedAt ?? "{$day}T21:40:00Z",
        'finished_at' => $finishedAt, 'error' => $error, 'counts' => '{}',
    ]);
}

/** One ok run on each of the days. */
function pjxOk(string ...$days): void
{
    foreach ($days as $day) {
        pjxRun('ok', $day, null, "{$day}T21:41:00Z");
    }
}

/** @return array<string, string> the key of each problem by its kind and day */
function pjxKeys(): array
{
    $keys = [];
    foreach (PriceJobProblems::all() as $problem) {
        $keys["{$problem['kind']} {$problem['day']}"] = $problem['key'];
    }

    return $keys;
}

it('lists the first day of a year and 29 February as missing, answers the newest thirty oldest first and counts all of them', function (): void {
    pjxOk('2027-12-31', '2028-01-02', '2028-02-28', '2028-03-01');
    Carbon::setTestNow(CarbonImmutable::parse('2028-03-01T21:05:44Z'));

    $all = PriceJobProblems::missingDays();
    $problems = PriceJobProblems::all();
    $data = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');

    // 1 January, then 3 January to 27 February (29 + 27 days), then the leap day.
    expect(count($all))->toBe(58)
        ->and($all[0])->toBe('2028-01-01')
        ->and($all[1])->toBe('2028-01-03')
        ->and($all[57])->toBe('2028-02-29')
        ->and(array_intersect($all, ['2027-12-31', '2028-01-02', '2028-02-28', '2028-03-01']))->toBe([])
        ->and($all)->toBe(array_values(array_unique($all)))
        ->and(array_column($problems, 'kind'))->toBe(array_fill(0, 58, 'missing_day'))
        ->and(array_column($problems, 'day'))->toBe($all)
        ->and($data['missing_days_count'])->toBe(58)
        ->and($data['missing_days'])->toBe(array_slice($all, -30))
        ->and($data['missing_days'][0])->toBe('2028-01-30')
        ->and($data['missing_days'][29])->toBe('2028-02-29')
        ->and($data['within_slo'])->toBeTrue()
        ->and($data['day'])->toBe('2028-03-01');
});

it('finds no missing day in a history of one ok day, and lists a folder refused before or after the ok days as refused only', function (): void {
    pjxOk('2026-10-12');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T21:00:00Z'));
    $alone = PriceJobProblems::all();
    $aloneMissing = PriceJobProblems::missingDays();

    // An older folder and the next day's folder are refused: neither lies between the first and the newest ok day.
    pjxRun('refused', '2026-10-08', '2026-10-13T08:40:00Z', '2026-10-13T08:40:01Z', 'bad_document:groups.json');
    pjxRun('refused', '2026-10-13', '2026-10-13T21:40:00Z', '2026-10-13T21:40:01Z', 'missing_file:prices/604.json.zst');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T21:45:00Z'));
    $problems = PriceJobProblems::all();
    $data = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');

    expect($alone)->toBe([])
        ->and($aloneMissing)->toBe([])
        ->and(array_column($problems, 'kind'))->toBe(['refused', 'refused'])
        ->and(array_column($problems, 'day'))->toBe(['2026-10-08', '2026-10-13'])
        ->and(PriceJobProblems::missingDays())->toBe([])
        ->and([$data['missing_days'], $data['missing_days_count'], $data['within_slo']])->toBe([[], 0, true])
        ->and($data['last_run']['status'])->toBe('refused');
});

it('keeps every key across a second reading and across runs that change no cause, and changes the refused key only with the marker or the reason', function (): void {
    pjxOk('2026-10-08', '2026-10-13');
    pjxRun('refused', '2026-10-09', '2026-10-14T08:40:00Z', '2026-10-14T08:40:01Z', 'missing_file:groups.json');
    $running = pjxRun('running', '2026-10-10', '2026-10-14T04:00:00Z');
    pjxRun('failed', '2026-10-11', '2026-10-14T20:00:00Z', '2026-10-14T20:01:00Z', 'boom');
    $failed = pjxRun('failed', '2026-10-11', '2026-10-14T21:00:00Z', '2026-10-14T21:01:00Z', 'boom');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-14T23:00:00Z'));

    $first = pjxKeys();
    $again = pjxKeys();

    // Runs that are no new cause: the retry of the unchanged refused folder, a forced re-ingest of an old ok day, and
    // an unchanged re-run of the newest day.
    pjxRun('refused', '2026-10-09', '2026-10-14T22:40:00Z', '2026-10-14T22:40:01Z', 'missing_file:groups.json');
    pjxRun('ok', '2026-10-08', '2026-10-14T22:41:00Z', '2026-10-14T22:42:00Z');
    pjxRun('skipped_unchanged', '2026-10-13', '2026-10-14T22:43:00Z', '2026-10-14T22:43:01Z');
    $afterUnrelated = pjxKeys();

    // The refused folder is collected again under a later marker with the same fault, then the fault changes.
    pjxRun('refused', '2026-10-09', '2026-10-14T22:50:00Z', '2026-10-14T22:50:01Z', 'missing_file:groups.json', '2026-10-09T22:30:00Z');
    $afterMarker = pjxKeys();
    pjxRun('refused', '2026-10-09', '2026-10-14T22:55:00Z', '2026-10-14T22:55:01Z', 'bad_document:groups.json', '2026-10-09T22:30:00Z');
    $afterReason = pjxKeys();

    $refused = 'refused 2026-10-09';
    $others = fn (array $keys): array => array_diff_key($keys, [$refused => true]);

    expect($first)->toBe([
        'late 2026-10-13' => 'late:2026-10-13',
        'missing_day 2026-10-09' => 'missing_day:2026-10-09',
        $refused => 'refused:2026-10-09:2026-10-09T20:05:44Z:'.substr(sha1('missing_file:groups.json'), 0, 12),
        'missing_day 2026-10-10' => 'missing_day:2026-10-10',
        'stuck 2026-10-10' => "stuck:2026-10-10:run:{$running}",
        'missing_day 2026-10-11' => 'missing_day:2026-10-11',
        'stuck 2026-10-11' => "stuck:2026-10-11:hold:{$failed}",
        'missing_day 2026-10-12' => 'missing_day:2026-10-12',
    ])
        ->and($again)->toBe($first)
        ->and($afterUnrelated)->toBe($first)
        ->and($afterMarker[$refused])->toBe('refused:2026-10-09:2026-10-09T22:30:00Z:'.substr(sha1('missing_file:groups.json'), 0, 12))
        ->and($afterReason[$refused])->toBe('refused:2026-10-09:2026-10-09T22:30:00Z:'.substr(sha1('bad_document:groups.json'), 0, 12))
        ->and($others($afterMarker))->toBe($others($first))
        ->and($others($afterReason))->toBe($others($first));
});
