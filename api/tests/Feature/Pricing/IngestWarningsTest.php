<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\StartDayIngest;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// The scheduled start of a day says so when it cannot ingest it: `ingest_refused` (warning) when the folder is refused,
// once per day, marker and reason however often the pending scan retries the folder, and `ingest_blocked` (warning)
// when a run left running blocks the day, once per run.

/** A writable copy of one fixture day under a scratch root that afterEach removes. */
function iwCopyDay(string $day): string
{
    $root = storage_path('framework/testing/pp-iw-'.bin2hex(random_bytes(6)));
    $from = base_path("tests/Fixtures/tcgcsv-days/snapshots/{$day}");
    mkdir("{$root}/{$day}", 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
    }
    iwRoots($root);

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function iwRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Runs the queued start of the day as the worker does, at $at, and returns the job. */
function iwStart(string $root, string $day, string $at): StartDayIngest
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    $job = new StartDayIngest($day, $root, false, 'sync');
    $job->handle();

    return $job;
}

/** @return list<array<string, mixed>> the contexts of the warnings logged under $message since the test began */
function iwWarnings(string $message, ?MessageLogged $add = null, bool $reset = false): array
{
    static $logged = [];
    if ($reset) {
        $logged = [];
    }
    if ($add !== null) {
        $logged[] = $add;
    }

    return array_values(array_map(fn (MessageLogged $e): array => $e->context,
        array_filter($logged, fn (MessageLogged $e): bool => $e->level === 'warning' && $e->message === $message)));
}

/** @return list<string> every message logged under $level since the test began */
function iwMessages(string $level, ?MessageLogged $add = null, bool $reset = false): array
{
    static $logged = [];
    if ($reset) {
        $logged = [];
    }
    if ($add !== null) {
        $logged[] = $add;
    }

    return array_values(array_map(fn (MessageLogged $e): string => $e->message,
        array_filter($logged, fn (MessageLogged $e): bool => $e->level === $level)));
}

beforeEach(function (): void {
    iwWarnings('', null, true);
    iwMessages('', null, true);
    Event::listen(MessageLogged::class, function (MessageLogged $e): void {
        iwWarnings('', $e);
        iwMessages('', $e);
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    foreach (iwRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

it('warns ingest_refused once for a refused folder however often it is retried, and again for a new reason', function (): void {
    $root = iwCopyDay('2026-10-12');
    $prices = "{$root}/2026-10-12/prices/604.json.zst";
    $bytes = (string) file_get_contents($prices);
    unlink($prices);

    iwStart($root, '2026-10-12', '2026-10-12 21:40:00');
    $first = iwWarnings('ingest_refused');
    // The pending scan retries an unchanged refused folder once a day: two more refused rows, no second warning.
    iwStart($root, '2026-10-12', '2026-10-13 21:40:00');
    iwStart($root, '2026-10-12', '2026-10-14 21:40:00');
    $retried = iwWarnings('ingest_refused');

    // Another file breaks: a new reason for the same day and marker.
    file_put_contents($prices, $bytes);
    unlink("{$root}/2026-10-12/groups.json");
    iwStart($root, '2026-10-12', '2026-10-15 21:40:00');
    iwStart($root, '2026-10-12', '2026-10-16 21:40:00');

    expect($first)->toBe([['day' => '2026-10-12', 'marker' => '2026-10-12T20:05:44Z', 'error' => 'missing_file:prices/604.json.zst']])
        ->and($retried)->toBe($first)
        ->and(iwWarnings('ingest_refused'))->toBe([
            ['day' => '2026-10-12', 'marker' => '2026-10-12T20:05:44Z', 'error' => 'missing_file:prices/604.json.zst'],
            ['day' => '2026-10-12', 'marker' => '2026-10-12T20:05:44Z', 'error' => 'missing_file:groups.json'],
        ])
        ->and(DB::table('ingest_runs')->where('day', '2026-10-12')->where('status', 'refused')->count())->toBe(5);
});

it('warns ingest_refused again when the same reason comes back under a new marker, and for each day on its own', function (): void {
    $root = iwCopyDay('2026-10-12');
    unlink("{$root}/2026-10-12/groups.json");

    iwStart($root, '2026-10-12', '2026-10-12 21:40:00');
    // The folder is collected again: a later marker on the same date, the same file still absent.
    file_put_contents("{$root}/2026-10-12/marker.txt", "2026-10-12T22:30:00+0000\n");
    iwStart($root, '2026-10-12', '2026-10-12 23:40:00');
    iwStart($root, '2026-10-12', '2026-10-13 23:40:00');
    // A day with no folder at all is refused before any marker is read.
    iwStart($root, '2026-10-13', '2026-10-13 23:40:00');
    iwStart($root, '2026-10-13', '2026-10-14 23:40:00');

    expect(iwWarnings('ingest_refused'))->toBe([
        ['day' => '2026-10-12', 'marker' => '2026-10-12T20:05:44Z', 'error' => 'missing_file:groups.json'],
        ['day' => '2026-10-12', 'marker' => '2026-10-12T22:30:00Z', 'error' => 'missing_file:groups.json'],
        ['day' => '2026-10-13', 'marker' => null, 'error' => 'missing_day:2026-10-13'],
    ]);
});

it('warns ingest_blocked once per running run that blocks its day, naming the day and the run', function (): void {
    $root = iwCopyDay('2026-10-12');
    $insert = fn (string $marker): int => DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-12', 'marker' => $marker, 'mode' => 'queued',
        'status' => 'running', 'started_at' => '2026-10-12T19:40:00Z', 'counts' => '{}',
    ]);
    // A run of an earlier marker of the day never finished; the folder holds a newer marker now.
    $stale = $insert('2026-10-12T19:30:00Z');

    iwStart($root, '2026-10-12', '2026-10-12 22:40:00');
    iwStart($root, '2026-10-12', '2026-10-12 23:40:00');
    iwStart($root, '2026-10-12', '2026-10-13 00:40:00');
    $once = iwWarnings('ingest_blocked');
    $rowsAfter = DB::table('ingest_runs')->count();

    // That run is cleared and another one is left running: a new cause.
    DB::table('ingest_runs')->where('id', $stale)->update(['status' => 'superseded']);
    $other = $insert('2026-10-12T19:45:00Z');
    iwStart($root, '2026-10-12', '2026-10-13 01:40:00');
    iwStart($root, '2026-10-12', '2026-10-13 02:40:00');

    expect($once)->toBe([['day' => '2026-10-12', 'run_id' => $stale]])
        ->and($rowsAfter)->toBe(1)
        ->and(iwWarnings('ingest_blocked'))->toBe([
            ['day' => '2026-10-12', 'run_id' => $stale],
            ['day' => '2026-10-12', 'run_id' => $other],
        ])
        // The blocked start no longer speaks at info.
        ->and(array_values(array_filter(iwMessages('info'), fn (string $m): bool => str_contains($m, 'running run'))))->toBe([])
        ->and(DB::table('price_points')->count())->toBe(0);
});
