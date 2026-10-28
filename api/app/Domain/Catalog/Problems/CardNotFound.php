<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** PokéSearch has no such card: 404 card-not-found; catalogMissing is true when PokéPrices still holds variant rows for it. */
final class CardNotFound extends HttpException implements ProblemType
{
    public function __construct(public readonly bool $catalogMissing = false, ?Throwable $previous = null)
    {
        parent::__construct(404, 'Card not found.', $previous);
    }

    public function slug(): string
    {
        return 'card-not-found';
    }

    public function title(): string
    {
        return 'Card Not Found';
    }

    public function extra(): array
    {
        return ['catalog_missing' => $this->catalogMissing];
    }
}
