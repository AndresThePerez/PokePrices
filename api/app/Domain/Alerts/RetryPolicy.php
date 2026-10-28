<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Attributes\Config;
use Throwable;

/**
 * Retry delays: after failed attempt n, d = min(cap, base x factor^(n-1)) seconds (30, 120, 480,
 * 1920, 3600 by default), with equal jitter intdiv(d, 2) + random(0, intdiv(d, 2)). A Retry-After larger than that
 * raises the delay, never above the cap. The randomness is injectable so tests can pin either end.
 */
final class RetryPolicy
{
    /** @var Closure(int, int): int */
    private readonly Closure $random;

    /** @param (Closure(int, int): int)|null $random */
    public function __construct(
        #[Config('alerts.delivery.base_seconds', 30)] private readonly int $baseSeconds = 30,
        #[Config('alerts.delivery.factor', 4)] private readonly int $factor = 4,
        #[Config('alerts.delivery.cap_seconds', 3600)] private readonly int $capSeconds = 3600,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? fn (int $min, int $max): int => random_int($min, $max);
    }

    /** Seconds to wait after failed attempt $attempt (1-based), raised to $retryAfter (capped) when larger. */
    public function delay(int $attempt, ?int $retryAfter = null): int
    {
        $d = $this->capSeconds;
        $step = $this->baseSeconds;
        for ($n = 1; $n < max(1, $attempt) && $step < $this->capSeconds; $n++) {
            $step *= $this->factor;
        }
        $d = min($d, $step);
        $half = intdiv($d, 2);
        $delay = $half + min($half, max(0, ($this->random)(0, $half)));

        if ($retryAfter !== null && $retryAfter > $delay) {
            return min($this->capSeconds, $retryAfter);
        }

        return $delay;
    }

    /** A Retry-After header as seconds from $now: delta-seconds or an HTTP date (past dates give 0); else null. */
    public static function retryAfter(?string $header, CarbonImmutable $now): ?int
    {
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/^[0-9]{1,10}$/D', $header) === 1) {
            return (int) $header;
        }
        if (preg_match('/^[A-Za-z]{3}, [0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT$/D', $header) !== 1) {
            return null;
        }
        try {
            $at = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header, 'UTC');
        } catch (Throwable) {
            return null;
        }
        if (! $at instanceof CarbonImmutable) {
            return null;
        }

        return max(0, (int) $now->diffInSeconds($at, false));
    }
}
