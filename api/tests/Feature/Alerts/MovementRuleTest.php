<?php

declare(strict_types=1);

use App\Domain\Alerts\AlertThresholds;
use App\Domain\Pricing\MovementRule;

// Plan Q1-i4 T01 (A5, D4 server half): the movement rule's figures are read from config('pricing.movement') in one
// place, and the alert floor is derived from its percentage there. The ADR 0001 values are not re-decided here.

it('reads the configured rule, percentage and amount', function (): void {
    expect([MovementRule::rule(), MovementRule::pct(), MovementRule::abs()])->toBe(['draft-v1', '5.00', '1.00'])
        ->and(MovementRule::pctText())->toBe('5')
        ->and(MovementRule::alertFloor())->toBe(5)
        ->and(AlertThresholds::MAX)->toBe(100);
});

it('follows a changed config: the text drops trailing zeros and the floor rounds up, never below 1', function (string $pct, string $text, int $floor): void {
    config(['pricing.movement.pct' => $pct, 'pricing.movement.abs' => '2.50', 'pricing.movement.rule' => 'other-v2']);

    expect([MovementRule::rule(), MovementRule::pct(), MovementRule::abs()])->toBe(['other-v2', $pct, '2.50'])
        ->and(MovementRule::pctText())->toBe($text)
        ->and(MovementRule::alertFloor())->toBe($floor);
})->with([
    '7.50' => ['7.50', '7.5', 8],
    '7.00' => ['7.00', '7', 7],
    '5.50' => ['5.50', '5.5', 6],
    '10' => ['10', '10', 10],
    '0.40' => ['0.40', '0.4', 1],
]);
