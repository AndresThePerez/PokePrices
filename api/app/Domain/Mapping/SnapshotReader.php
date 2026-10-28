<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Reads one collected TCGCSV day, `<snapshots>/<day>/{SUCCESS, marker.txt, groups.json, products/<g>.json.zst,
 * prices/<g>.json.zst}`. It only ever opens files for reading.
 *
 * pecl-zstd returns "" or partial data for a stream cut mid-frame instead of false, so a non-false
 * return proves nothing: every file must decode to one whole JSON object with `success: true` and a `results`
 * list, and a products file's `totalItems`, when present, must equal its result count. A group with no products
 * is legitimate (the real day has empty groups, written without totalItems) only when its JSON says so in full.
 * Prices leave this class as (productId, subTypeName) pairs only: no price value is ever returned.
 */
final readonly class SnapshotReader
{
    /** @param array<int, string> $groups groupId => name, in groups.json order */
    private function __construct(
        public string $dir,
        public CarbonImmutable $marker,
        public array $groups,
    ) {}

    /** @throws RuntimeException when the day is incomplete or unreadable; the message names the file */
    public static function open(string $snapshotsRoot, string $day): self
    {
        $dir = rtrim($snapshotsRoot, '/').'/'.$day;
        if (! is_dir($dir)) {
            throw new RuntimeException("snapshot day {$dir} does not exist");
        }
        if (! is_file($dir.'/SUCCESS')) {
            throw new RuntimeException("snapshot day {$dir} has no SUCCESS file; the collection did not finish");
        }

        $markerText = is_readable($dir.'/marker.txt') ? trim((string) file_get_contents($dir.'/marker.txt')) : '';
        $marker = self::parseMarker($markerText);
        if ($marker === null) {
            throw new RuntimeException("snapshot day {$dir} has an unreadable marker.txt");
        }

        $document = self::decodeJson((string) @file_get_contents($dir.'/groups.json'), $dir.'/groups.json');
        $groups = [];
        foreach ($document['results'] as $row) {
            if (! is_array($row) || ! is_int($row['groupId'] ?? null) || ! is_string($row['name'] ?? null)) {
                throw new RuntimeException("{$dir}/groups.json has a group without an integer groupId and a name");
            }
            $groups[$row['groupId']] = $row['name'];
        }
        foreach (array_keys($groups) as $groupId) {
            foreach (['products', 'prices'] as $kind) {
                if (! is_file("{$dir}/{$kind}/{$groupId}.json.zst")) {
                    throw new RuntimeException("snapshot day {$dir} has no {$kind} file for group {$groupId}");
                }
            }
        }

        return new self($dir, $marker, $groups);
    }

    /**
     * The products of one group: productId, name and the `Number` extended-data value (null for sealed products).
     *
     * @return list<array{productId: int, name: string, number: string|null}>
     */
    public function products(int $groupId): array
    {
        $path = "{$this->dir}/products/{$groupId}.json.zst";
        $document = self::decodeZstd($path);
        $total = $document['totalItems'] ?? count($document['results']);
        if ($total !== count($document['results'])) {
            throw new RuntimeException("{$path} lists a totalItems that does not match its results");
        }

        $out = [];
        foreach ($document['results'] as $row) {
            if (! is_array($row) || ! is_int($row['productId'] ?? null) || ! is_string($row['name'] ?? null)) {
                throw new RuntimeException("{$path} has a product without an integer productId and a name");
            }
            $number = null;
            foreach (is_array($row['extendedData'] ?? null) ? $row['extendedData'] : [] as $field) {
                if (is_array($field) && ($field['name'] ?? null) === 'Number' && is_string($field['value'] ?? null)) {
                    $number = $field['value'];
                }
            }
            $out[] = ['productId' => $row['productId'], 'name' => $row['name'], 'number' => $number];
        }

        return $out;
    }

    /**
     * The priced (productId, subTypeName) pairs of one group, first occurrence kept. The price fields are not read.
     *
     * @return list<array{productId: int, subTypeName: string}>
     */
    public function priceRows(int $groupId): array
    {
        $path = "{$this->dir}/prices/{$groupId}.json.zst";
        $out = [];
        $seen = [];
        foreach (self::decodeZstd($path)['results'] as $row) {
            if (! is_array($row) || ! is_int($row['productId'] ?? null) || ! is_string($row['subTypeName'] ?? null)) {
                throw new RuntimeException("{$path} has a price row without an integer productId and a subTypeName");
            }
            $key = $row['productId'].'|'.$row['subTypeName'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['productId' => $row['productId'], 'subTypeName' => $row['subTypeName']];
            }
        }

        return $out;
    }

    private static function parseMarker(string $text): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/', $text) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($text)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{results: list<mixed>, totalItems?: mixed} */
    private static function decodeZstd(string $path): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("{$path} is empty or unreadable");
        }
        $plain = @zstd_uncompress($bytes);
        if (! is_string($plain) || $plain === '') {
            throw new RuntimeException("{$path} does not decode as zstd");
        }

        return self::decodeJson($plain, $path);
    }

    /** @return array{results: list<mixed>, totalItems?: mixed} */
    private static function decodeJson(string $json, string $path): array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$path} is not one whole JSON document: {$e->getMessage()}", 0, $e);
        }
        if (! is_array($document) || ($document['success'] ?? null) !== true
            || ! is_array($document['results'] ?? null) || ! array_is_list($document['results'])) {
            throw new RuntimeException("{$path} is not a successful TCGCSV document with a results list");
        }

        /** @var array{results: list<mixed>, totalItems?: mixed} $document */
        return $document;
    }
}
