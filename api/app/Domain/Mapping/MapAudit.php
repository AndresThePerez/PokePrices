<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use RuntimeException;

/**
 * Audits one product-map build. Each check returns an artefact in the dc.py contract: `id`,
 * `value`, `denominator`, `details` (a list) and `inputs` {snapshot_day, dataset_ref, marker, map_build_id};
 * `coverage` adds `coverage_pct` and `unlisted_sets`. A value of 0 is a pass; the threshold lives in the gate.
 *
 *  - names: every current source_product_map row, tie-breaks included, re-checked against the dataset card
 *    name with ProductName + NameAliases (the builder's own rule). A row whose card is not in the dataset counts.
 *  - constraints: the six UNIQUE constraints read from pg_constraint with exactly their columns, plus
 *    duplicate rows over the same columns found by GROUP BY (rows with a NULL in the key are never duplicates,
 *    as in a UNIQUE). The Pest group DC3 is run beside it, not by it.
 *  - coverage: dataset cards neither mapped, nor named as a candidate by an unmapped_source_items row of the
 *    build, nor listed in unmapped_cards for the build (a card no priced item reached); unlisted_sets counts dataset sets with neither a set_aliases row nor an explicit `none` row in
 *    database/data/set_aliases.csv (the table the seeder loads; fut20's row cannot be stored).
 *  - ambiguity: the number join is recomputed from set_aliases and the dataset; a card is ambiguous when it
 *    is one of two or more candidates of a source item of the build. It fails when the mapped item that chose it
 *    records a method outside name_tiebreak, alias, golden; an item left in unmapped_source_items is `unmapped`.
 *
 * The audit only reads: the database, the dataset and the CSV.
 */
