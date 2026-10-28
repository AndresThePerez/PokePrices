<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Q1-i5 T03 review tests: the five commands that share IsoDay each keep their own error text for a bad day (no
// earlier test pins them), refuse year 0000, and let a good day through to the next check of their own.

/** @return array{int, string} the exit code and the output of an Artisan call */
function isoDayRun(string $command, array $options): array
{
    $code = Artisan::call($command, $options);

    return [$code, trim(Artisan::output())];
}

beforeEach(function (): void {
    $this->gatesOut = getenv('GATES_OUT');
    putenv('GATES_OUT');
    $this->snapshots = sys_get_temp_dir().'/q1-i5-t03-'.bin2hex(random_bytes(6));
    mkdir($this->snapshots);
});

afterEach(function (): void {
    rmdir($this->snapshots);
    if (is_string($this->gatesOut)) {
        putenv('GATES_OUT='.$this->gatesOut);
    }
});

it('refuses a bad --day or --days with the command\'s own text and exit 1, year 0000 and the 30th of February included', function (string $day): void {
    $dir = $this->snapshots;
    $oracle = isoDayRun('pokeprices:oracle:fetch', ['--day' => $day, '--snapshots' => $dir]);
    $map = isoDayRun('pokeprices:map:build', ['--day' => $day, '--dataset' => $dir, '--snapshots' => $dir]);
    $ingest = isoDayRun('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $dir]);
    $replay = isoDayRun('pokeprices:prices:replay', ['--days' => '2026-02-03,'.$day, '--fresh' => true, '--snapshots' => $dir]);
    $reset = isoDayRun('pokeprices:alerts:dispatch', ['--reset-watermark' => $day]);

    expect($oracle)->toBe([1, '--day must be a date in the form YYYY-MM-DD'])
        ->and($map)->toBe([1, '--day must be a date in the form YYYY-MM-DD'])
        ->and($ingest)->toBe([1, '--day must be a date in the form YYYY-MM-DD'])
        ->and($replay)->toBe([1, "--days: {$day} is not a day in the form YYYY-MM-DD"])
        ->and($reset)->toBe([1, '--reset-watermark needs a date as YYYY-MM-DD; nothing was deleted.']);
})->with([
    'year 0000' => ['0000-01-01'],
    'the 30th of February' => ['2026-02-30'],
    'an unpadded day' => ['2026-2-3'],
    'a timestamp' => ['2026-02-03T00:00'],
    'fullwidth digits' => ["\u{FF12}\u{FF10}\u{FF12}\u{FF16}-02-03"],
]);

it('lets a good day, the leap day included, past the day check to each command\'s next refusal', function (string $day): void {
    $dir = $this->snapshots;

    $oracle = isoDayRun('pokeprices:oracle:fetch', ['--day' => $day, '--snapshots' => $dir]);
    $map = isoDayRun('pokeprices:map:build', ['--day' => $day, '--dataset' => $dir.'/missing', '--snapshots' => $dir]);
    $ingest = isoDayRun('pokeprices:prices:ingest', ['--day' => $day, '--snapshots' => $dir]);
    $replay = isoDayRun('pokeprices:prices:replay', ['--days' => $day, '--snapshots' => $dir]);

    expect($oracle[0])->toBe(1)
        ->and($oracle[1])->toBe("{$dir}/{$day}/marker.txt is missing or unreadable; the oracle is recorded against a collected day")
        ->and($map)->toBe([1, "--dataset {$dir}/missing is not a directory"])
        ->and($ingest)->toBe([1, 'choose one mode: --sync, --queued or --resume'])
        ->and($replay[0])->toBe(1)
        ->and($replay[1])->toStartWith('replay needs --fresh:');
})->with([
    'a leap day' => ['2024-02-29'],
    'an ordinary day' => ['2026-10-12'],
]);
