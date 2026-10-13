<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use JsonException;
use Throwable;
use UnexpectedValueException;

/**
 * Reads the prices of one collected TCGCSV day, `<snapshots>/<day>/{SUCCESS, marker.txt, manifest.json, groups.json,
 * products/<g>.json.zst, prices/<g>.json.zst}` (plan P2-i1 T04, D1, D5). It only ever opens files for reading.
 *
 * validate() checks the whole day before anything is written and throws DayRefused naming the first problem. rows()
 * decodes one group's prices file: every row must carry an integer productId, a string subTypeName and all five price
 * fields, each a JSON number or null that PriceNormalizer::money() accepts. Rows are then de-duplicated on
 * (productId, subTypeName), first occurrence kept (as App\Domain\Mapping\SnapshotReader does), so chunk offsets
 * index the de-duplicated list and no single upsert ever sees one key twice (review F10).
 *
 * A number written with an exponent (`1e2`) is no plain decimal price: json_decode would turn it into an exact
 * float, so it is rewritten into an object before decoding and money() then refuses it like any other non-number.
 */
final class PriceSnapshotReader
{
    /** JSON field => price_points column. */
    public const array PRICE_FIELDS = [
        'marketPrice' => 'market',
        'lowPrice' => 'low',
        'midPrice' => 'mid',
        'highPrice' => 'high',
        'directLowPrice' => 'direct_low',
    ];

    private ?int $cachedGroup = null;

    /** @var array{rows: list<array{productId: int, subTypeName: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>, duplicates: int} */
    private array $cached = ['rows' => [], 'duplicates' => 0];

    private function __construct(public readonly string $dir, public readonly string $day) {}

    /** A reader of <root>/<day>; nothing is read until validate(), marker() or rows() is called. */
    public static function at(string $snapshotsRoot, string $day): self
    {
        return new self(rtrim($snapshotsRoot, '/').'/'.$day, $day);
    }

    /**
     * Validates every file of the day (T04 item 1) and returns the marker, each group's de-duplicated row count in
     * groups.json order, and the day's totals.
     *
     * @return array{marker: CarbonImmutable, groups: array<int, int>, rows: int, duplicate_rows: int}
     *
     * @throws DayRefused
     */
    public function validate(): array
    {
        if (! is_dir($this->dir)) {
            throw new DayRefused("missing_day:{$this->day}");
        }
        if (! is_file($this->dir.'/SUCCESS')) {
            throw new DayRefused('missing_file:SUCCESS');
        }
        $marker = $this->marker();
        if ($marker->format('Y-m-d') !== $this->day) {
            throw new DayRefused('marker_day_mismatch:marker.txt', $marker);
        }

        if (! is_file($this->dir.'/manifest.json')) {
            throw new DayRefused('missing_file:manifest.json', $marker);
        }
        $manifest = self::decodeJson((string) @file_get_contents($this->dir.'/manifest.json'));
        if ($manifest === null) {
            throw new DayRefused('bad_document:manifest.json', $marker);
        }

        if (! is_file($this->dir.'/groups.json')) {
            throw new DayRefused('missing_file:groups.json', $marker);
        }
        $groupsDocument = self::decodeTcgcsv((string) @file_get_contents($this->dir.'/groups.json'));
        if ($groupsDocument === null) {
            throw new DayRefused('bad_document:groups.json', $marker);
        }
        $groupIds = [];
        foreach ($groupsDocument['results'] as $row) {
            if (! is_array($row) || ! is_int($row['groupId'] ?? null)) {
                throw new DayRefused('bad_document:groups.json', $marker);
            }
            $groupIds[$row['groupId']] = true;
        }

        // Manifest cross-check (D1): a half-copied folder disagrees with its own manifest.
        $manifestMarker = is_string($manifest['marker'] ?? null) ? self::parseMarker($manifest['marker']) : null;
        if ($manifestMarker === null || ! $manifestMarker->equalTo($marker)) {
            throw new DayRefused('manifest_mismatch:manifest.json:marker', $marker);
        }
        if (($manifest['day'] ?? null) !== $this->day) {
            throw new DayRefused('manifest_mismatch:manifest.json:day', $marker);
        }
        if (($manifest['groups'] ?? null) !== count($groupsDocument['results'])) {
            throw new DayRefused('manifest_mismatch:manifest.json:groups', $marker);
        }

        $groups = [];
        $rows = 0;
        $duplicates = 0;
        foreach (array_keys($groupIds) as $groupId) {
            foreach (["prices/{$groupId}.json.zst", "products/{$groupId}.json.zst"] as $file) {
                if (! is_file("{$this->dir}/{$file}")) {
                    throw new DayRefused("missing_file:{$file}", $marker);
                }
            }
            $products = self::decodeTcgcsv(self::unzstd("{$this->dir}/products/{$groupId}.json.zst"));
            if ($products === null) {
                throw new DayRefused("bad_document:products/{$groupId}.json.zst", $marker);
            }
            try {
                $group = $this->decodeGroup($groupId);
            } catch (DayRefused $e) {
                throw new DayRefused($e->error, $marker);
            }
            $groups[$groupId] = count($group['rows']);
            $rows += count($group['rows']);
            $duplicates += $group['duplicates'];
        }

        return ['marker' => $marker, 'groups' => $groups, 'rows' => $rows, 'duplicate_rows' => $duplicates];
    }

