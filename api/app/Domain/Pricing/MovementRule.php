<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The movement rule (ADR 0001, `draft-v1`: at least 5 % and at least $1.00 between two daily closes), read from
 * config('pricing.movement') in this one place. Detection, the volume grid, the notification texts and the alert
 * floor all take its figures from here, never from a literal.
 */
final class MovementRule
{
    /** The rule's name, e.g. 'draft-v1'. */
    public static function rule(): string
    {
        return (string) config('pricing.movement.rule');
    }

    /** The smallest abs(change_pct) that fires, as the decimal text '5.00'. */
    public static function pct(): string
    {
        return (string) config('pricing.movement.pct');
    }

    /** The smallest abs(change) that fires, as the decimal text '1.00'. */
    public static function abs(): string
    {
        return (string) config('pricing.movement.abs');
    }

    /** The percentage without trailing zeros: '5.00' gives '5', '7.50' gives '7.5'. */
    public static function pctText(): string
    {
        $pct = self::pct();

        return str_contains($pct, '.') ? rtrim(rtrim($pct, '0'), '.') : $pct;
    }

    /** The smallest alert threshold: the percentage rounded up, at least 1 (no event exists below it). */
    public static function alertFloor(): int
    {
        $pct = self::pct();

        return max(1, (int) ceil(is_numeric($pct) ? (float) $pct : 5.0));
    }
}
