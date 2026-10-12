<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch has no such card: 404 card-not-found; catalogMissing is true when PokéPrices still holds variant rows for it (F-P1-027). */
final class CardNotFound extends HttpException
{
    public function __construct(public readonly bool $catalogMissing = false, ?Throwable $previous = null)
    {
        parent::__construct(404, 'Card not found.', $previous);
    }
}