final readonly class MapAudit
{
    /** check name => gate id */
    public const array CHECKS = ['names' => 'DC1', 'constraints' => 'DC3', 'coverage' => 'DC5', 'ambiguity' => 'DC6'];

    /** The UNIQUE constraints the epoch rules require, as [table, columns]. */
    private const array REQUIRED_UNIQUE = [
        ['source_product_map', ['source', 'source_product_id', 'source_subtype']],
        ['source_product_map', ['variant_key', 'source']],
        ['source_product_map_history', ['variant_key', 'source', 'series_epoch']],
        ['card_variants', ['card_id', 'finish', 'edition', 'treatment']],
        ['unmapped_source_items', ['map_build_id', 'source', 'source_product_id', 'source_subtype']],
        ['unmapped_cards', ['map_build_id', 'source', 'card_id']],
    ];

    private const array ALLOWED_AMBIGUOUS = ['name_tiebreak', 'alias', 'golden', 'unmapped'];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * The build to audit: the given id, or the newest build.
     *
     * @return array{id: int, snapshot_day: string, dataset_ref: string, marker: string}
     *
     * @throws RuntimeException when there is no such build
     */
    public function build(?int $id = null): array
    {
        $query = $this->db->table('map_builds');
        $row = $id === null ? $query->orderByDesc('id')->first() : $query->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException($id === null ? 'there is no map build to audit; run pokeprices:map:build first' : "map build {$id} does not exist");
        }
        $r = (array) $row;
        $counts = json_decode((string) $r['counts'], true);

        return [
            'id' => (int) $r['id'],
            'snapshot_day' => substr((string) $r['snapshot_day'], 0, 10),
            'dataset_ref' => (string) $r['dataset_ref'],
            'marker' => is_array($counts) && is_string($counts['marker'] ?? null) ? $counts['marker'] : '',
        ];
    }

    /**
     * @param  array{id: int, snapshot_day: string, dataset_ref: string, marker: string}  $build
     * @return array<string, mixed>
     *
     * @throws RuntimeException on an unknown check or an unreadable dataset
     */
    public function run(string $check, array $build, string $datasetDir, string $aliasCsv): array
    {
        $id = self::CHECKS[$check] ?? throw new RuntimeException("unknown check {$check}; use one of ".implode(', ', array_keys(self::CHECKS)));
        $inputs = ['snapshot_day' => $build['snapshot_day'], 'dataset_ref' => $build['dataset_ref'], 'marker' => $build['marker'], 'map_build_id' => $build['id']];

        $result = match ($check) {
            'names' => $this->names(self::readDataset($datasetDir)),
            'constraints' => $this->constraints(),
            'coverage' => $this->coverage(self::readDataset($datasetDir), $build['id'], $aliasCsv),
            default => $this->ambiguity(self::readDataset($datasetDir), $build['id']),
        };

        return ['id' => $id, 'value' => $result['value'], 'denominator' => $result['denominator'], 'details' => $result['details'], 'inputs' => $inputs]
            + array_diff_key($result, array_flip(['value', 'denominator', 'details']));
    }

    /**
     * @param  array{sets: list<string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
     * @return array{value: int, denominator: int, details: list<array<string, mixed>>}
     */
    private function names(array $dataset): array
    {
        $details = [];
        $rows = 0;
        foreach ($this->mappedRows() as $row) {
            $rows++;
            $card = $dataset['cards'][$row['card_id']] ?? null;
            $parsed = ProductName::parse($row['product_name']);
            $compareAs = $parsed->base.($parsed->isDelta() ? ' (Delta Species)' : '');
            if ($card !== null && NameAliases::equal($card['name'], $compareAs)) {
                continue;
            }
            $details[] = [
                'variant_key' => $row['variant_key'],
                'card_id' => $row['card_id'],
                'card_name' => $card['name'] ?? null,
                'product_id' => $row['product_id'],
                'subtype' => $row['subtype'],
                'product_name' => $row['product_name'],
                'reason' => $card === null ? 'card_not_in_dataset' : 'name_disagreement',
            ];
        }

        return ['value' => count($details), 'denominator' => $rows, 'details' => $details];
    }

    /** @return array{value: int, denominator: int, details: list<array<string, mixed>>} */
    private function constraints(): array
    {
        $details = [];
        foreach (self::REQUIRED_UNIQUE as [$table, $columns]) {
            $found = false;
            foreach ($this->uniqueConstraints($table) as $have) {
                $sortedHave = $have;
                $sortedWant = $columns;
                sort($sortedHave);
                sort($sortedWant);
                $found = $found || $sortedHave === $sortedWant;
            }
            if (! $found) {
                $details[] = ['kind' => 'missing_constraint', 'table' => $table, 'columns' => $columns];
            }
        }
        $missing = count($details);

        $duplicates = 0;
        foreach (self::REQUIRED_UNIQUE as [$table, $columns]) {
            $list = implode(', ', $columns);
            $notNull = implode(' AND ', array_map(fn (string $c): string => "{$c} IS NOT NULL", $columns));
            $groups = $this->db->select("SELECT {$list}, count(*) AS n FROM {$table} WHERE {$notNull} GROUP BY {$list} HAVING count(*) > 1 ORDER BY {$list}");
            foreach ($groups as $group) {
                $g = (array) $group;
                $extra = (int) $g['n'] - 1;
                $duplicates += $extra;
                $details[] = ['kind' => 'duplicate_rows', 'table' => $table, 'columns' => $columns, 'key' => array_values(array_intersect_key($g, array_flip($columns))), 'rows' => (int) $g['n']];
            }
        }

        return ['value' => $missing + $duplicates, 'denominator' => 2 * count(self::REQUIRED_UNIQUE), 'details' => $details];
    }

    /** @return list<list<string>> the column lists of $table's UNIQUE constraints */
    private function uniqueConstraints(string $table): array
    {
        $rows = $this->db->select(<<<'SQL'
            SELECT array_to_json(ARRAY(
                SELECT a.attname FROM unnest(c.conkey) AS k(attnum)
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
            )) AS columns
            FROM pg_constraint c
            WHERE c.contype = 'u' AND c.conrelid = to_regclass(?)
            SQL, [$table]);

        $out = [];
        foreach ($rows as $row) {
            $columns = json_decode((string) ((array) $row)['columns'], true);
            if (is_array($columns)) {
                $out[] = array_values(array_map('strval', $columns));
            }
        }

        return $out;
    }

    /**
     * @param  array{sets: list<string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
     * @return array{value: int, denominator: int, details: list<array<string, mixed>>, coverage_pct: float, unlisted_sets: int}
     */
    private function coverage(array $dataset, int $buildId, string $aliasCsv): array
    {
        $mapped = [];
        foreach ($this->mappedRows() as $row) {
            $mapped[$row['card_id']] = true;
        }
        $reviewed = [];
        $rows = $this->db->select('SELECT DISTINCT jsonb_array_elements_text(candidates) AS card FROM unmapped_source_items WHERE map_build_id = ?', [$buildId]);
        foreach ($rows as $row) {
            $reviewed[(string) ((array) $row)['card']] = true;
        }
        $listedCards = [];
        foreach ($this->db->table('unmapped_cards')->where('map_build_id', $buildId)->where('source', MapBuilder::SOURCE)->get(['card_id', 'reason']) as $row) {
            $r = (array) $row;
            $reviewed[(string) $r['card_id']] = true;
            $listedCards[(string) $r['reason']] = ($listedCards[(string) $r['reason']] ?? 0) + 1;
        }

        $details = [];
        $mappedCards = 0;
        foreach ($dataset['cards'] as $id => $card) {
            if (isset($mapped[$id])) {
                $mappedCards++;
            } elseif (! isset($reviewed[$id])) {
                $details[] = ['kind' => 'uncovered_card', 'card_id' => $id, 'set_id' => $card['set']];
            }
        }
        $uncovered = count($details);

        $listed = array_flip(array_map('strval', $this->db->table('set_aliases')->where('source', MapBuilder::SOURCE)->distinct()->pluck('pokesearch_set_id')->all()));
        $listed += array_flip(self::explicitNoneSets($aliasCsv));
        $unlisted = array_values(array_diff($dataset['sets'], array_keys($listed)));
        sort($unlisted);
        foreach ($unlisted as $set) {
            $details[] = ['kind' => 'unlisted_set', 'set_id' => $set];
        }
        ksort($listedCards);
        foreach ($listedCards as $reason => $cards) {
            $details[] = ['kind' => 'listed_card_reason', 'reason' => $reason, 'cards' => $cards];
        }

        $total = count($dataset['cards']);

        return [
            'value' => $uncovered,
            'denominator' => $total,
            'details' => $details,
            'coverage_pct' => $total === 0 ? 0.0 : round($mappedCards / $total * 100, 2),
            'unlisted_sets' => count($unlisted),
        ];
    }

    /**
     * @param  array{sets: list<string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
     * @return array{value: int, denominator: int, details: list<array<string, mixed>>}
     */
    private function ambiguity(array $dataset, int $buildId): array
    {
        $byNumber = [];
        foreach ($dataset['cards'] as $id => $card) {
            $byNumber[$card['set']][CardNumber::normalise($card['number'])][] = $id;
        }
        $aliases = [];
        foreach ($this->db->table('set_aliases')->where('source', MapBuilder::SOURCE)->get() as $row) {
            $r = (array) $row;
            $aliases[(int) $r['source_group_id']][] = ['set' => (string) $r['pokesearch_set_id'], 'rule' => is_string($r['number_rule']) ? $r['number_rule'] : null];
        }
        $candidates = function (int $group, ?string $number) use ($aliases, $byNumber): array {
            if ($number === null || trim($number) === '') {
                return [];
            }
            $normal = CardNumber::normalise($number);
            $ids = [];
            foreach ($aliases[$group] ?? [] as $alias) {
                if ($alias['rule'] === null || fnmatch($alias['rule'], $normal)) {
                    array_push($ids, ...($byNumber[$alias['set']][$normal] ?? []));
                }
            }

            return array_values(array_unique($ids));
        };

        $ambiguous = [];
        $failing = [];
        foreach ($this->mappedRows() as $row) {
            $ids = $candidates($row['group_id'], $row['number']);
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $ambiguous[$id] = true;
            }
            if (! in_array($row['method'], self::ALLOWED_AMBIGUOUS, true)) {
                $failing[$row['card_id']][] = [
                    'card_id' => $row['card_id'], 'variant_key' => $row['variant_key'], 'product_id' => $row['product_id'],
                    'subtype' => $row['subtype'], 'method' => $row['method'], 'candidates' => $ids,
                ];
            }
        }
        $unmapped = $this->db->table('unmapped_source_items')->where('map_build_id', $buildId)->where('source', MapBuilder::SOURCE)
            ->orderBy('id')->get(['source_group_id', 'number']);
        foreach ($unmapped as $row) {
            $r = (array) $row;
            $ids = $candidates((int) $r['source_group_id'], is_string($r['number']) ? $r['number'] : null);
            if (count($ids) >= 2) {
                foreach ($ids as $id) {
                    $ambiguous[$id] = true;
                }
            }
        }

        ksort($failing);
        $details = [];
        foreach ($failing as $rows) {
            array_push($details, ...$rows);
        }

        return ['value' => count($failing), 'denominator' => count($ambiguous), 'details' => $details];
    }

    /**
     * The current tcgplayer mapping rows with their variant's card, ordered by variant key.
     *
     * @return list<array{variant_key: string, card_id: string, group_id: int, product_id: int, subtype: string, product_name: string, number: string|null, method: string}>
     */
    private function mappedRows(): array
    {
        $out = [];
        $rows = $this->db->table('source_product_map as m')
            ->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')
            ->where('m.source', MapBuilder::SOURCE)
            ->orderBy('m.variant_key')
            ->get(['m.variant_key', 'v.card_id', 'm.source_group_id', 'm.source_product_id', 'm.source_subtype', 'm.source_product_name', 'm.source_number', 'm.method']);
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[] = [
                'variant_key' => (string) $r['variant_key'],
                'card_id' => (string) $r['card_id'],
                'group_id' => (int) $r['source_group_id'],
                'product_id' => (int) $r['source_product_id'],
                'subtype' => (string) $r['source_subtype'],
                'product_name' => (string) $r['source_product_name'],
                'number' => is_string($r['source_number']) ? $r['source_number'] : null,
                'method' => (string) $r['method'],
            ];
        }

        return $out;
    }

    /**
     * The dataset's set ids and its cards by id.
     *
     * @return array{sets: list<string>, cards: array<string, array{set: string, name: string, number: string}>}
     */
    private static function readDataset(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $sets = [];
        $cards = [];
        foreach (self::readJsonList($dir.'/sets/en.json') as $set) {
            if (! is_array($set) || ! is_string($set['id'] ?? null)) {
                throw new RuntimeException("{$dir}/sets/en.json has a set without an id");
            }
            $sets[] = $set['id'];
            foreach (self::readJsonList("{$dir}/cards/en/{$set['id']}.json") as $card) {
                if (! is_array($card) || ! is_string($card['id'] ?? null) || ! is_string($card['name'] ?? null) || ! is_string($card['number'] ?? null)) {
                    throw new RuntimeException("{$dir}/cards/en/{$set['id']}.json has a card without an id, a name and a number");
                }
                $cards[$card['id']] = ['set' => $set['id'], 'name' => $card['name'], 'number' => $card['number']];
            }
        }

        return ['sets' => $sets, 'cards' => $cards];
    }

    /** @return list<mixed> */
    private static function readJsonList(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new RuntimeException("dataset file {$path} is missing or unreadable");
        }
        try {
            $list = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("dataset file {$path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException("dataset file {$path} is not a JSON list");
        }

        return $list;
    }

    /** @return list<string> set ids the alias CSV lists with role `none` (no TCGplayer group exists) */
    private static function explicitNoneSets(string $path): array
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException("set alias table {$path} is not readable");
        }
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            $set = is_array($header) ? array_search('pokesearch_set_id', $header, true) : false;
            $role = is_array($header) ? array_search('role', $header, true) : false;
            if ($set === false || $role === false) {
                throw new RuntimeException("set alias table {$path} has no pokesearch_set_id and role columns");
            }
            $out = [];
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (($cells[$role] ?? null) === 'none' && is_string($cells[$set] ?? null)) {
                    $out[] = $cells[$set];
                }
            }

            return $out;
        } finally {
            fclose($handle);
        }
    }
}
