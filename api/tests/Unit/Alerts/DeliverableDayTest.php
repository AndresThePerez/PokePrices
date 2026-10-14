<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverableDay;

// Plan P5-i1 T01 criterion 4 (D2, R192): the deliverable-day predicate as a truth table over (newest, floor,
// watermark). A newest day must exist and be no older than the floor (the newest day ever finalized) or the
// watermark (the newest day any dispatch ran for).

it('decides whether the newest finalized day may be delivered', function (?string $newest, ?string $floor, ?string $watermark, bool $allowed): void {
    expect(DeliverableDay::allows($newest, $floor, $watermark))->toBe($allowed);
})->with([
    'nothing finalized' => [null, null, null, false],
    'first start, no floor or watermark' => ['2026-10-12', null, null, true],
    'all three equal' => ['2026-10-12', '2026-10-12', '2026-10-12', true],
    'a new day after the watermark' => ['2026-10-13', '2026-10-13', '2026-10-12', true],
    'newest older than the watermark' => ['2026-10-11', '2026-10-11', '2026-10-12', false],
    'repair in progress, no watermark' => ['2026-10-11', '2026-10-12', null, false],
    'repair in progress above the watermark' => ['2026-10-11', '2026-10-12', '2026-10-11', false],
    'no newest day, floor and watermark set' => [null, '2026-10-12', '2026-10-12', false],
]);
