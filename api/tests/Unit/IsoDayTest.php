<?php

declare(strict_types=1);

use App\Support\IsoDay;
use Carbon\CarbonImmutable;

// Q1-i5 T03: the one parse of a collected day (YYYY-MM-DD, a real calendar day) the commands share.

it('refuses a day that is not a real calendar day in the form YYYY-MM-DD', function (string $day): void {
    expect(IsoDay::parse($day))->toBeNull()
        ->and(IsoDay::isDay($day))->toBeFalse();
})->with([
    'the 30th of February' => ['2026-02-30'],
    'unpadded month and day' => ['2026-2-3'],
    'a trailing newline' => ["2026-02-03\n"],
    'year 0000' => ['0000-01-01'],
    'month 13' => ['2026-13-01'],
    'day 00' => ['2026-01-00'],
    'a timestamp' => ['2026-02-03T00:00:00Z'],
    'empty' => [''],
    'a leading space' => [' 2026-02-03'],
    'a non-leap 29th of February' => ['2026-02-29'],
]);

it('parses a real day, the leap day included, to UTC midnight', function (string $day): void {
    $parsed = IsoDay::parse($day);

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class)
        ->and($parsed?->format('Y-m-d H:i:s'))->toBe($day.' 00:00:00')
        ->and($parsed?->getTimezone()->getName())->toBe('UTC')
        ->and(IsoDay::isDay($day))->toBeTrue();
})->with([
    'the leap day' => ['2024-02-29'],
    'a collected day' => ['2026-10-12'],
    'year 0001' => ['0001-01-01'],
    'the last day of a year' => ['2025-12-31'],
]);
