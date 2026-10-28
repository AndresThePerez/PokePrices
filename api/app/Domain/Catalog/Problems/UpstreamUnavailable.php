<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch did not answer and no last-good copy exists: 503 upstream-unavailable with Retry-After in whole seconds, at least 1. */
final class UpstreamUnavailable extends HttpException implements ProblemType
{
    public function __construct(int $retryAfterSeconds, ?Throwable $previous = null)
    {
        parent::__construct(503, 'PokéSearch is unavailable.', $previous, ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }

    public function slug(): string
    {
        return 'upstream-unavailable';
    }

    public function title(): string
    {
        return 'Upstream Unavailable';
    }

    public function extra(): array
    {
        return [];
    }
}
