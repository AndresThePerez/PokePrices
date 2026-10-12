<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch answered outside its contract (unexpected status or shape): 502 upstream-contract (F-P1-027). */
final class UpstreamContract extends HttpException
{
    public function __construct(string $message = 'PokéSearch answered outside its contract.', ?Throwable $previous = null)
    {
        parent::__construct(502, $message, $previous);
    }
}
