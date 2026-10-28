<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * One catalog answer from CatalogRepository and how honest it is.
 *
 * cached: the data comes from the catalog cache, not from a PokéSearch call this read made (a stale copy
 * refreshed in the background still counts as cached). degraded: PokéSearch failed and this is the
 * last-good copy, fetched at staleSince (ISO 8601, UTC); a degraded answer is always cached.
 */
final readonly class CatalogResult
{
    /** @param array<mixed> $data the decoded PokéSearch answer */
    public function __construct(
        public array $data,
        public bool $cached,
        public bool $degraded = false,
        public ?string $staleSince = null,
    ) {}
}
