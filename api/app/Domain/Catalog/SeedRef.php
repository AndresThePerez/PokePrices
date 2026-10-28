<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Problems\UpstreamContract;

/**
 * Which pokemon-tcg-data snapshot the PokéSearch index holds (plan T42): the seed object of GET /api/meta.
 *
 * The ref is the cache key segment for every catalog answer, so a reseed moves every key at once; an index
 * seeded before provenance stamping reports seed: null and keys under the segment "unseeded".
 */
final readonly class SeedRef
{
    public const string UNSEEDED = 'unseeded';

    /** A ref becomes part of a Redis key: a commit sha in practice, never a colon or whitespace. */
    private const string REF_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    public function __construct(public ?string $ref, public ?string $seededAt) {}

    /**
     * @param  array<mixed>  $meta  a decoded GET /api/meta answer
     *
     * @throws UpstreamContract when seed is neither null nor an object with a usable ref and seeded_at
     */
    public static function fromMeta(array $meta): self
    {
        $seed = $meta['seed'] ?? null;
        if ($seed === null) {
            return new self(null, null);
        }
        if (! is_array($seed)) {
            throw new UpstreamContract('PokéSearch meta has a seed that is not an object.');
        }

        $ref = $seed['ref'] ?? null;
        $seededAt = $seed['seeded_at'] ?? null;
        if (! is_string($ref) || ($ref !== '' && preg_match(self::REF_PATTERN, $ref) !== 1)) {
            throw new UpstreamContract('PokéSearch meta has a seed ref that cannot key the catalog cache.');
        }
        if ($seededAt !== null && ! is_string($seededAt)) {
            throw new UpstreamContract('PokéSearch meta has a seeded_at that is not a string.');
        }

        return new self($ref === '' ? null : $ref, $seededAt === '' ? null : $seededAt);
    }

    /** A copy read back from the cache, or null when it is not one this class wrote. */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! array_key_exists('ref', $value) || ! array_key_exists('seeded_at', $value)) {
            return null;
        }
        $ref = $value['ref'];
        $seededAt = $value['seeded_at'];
        if (($ref !== null && (! is_string($ref) || preg_match(self::REF_PATTERN, $ref) !== 1)) || ($seededAt !== null && ! is_string($seededAt))) {
            return null;
        }

        return new self($ref, $seededAt);
    }

    /** @return array{ref: string|null, seeded_at: string|null} */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'seeded_at' => $this->seededAt];
    }

    /** The cache key segment: the ref, or "unseeded". */
    public function segment(): string
    {
        return $this->ref ?? self::UNSEEDED;
    }
}
