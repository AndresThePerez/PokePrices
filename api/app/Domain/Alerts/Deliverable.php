<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The day DeliverableDay allows (plan P5-i1 D2): its close day and the price_events id ceiling recorded at the
 * day's first dispatch (null when the day had no event, so nothing of it is ever delivered).
 */
final readonly class Deliverable
{
    public function __construct(
        public string $day,
        public ?int $ceiling,
    ) {}
}
