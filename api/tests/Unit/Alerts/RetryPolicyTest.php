<?php

declare(strict_types=1);

use App\Domain\Alerts\RetryPolicy;
use Carbon\CarbonImmutable;

// Plan P5-i1 T02 criterion 3 (D7): exponential delays (base 30 s, factor 4, cap 3600 s) with equal jitter, the
// randomness injected; a Retry-After raises the delay up to the cap.

function p5Policy(string $at): RetryPolicy
{
    return new RetryPolicy(30, 4, 3600, fn (int $min, int $max): int => $at === 'min' ? $min : $max);
}

it('gives the equal-jitter delays at the bounds of the randomness', function (): void {
    $min = p5Policy('min');
    $max = p5Policy('max');

    expect(array_map(fn (int $n): int => $min->delay($n), [1, 2, 3, 4, 5]))->toBe([15, 60, 240, 960, 1800])
        ->and(array_map(fn (int $n): int => $max->delay($n), [1, 2, 3, 4, 5]))->toBe([30, 120, 480, 1920, 3600])
        ->and($min->delay(6))->toBe(1800)
        ->and($max->delay(6))->toBe(3600)
        ->and($max->delay(40))->toBe(3600);
});

it('stays inside the jitter bounds with the default randomness', function (): void {
    $policy = new RetryPolicy(30, 4, 3600);
    for ($i = 0; $i < 50; $i++) {
        expect($policy->delay(2))->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(120);
    }
});

it('raises the delay to a larger Retry-After, never above the cap', function (): void {
    $policy = p5Policy('max');

    expect($policy->delay(1, 90))->toBe(90)
        ->and($policy->delay(1, 10))->toBe(30)
        ->and($policy->delay(1, 99999))->toBe(3600)
        ->and($policy->delay(2, null))->toBe(120);
});

it('reads Retry-After as seconds or as an HTTP date', function (?string $header, ?int $seconds): void {
    expect(RetryPolicy::retryAfter($header, CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC')))->toBe($seconds);
})->with([
    'seconds' => ['90', 90],
    'zero' => ['0', 0],
    'http date' => ['Thu, 15 Oct 2026 12:02:00 GMT', 120],
    'date in the past' => ['Thu, 15 Oct 2026 11:00:00 GMT', 0],
    'missing' => [null, null],
    'garbage' => ['soon', null],
    'negative' => ['-5', null],
]);
