<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch answered outside its contract (unexpected status or shape): 502 upstream-contract (F-P1-027). */
final class UpstreamContract extends HttpException implements ProblemType
{
    public function __construct(string $message = 'PokéSearch answered outside its contract.', ?Throwable $previous = null)
    {
        parent::__construct(502, $message, $previous);
    }

    public function slug(): string
    {
        return 'upstream-contract';
    }

    public function title(): string
    {
        return 'Upstream Contract Violation';
    }

    public function extra(): array
    {
        return [];
    }
}
