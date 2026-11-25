<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

/**
 * Raised inside a search fill to leave the cache without an entry: the answer names a page past its own last page,
 * so it is handed back once and never stored. CatalogRepository::search() catches it; it never leaves the class.
 */
final class PastTheLastPage extends RuntimeException
{
    /** @param array<mixed> $data the decoded PokéSearch answer */
    public function __construct(public readonly array $data)
    {
        parent::__construct('The search answer names a page past its last page.');
    }
}
