<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch did not answer and no last-good copy exists: 503 upstream-unavailable with Retry-After in whole seconds, at least 1 (F-P1-027). */
final class UpstreamUnavailable extends HttpException
{
    public function __construct(int $retryAfterSeconds, ?Throwable $previous = null)
    {
        parent::__construct(503, 'PokéSearch is unavailable.', $previous, ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }
}
