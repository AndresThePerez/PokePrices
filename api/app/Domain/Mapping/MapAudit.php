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
 *    as in a UNIQUE). The Pest group for the map checks is run beside it, not by it.
 *  - coverage: dataset cards neither mapped, nor named as a candidate by an unmapped_source_items row of the
 *    build, nor listed in unmapped_cards for the build (a card no priced item reached); unlisted_sets counts dataset sets with neither a set_aliases row nor an explicit `none` row in
 *    database/data/set_aliases.csv (the table the seeder loads; fut20's row cannot be stored).
 *  - ambiguity: the number join is recomputed from set_aliases and the dataset; a card is ambiguous when it
 *    is one of two or more candidates of a source item of the build. It fails when the mapped item that chose it
 *    records a method outside name_tiebreak, alias, golden; an item left in unmapped_source_items is `unmapped`.
 *  - priced: of the dataset's cards, the share with at least one current source_product_map row, overall and per
 *    era (the set's `series`). A card whose only products were rejected (left in unmapped_source_items) is reviewed,
 *    so coverage accounts for it, but it is not priced here. Each share is held to a floor: the overall floor, the
 *    era's own floor, or the default era floor for an era without one. The value is the number of floors missed out
 *    of 1 + the number of eras. The details also list the build's unmapped card products (every reason except
 *    `non_card` and `no_set_alias`), the dearest first by the collected day's market price, at most UNMAPPED_LIST.
 *    The share comes from the map rows, never from the price tables, so it is right before a replay too.
 *
 * The audit only reads: the database, the dataset, the CSV and, for priced, the build's collected day.
 */
final readonly class MapAudit
{
    /** check name => gate id */
    public const array CHECKS = ['names' => 'DC1', 'constraints' => 'DC3', 'coverage' => 'DC5', 'ambiguity' => 'DC6', 'priced' => 'DC7'];

    /** The UNIQUE constraints the epoch rules require, as [table, columns]. */
    private const array REQUIRED_UNIQUE = [
        ['source_product_map', ['source', 'source_product_id', 'source_subtype']],
        ['source_product_map', ['variant_key', 'source']],
        ['source_product_map_history', ['variant_key', 'source', 'series_epoch']],
        ['card_variants', ['card_id', 'finish', 'edition', 'treatment']],
        ['unmapped_source_items', ['map_build_id', 'source', 'source_product_id', 'source_subtype']],
        ['unmapped_cards', ['map_build_id', 'source', 'card_id']],
    ];

    /**
     * The overall priced-card floor, in basis points of the dataset's cards (9600 is 96.00 %). Measured on the
     * 2026-10-11 day with dataset 39a26a1: 89.46 % before the hyphen, Prism Star, Unown and descriptor rules, 97.86 %
     * with them. The floor sits about two points under today's share and well above the old one.
     */
    public const int PRICED_FLOOR_BP = 9600;

    /** The floor of an era that has none of its own in ERA_FLOORS_BP (a new series), in basis points. */
    public const int DEFAULT_ERA_FLOOR_BP = 9000;

    /**
     * The priced-card floor of each era (the dataset set's `series`), in basis points: the era's share on the
     * 2026-10-11 day (shown, then the share before the name and tag rules) rounded down about two points. E-Card
     * (H-numbered holos) and NP (Nintendo promo tags) are known gaps held where they are.
     */
    public const array ERA_FLOORS_BP = [
        'Base' => 9600,                    // 97.98, was 97.77
        'Black & White' => 9650,           // 98.54, was 82.95
        'Diamond & Pearl' => 9700,         // 99.22, was 95.78
        'E-Card' => 8300,                  // 85.44, was 85.44
        'EX' => 9700,                      // 98.98, was 98.81
        'Gym' => 9800,                     // 100.00
        'HeartGold & SoulSilver' => 9350,  // 95.60, was 88.26
        'Mega Evolution' => 9500,          // 99.23, was 98.63; the current era, so new sets get room
        'NP' => 1500,                      // 20.00, was 20.00
        'Neo' => 9650,                     // 98.90, was 92.05
        'Other' => 9600,                   // 98.30, was 98.30
        'POP' => 9500,                     // 97.39, was 96.73
        'Platinum' => 9600,                // 97.87, was 96.13
        'Scarlet & Violet' => 9700,        // 99.08, was 99.08
        'Sun & Moon' => 9500,              // 97.17, was 73.02
        'Sword & Shield' => 9750,          // 99.35, was 89.88
        'XY' => 9400,                      // 95.90, was 78.92
    ];

    /** The most unmapped products the priced check lists. */
    public const int UNMAPPED_LIST = 25;

    /** The era name of a set without a `series`. */
    private const string NO_SERIES = '(no series)';

    /** Unmapped reasons that are not card products of a catalogue set: sealed products and unaliased groups. */
    private const array NOT_CARD_PRODUCTS = ['non_card', 'no_set_alias'];

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
     * @throws RuntimeException on an unknown check, an unreadable dataset or, for priced, an unreadable collected day
     */
    public function run(string $check, array $build, string $datasetDir, string $aliasCsv, string $snapshotsRoot = ''): array
    {
        $id = self::CHECKS[$check] ?? throw new RuntimeException("unknown check {$check}; use one of ".implode(', ', array_keys(self::CHECKS)));
        $inputs = ['snapshot_day' => $build['snapshot_day'], 'dataset_ref' => $build['dataset_ref'], 'marker' => $build['marker'], 'map_build_id' => $build['id']];

        $result = match ($check) {
            'names' => $this->names(self::readDataset($datasetDir)),
            'constraints' => $this->constraints(),
            'coverage' => $this->coverage(self::readDataset($datasetDir), $build['id'], $aliasCsv),
            'priced' => $this->priced(self::readDataset($datasetDir), $build, $snapshotsRoot),
            default => $this->ambiguity(self::readDataset($datasetDir), $build['id']),
        };

        return ['id' => $id, 'value' => $result['value'], 'denominator' => $result['denominator'], 'details' => $result['details'], 'inputs' => $inputs]
            + array_diff_key($result, array_flip(['value', 'denominator', 'details']));
    }

    /**
     * @param  array{sets: list<string>, series: array<string, string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
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
            $compareAs = $parsed->compareName();
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
     * @param  array{sets: list<string>, series: array<string, string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
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
     * @param  array{sets: list<string>, series: array<string, string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
     * @param  array{id: int, snapshot_day: string, dataset_ref: string, marker: string}  $build
     * @return array{value: int, denominator: int, details: list<array<string, mixed>>, priced_pct: float, floor_pct: float}
     *
     * @throws RuntimeException when the build's collected day is missing or a prices file is unreadable
     */
    private function priced(array $dataset, array $build, string $snapshotsRoot): array
    {
        $snapshot = SnapshotReader::open($snapshotsRoot, $build['snapshot_day']);
        $mapped = [];
        foreach ($this->mappedRows() as $row) {
            $mapped[$row['card_id']] = true;
        }

        $total = 0;
        $priced = 0;
        /** @var array<string, array{cards: int, priced: int}> $eras */
        $eras = [];
        foreach ($dataset['cards'] as $id => $card) {
            $era = $dataset['series'][$card['set']] ?? self::NO_SERIES;
            $eras[$era] ??= ['cards' => 0, 'priced' => 0];
            $eras[$era]['cards']++;
            $total++;
            if (isset($mapped[$id])) {
                $eras[$era]['priced']++;
                $priced++;
            }
        }
        ksort($eras, SORT_STRING);

        $shares = [self::share('overall', null, $total, $priced, self::PRICED_FLOOR_BP)];
        foreach ($eras as $era => $n) {
            $era = (string) $era;
            $shares[] = self::share('era', $era, $n['cards'], $n['priced'], self::ERA_FLOORS_BP[$era] ?? self::DEFAULT_ERA_FLOOR_BP);
        }
        $missed = count(array_filter($shares, fn (array $s): bool => $s['met'] === false));

        return [
            'value' => $missed,
            'denominator' => count($shares),
            'details' => [...$shares, ...$this->unmappedProducts($build['id'], $snapshot)],
            'priced_pct' => $shares[0]['share_pct'],
            'floor_pct' => self::PRICED_FLOOR_BP / 100.0,
        ];
    }

    /**
     * @return array{kind: string, scope: string, series: string|null, cards: int, priced: int, share_pct: float, floor_pct: float, met: bool}
     */
    private static function share(string $scope, ?string $series, int $cards, int $priced, int $floorBp): array
    {
        return [
            'kind' => 'priced_share',
            'scope' => $scope,
            'series' => $series,
            'cards' => $cards,
            'priced' => $priced,
            'share_pct' => $cards === 0 ? 0.0 : round($priced / $cards * 100, 2),
            'floor_pct' => $floorBp / 100.0,
            'met' => $priced * 10000 >= $floorBp * $cards,
        ];
    }

    /**
     * The build's unmapped card products, the dearest first by the collected day's market price (a product without
     * one last), then by product id and subtype; at most UNMAPPED_LIST. Prices are held in memory only.
     *
     * @return list<array<string, mixed>>
     */
    private function unmappedProducts(int $buildId, SnapshotReader $snapshot): array
    {
        $rows = $this->db->table('unmapped_source_items')->where('map_build_id', $buildId)->where('source', MapBuilder::SOURCE)
            ->whereNotIn('reason', self::NOT_CARD_PRODUCTS)->orderBy('id')
            ->get(['source_group_id', 'source_product_id', 'source_subtype', 'product_name', 'number', 'reason', 'candidates']);
        $items = [];
        $prices = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $group = (int) $r['source_group_id'];
            $prices[$group] ??= self::marketPrices($snapshot, $group);
            $candidates = json_decode((string) $r['candidates'], true);
            $items[] = [
                'kind' => 'unmapped_product',
                'rank' => 0,
                'group_id' => $group,
                'product_id' => (int) $r['source_product_id'],
                'subtype' => (string) $r['source_subtype'],
                'product_name' => (string) $r['product_name'],
                'number' => is_string($r['number']) ? $r['number'] : null,
                'reason' => (string) $r['reason'],
                'candidates' => is_array($candidates) ? array_values(array_map('strval', $candidates)) : [],
                'market_price' => $prices[$group][$r['source_product_id'].'|'.$r['source_subtype']] ?? null,
            ];
        }
        usort($items, fn (array $a, array $b): int => [$a['market_price'] === null, -($a['market_price'] ?? 0.0), $a['product_id'], $a['subtype']]
            <=> [$b['market_price'] === null, -($b['market_price'] ?? 0.0), $b['product_id'], $b['subtype']]);
        $items = array_slice($items, 0, self::UNMAPPED_LIST);
        foreach ($items as $i => $item) {
            $items[$i]['rank'] = $i + 1;
        }

        return $items;
    }

    /**
     * The market price per "productId|subTypeName" of one group of the collected day (first row wins, as in
     * SnapshotReader); empty for a group the day has no prices file for.
     *
     * @return array<string, float|null>
     *
     * @throws RuntimeException when the prices file does not decode
     */
    private static function marketPrices(SnapshotReader $snapshot, int $group): array
    {
        $path = "{$snapshot->dir}/prices/{$group}.json.zst";
        if (! is_file($path)) {
            return [];
        }
        $bytes = @file_get_contents($path);
        $plain = is_string($bytes) && $bytes !== '' ? @zstd_uncompress($bytes) : false;
        $document = is_string($plain) && $plain !== '' ? json_decode($plain, true) : null;
        if (! is_array($document) || ($document['success'] ?? null) !== true || ! is_array($document['results'] ?? null)) {
            throw new RuntimeException("{$path} is not a readable TCGCSV prices document");
        }

        $out = [];
        foreach ($document['results'] as $row) {
            if (! is_array($row) || ! is_int($row['productId'] ?? null) || ! is_string($row['subTypeName'] ?? null)) {
                throw new RuntimeException("{$path} has a price row without an integer productId and a subTypeName");
            }
            $key = $row['productId'].'|'.$row['subTypeName'];
            if (! array_key_exists($key, $out)) {
                $market = $row['marketPrice'] ?? null;
                $out[$key] = is_int($market) || is_float($market) ? (float) $market : null;
            }
        }

        return $out;
    }

    /**
     * @param  array{sets: list<string>, series: array<string, string>, cards: array<string, array{set: string, name: string, number: string}>}  $dataset
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
     * @return array{sets: list<string>, series: array<string, string>, cards: array<string, array{set: string, name: string, number: string}>}
     */
    private static function readDataset(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $sets = [];
        $series = [];
        $cards = [];
        foreach (self::readJsonList($dir.'/sets/en.json') as $set) {
            if (! is_array($set) || ! is_string($set['id'] ?? null)) {
                throw new RuntimeException("{$dir}/sets/en.json has a set without an id");
            }
            $sets[] = $set['id'];
            $series[$set['id']] = is_string($set['series'] ?? null) && trim($set['series']) !== '' ? $set['series'] : self::NO_SERIES;
            foreach (self::readJsonList("{$dir}/cards/en/{$set['id']}.json") as $card) {
                if (! is_array($card) || ! is_string($card['id'] ?? null) || ! is_string($card['name'] ?? null) || ! is_string($card['number'] ?? null)) {
                    throw new RuntimeException("{$dir}/cards/en/{$set['id']}.json has a card without an id, a name and a number");
                }
                $cards[$card['id']] = ['set' => $set['id'], 'name' => $card['name'], 'number' => $card['number']];
            }
        }

        return ['sets' => $sets, 'series' => $series, 'cards' => $cards];
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
