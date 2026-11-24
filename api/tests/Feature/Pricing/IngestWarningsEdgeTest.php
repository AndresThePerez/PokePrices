<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\IngestPending;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// The warnings of a scheduled start, read through the hourly pending scan itself: `ingest_refused` once for one
// folder, marker and reason however many scans pass, and no warning at all for a day that is in order.

/** A writable copy of one fixture day under a scratch root that afterEach removes. */
function iwxCopyDay(string $day): string
{
    $root = storage_path('framework/testing/pp-iwx-'.bin2hex(random_bytes(6)));
    $from = base_path("tests/Fixtures/tcgcsv-days/snapshots/{$day}");
    mkdir("{$root}/{$day}", 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
    }
    iwxRoots($root);

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function iwxRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/**
 * Runs the pending scan at $at, its starts inline as the worker would run them, and returns the days it started.
 *
 * @return list<string>
 */
function iwxScan(string $root, string $at): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));

    return (new IngestPending($root, 'sync'))->scan()['dispatched'];
}

/** @return list<MessageLogged> every warning logged since the test began */
function iwxWarnings(?MessageLogged $add = null, bool $reset = false): array
{
    static $logged = [];
    if ($reset) {
        $logged = [];
    }
    if ($add !== null && $add->level === 'warning') {
        $logged[] = $add;
    }

    return $logged;
}

/** @return list<array<string, mixed>> the contexts of the warnings logged under $message */
function iwxContexts(string $message): array
{
    return array_values(array_map(fn (MessageLogged $e): array => $e->context,
        array_filter(iwxWarnings(), fn (MessageLogged $e): bool => $e->message === $message)));
}

beforeEach(function (): void {
    iwxWarnings(null, true);
    Event::listen(MessageLogged::class, function (MessageLogged $e): void {
        iwxWarnings($e);
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    foreach (iwxRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

it('warns ingest_refused once across three hourly scans and the next day\'s retry of one folder, and again once the folder carries a new marker', function (): void {
    $root = iwxCopyDay('2026-10-12');
    $day = "{$root}/2026-10-12";
    unlink("{$day}/prices/604.json.zst");
    $fault = 'missing_file:prices/604.json.zst';
    $refusedRows = fn (): int => DB::table('ingest_runs')->where('day', '2026-10-12')->where('status', 'refused')->count();

    $started = [
        iwxScan($root, '2026-10-12 21:40:00'),
        iwxScan($root, '2026-10-12 22:40:00'),
        iwxScan($root, '2026-10-12 23:40:00'),
    ];
    $afterThreeScans = [iwxContexts('ingest_refused'), $refusedRows()];

    // A day later the scan retries the unchanged folder: a second refused run, no second warning.
    $started[] = iwxScan($root, '2026-10-13 21:41:00');
    $afterRetry = [iwxContexts('ingest_refused'), $refusedRows()];

    // The collector writes the folder again, the same file still absent: marker.txt and the manifest carry the new marker.
    $manifest = json_decode((string) file_get_contents("{$day}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    $manifest['marker'] = '2026-10-12T22:30:00+0000';
    file_put_contents("{$day}/manifest.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$day}/marker.txt", "2026-10-12T22:30:00+0000\n");
    $started[] = iwxScan($root, '2026-10-13 22:40:00');
    $started[] = iwxScan($root, '2026-10-13 23:40:00');

    $first = ['day' => '2026-10-12', 'marker' => '2026-10-12T20:05:44Z', 'error' => $fault];
    expect($started)->toBe([['2026-10-12'], [], [], ['2026-10-12'], ['2026-10-12'], []])
        ->and($afterThreeScans)->toBe([[$first], 1])
        ->and($afterRetry)->toBe([[$first], 2])
        ->and(iwxContexts('ingest_refused'))->toBe([$first, ['day' => '2026-10-12', 'marker' => '2026-10-12T22:30:00Z', 'error' => $fault]])
        ->and($refusedRows())->toBe(3)
        ->and(iwxContexts('ingest_blocked'))->toBe([])
        ->and(json_encode(iwxContexts('ingest_refused'), JSON_THROW_ON_ERROR))->not->toContain($root);
});

it('logs no warning when the scan starts a day that is in order and then finds it unchanged', function (): void {
    $root = iwxCopyDay('2026-10-12');

    $started = [iwxScan($root, '2026-10-12 21:40:00'), iwxScan($root, '2026-10-12 22:40:00')];

    expect($started)->toBe([['2026-10-12'], []])
        ->and(DB::table('ingest_runs')->where('day', '2026-10-12')->pluck('status')->all())->toBe(['ok'])
        ->and(DB::table('ingest_run_chunks')->where('status', '<>', 'done')->count())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->count())->toBeGreaterThan(0)
        ->and(array_map(fn (MessageLogged $e): string => $e->message, iwxWarnings()))->toBe([]);
});