    /**
     * The day's marker, parsed to UTC.
     *
     * @throws DayRefused when marker.txt is absent or not one ISO 8601 instant
     */
    public function marker(): CarbonImmutable
    {
        $text = is_readable($this->dir.'/marker.txt') ? trim((string) file_get_contents($this->dir.'/marker.txt')) : '';
        $marker = self::parseMarker($text);
        if ($marker === null) {
            throw new DayRefused('bad_marker:marker.txt');
        }

        return $marker;
    }

    /**
     * One group's price rows, validated, converted and de-duplicated (first occurrence kept). The last group read is
     * kept in memory, so consecutive chunks of one group decode its file once.
     *
     * @return list<array{productId: int, subTypeName: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>
     *
     * @throws DayRefused
     */
    public function rows(int $groupId): array
    {
        return $this->decodeGroup($groupId)['rows'];
    }

    /**
     * @return array{rows: list<array{productId: int, subTypeName: string, market: string|null, low: string|null, mid: string|null, high: string|null, direct_low: string|null}>, duplicates: int}
     *
     * @throws DayRefused
     */
    private function decodeGroup(int $groupId): array
    {
        if ($this->cachedGroup === $groupId) {
            return $this->cached;
        }

        $file = "prices/{$groupId}.json.zst";
        if (! is_file("{$this->dir}/{$file}")) {
            throw new DayRefused("missing_file:{$file}");
        }
        $plain = self::unzstd("{$this->dir}/{$file}");
        if (preg_match('/\d[eE]/', $plain) === 1) {
            $plain = (string) preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?[eE][+-]?\d+/',
                fn (array $m): string => $m[0][0] === '"' ? $m[0] : '{"exponent":"'.$m[0].'"}',
                $plain,
            );
        }
        $document = self::decodeTcgcsv($plain);
        if ($document === null) {
            throw new DayRefused("bad_document:{$file}");
        }

        $rows = [];
        $duplicates = 0;
        foreach ($document['results'] as $row) {
            if (! is_array($row) || ! is_int($row['productId'] ?? null) || ! is_string($row['subTypeName'] ?? null)) {
                throw new DayRefused("bad_document:{$file}");
            }
            $out = ['productId' => $row['productId'], 'subTypeName' => $row['subTypeName']];
            foreach (self::PRICE_FIELDS as $field => $column) {
                try {
                    if (! array_key_exists($field, $row)) {
                        throw new UnexpectedValueException('absent');
                    }
                    $out[$column] = PriceNormalizer::money($row[$field]);
                } catch (UnexpectedValueException) {
                    throw new DayRefused("bad_value:{$file}:{$row['productId']}:{$field}");
                }
            }
            $key = $row['productId'].'|'.$row['subTypeName'];
            if (isset($rows[$key])) {
                $duplicates++;

                continue;
            }
            $rows[$key] = [
                'productId' => $out['productId'],
                'subTypeName' => $out['subTypeName'],
                'market' => $out['market'],
                'low' => $out['low'],
                'mid' => $out['mid'],
                'high' => $out['high'],
                'direct_low' => $out['direct_low'],
            ];
        }

        $this->cachedGroup = $groupId;
        $this->cached = ['rows' => array_values($rows), 'duplicates' => $duplicates];

        return $this->cached;
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

    /** The decompressed bytes, or "" when the file is unreadable or not a whole zstd frame. */
    private static function unzstd(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return '';
        }
        $plain = @zstd_uncompress($bytes);

        return is_string($plain) ? $plain : '';
    }

    /** @return array<mixed>|null one whole JSON object, or null */
    private static function decodeJson(string $json): ?array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($document) && ! array_is_list($document) ? $document : null;
    }

    /**
     * One whole successful TCGCSV document (`success: true` and a `results` list), or null. pecl-zstd returns "" or
     * partial data for a frame cut short, which fails here.
     *
     * @return array{results: list<mixed>}|null
     */
    private static function decodeTcgcsv(string $json): ?array
    {
        $document = self::decodeJson($json);
        if ($document === null || ($document['success'] ?? null) !== true
            || ! is_array($document['results'] ?? null) || ! array_is_list($document['results'])) {
            return null;
        }

        /** @var array{results: list<mixed>} $document */
        return $document;
    }
}
