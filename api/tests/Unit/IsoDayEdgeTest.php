<?php

declare(strict_types=1);

use App\Support\IsoDay;

// Q1-i5 T03 review tests: the shared day parse at the edges the commands used to meet separately: other digit
// scripts, control characters, the top of the calendar, and a server whose default zone is not UTC.

it('refuses text that only looks like a day: other digit scripts, control characters, longer years and extra parts', function (string $day): void {
    expect(IsoDay::parse($day))->toBeNull()
        ->and(IsoDay::isDay($day))->toBeFalse();
})->with([
    'fullwidth digits' => ["\u{FF12}\u{FF10}\u{FF12}\u{FF16}-02-03"],
    'arabic-indic digits' => ["\u{0662}\u{0660}\u{0662}\u{0666}-02-03"],
    'a carriage return' => ["2026-02-03\r"],
    'a trailing space' => ['2026-02-03 '],
    'a trailing NUL' => ["2026-02-03\0"],
    'a five digit year' => ['12026-02-03'],
    'a slash separator' => ['2026/02/03'],
    'a compact date' => ['20260203'],
    'a plus sign' => ['+2026-02-03'],
    'a day of the year' => ['2026-034'],
    'an hour' => ['2026-02-03T00'],
    'the 31st of April' => ['2026-04-31'],
    'the 29th of February in a century year' => ['1900-02-29'],
    'text' => ['not-a-day'],
]);

it('returns the same UTC midnight whatever the default time zone of the process is', function (string $zone): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set($zone);
    try {
        $parsed = IsoDay::parse('2026-03-08');
    } finally {
        date_default_timezone_set($previous);
    }

    expect($parsed?->getTimestamp())->toBe(gmmktime(0, 0, 0, 3, 8, 2026))
        ->and($parsed?->getTimezone()->getName())->toBe('UTC')
        ->and($parsed?->format('Y-m-d H:i:s'))->toBe('2026-03-08 00:00:00');
})->with([
    'Chicago, on its spring-forward day' => ['America/Chicago'],
    'Auckland, ahead of UTC' => ['Pacific/Auckland'],
    'Kiritimati, UTC+14' => ['Pacific/Kiritimati'],
]);
