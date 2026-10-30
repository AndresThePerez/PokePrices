<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Judges the product map per set against the recorded pokemontcg.io oracle (the artefact follows the dc.py contract).
 *
 * Our side: every current tcgplayer mapping row, priced from the COLLECTED day's prices/<group>.json.zst (the map
 * stores no price). The oracle side: `tcgplayer.prices.<key>.market` of the card with the same id in
 * `<oracle dir>/cards-NNN.json`. Both are held in memory only; nothing price-shaped is returned except counts,
 * and nothing is written anywhere.
 *
 * Per variant, in order:
 *  1. no oracle key → blind: group 1663 (the shadowless group the oracle never prices), edition shadowless, and
 *     any treatment (pattern, stamp, error, Cosmos: separate TCGplayer products the oracle does not carry);
 *  2. the oracle has no such card, no tcgplayer block, or a block with neither updatedAt nor prices (the real
 *     oracle's url-only blocks) → blind;
 *  3. the block's updatedAt is more than 2 days older than the marker day, or a priced block has no
 *     valid updatedAt (absent, or not a real calendar date such as 2026/13/45) → excluded and counted in
 *     excluded_stale (per variant; the summary detail splits it into excluded_stale_old and
 *     excluded_stale_undated);
 *  4. the block has no prices → blind;
 *  5. none of our oracle keys is priced in the block → a disagreement (key_absent);
 *  6. either market is null → not comparable (no_market);
 *  7. otherwise compared; it disagrees when |Δ| > 20% of the oracle market AND |Δ| > $0.25.
 *
 * Oracle keys follow the TCGplayer subtype: Normal → normal, Holofoil → holofoil, Reverse Holofoil →
 * reverseHolofoil, 1st Edition Holofoil → 1stEditionHolofoil, 1st Edition → 1stEditionNormal (recorded as
 * `1stEdition` in the real oracle), Unlimited Holofoil → unlimitedHolofoil and Unlimited → `unlimited` (the real
 * oracle's key for that subtype; the plan's list omits it).
 *
 * Blind variants are classed group_1663, trainer_kit, basep, pattern, me_era or other (first match).
 * blind_classes_uncovered counts classes that occur among blind variants and have no row in the golden file on
 * disk (an absent file covers nothing). value = sets with at least one disagreement; denominator = compared.
 */
final readonly class OracleComparison
{
    public const float RELATIVE_LIMIT = 0.20;

    public const float ABSOLUTE_LIMIT = 0.25;

    public const int STALE_DAYS = 2;

    private const array PATTERNS = ['pokeball', 'masterball', 'friendball', 'loveball', 'quickball', 'duskball', 'energy'];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array{id: int, snapshot_day: string, dataset_ref: string, marker: string}  $build
     * @return array<string, mixed> the oracle artefact
     *
     * @throws RuntimeException when the oracle is missing, incomplete or unreadable, or a price file is unreadable
     */
    public function compare(array $build, SnapshotReader $snapshot, string $oracleDir, string $goldenPath): array
    {
        $fetch = self::readFetch($oracleDir);
        $oracle = self::readOracle($oracleDir, $fetch['pages_expected']);
        $window = self::window($fetch, $snapshot->marker);
        $goldenClasses = self::goldenClasses($goldenPath);
        $staleBefore = $snapshot->marker->startOfDay()->subDays(self::STALE_DAYS);

        $rows = $this->mappedRows();
        $ours = [];
        foreach (array_unique(array_column($rows, 'group_id')) as $group) {
            $ours[$group] = self::marketPrices($snapshot->dir, $group);
        }

        $perSet = [];
        $blindClasses = [];
        $disagreements = [];
        $excludedStale = 0;
        $excludedUndated = 0;
        $noMarket = 0;
        $noOracleCard = 0;
        foreach ($rows as $row) {
            $set = explode('-', $row['card_id'], 2)[0];
            $perSet[$set] ??= ['set_id' => $set, 'compared' => 0, 'disagree' => 0, 'blind' => 0];
            $keys = self::oracleKeys($row);
            $card = $oracle[$row['card_id']] ?? null;
            if ($card === null && $keys !== null) {
                $noOracleCard++;
            }

            if ($keys === null || $card === null || $card['block'] === false || ($card['updated'] === null && $card['prices'] === [])) {
                $perSet[$set]['blind']++;
                $class = self::blindClass($set, $row);
                $blindClasses[$class] = ($blindClasses[$class] ?? 0) + 1;

                continue;
            }
            if ($card['updated'] === null || $card['updated']->lessThan($staleBefore)) {
                $excludedStale++;
                $excludedUndated += $card['updated'] === null ? 1 : 0;

                continue;
            }
            if ($card['prices'] === []) {
                $perSet[$set]['blind']++;
                $class = self::blindClass($set, $row);
                $blindClasses[$class] = ($blindClasses[$class] ?? 0) + 1;

                continue;
            }

            $key = null;
            foreach ($keys as $candidate) {
                if (array_key_exists($candidate, $card['prices'])) {
                    $key = $candidate;
                    break;
                }
            }
            if ($key === null) {
                $perSet[$set]['compared']++;
                $perSet[$set]['disagree']++;
                $disagreements[] = self::disagreement($set, $row, $keys[0], 'key_absent');

                continue;
            }

            $theirs = $card['prices'][$key];
            $mine = $ours[$row['group_id']][$row['product_id'].'|'.$row['subtype']] ?? null;
            if ($theirs === null || $mine === null) {
                $noMarket++;

                continue;
            }
            $perSet[$set]['compared']++;
            if (self::disagrees($mine, $theirs)) {
                $perSet[$set]['disagree']++;
                $disagreements[] = self::disagreement($set, $row, $key, 'delta');
            }
        }

        ksort($perSet);
        ksort($blindClasses);
        $compared = array_sum(array_column($perSet, 'compared'));
        $disagree = array_sum(array_column($perSet, 'disagree'));
        $blind = array_sum($blindClasses);

        $details = [[
            'kind' => 'summary', 'variants' => count($rows), 'compared' => $compared, 'disagree' => $disagree, 'blind' => $blind,
            'excluded_stale' => $excludedStale, 'excluded_stale_old' => $excludedStale - $excludedUndated,
            'excluded_stale_undated' => $excludedUndated, 'no_market' => $noMarket, 'no_oracle_card' => $noOracleCard, 'oracle_cards' => count($oracle),
        ]];
        foreach ($blindClasses as $class => $count) {
            $details[] = ['kind' => 'blind_class', 'class' => $class, 'variants' => $count, 'golden_covered' => isset($goldenClasses[$class])];
        }
        usort($disagreements, fn (array $a, array $b): int => $a['variant_key'] <=> $b['variant_key']);
        array_push($details, ...$disagreements);

        return [
            'id' => 'DC2',
            'value' => count(array_filter($perSet, fn (array $s): bool => $s['disagree'] > 0)),
            'denominator' => $compared,
            'details' => $details,
            'inputs' => [
                'snapshot_day' => $build['snapshot_day'],
                'dataset_ref' => $build['dataset_ref'],
                'marker' => $snapshot->marker->format('Y-m-d\TH:i:sP'),
                'map_build_id' => $build['id'],
                'oracle_fetched_from' => $window['from'],
                'oracle_fetched_to' => $window['to'],
            ],
            'global_disagreement_pct' => $compared === 0 ? 0.0 : round($disagree / $compared * 100, 4),
            'fetch_window_violation' => $window['violation'] ? 1 : 0,
            'blind_classes_uncovered' => count(array_diff_key($blindClasses, $goldenClasses)),
            'per_set' => array_values($perSet),
            'excluded_stale' => $excludedStale,
        ];
    }

    private static function disagrees(float $mine, float $theirs): bool
    {
        $delta = round(abs($mine - $theirs), 6);
        if ($delta <= self::ABSOLUTE_LIMIT) {
            return false;
        }

        return $theirs <= 0.0 || round($delta / $theirs, 6) > self::RELATIVE_LIMIT;
    }

    /**
     * @param  array{variant_key: string, card_id: string, group_id: int, product_id: int, subtype: string, finish: string, edition: string, treatment: string}  $row
     * @return array<string, mixed>
     */
    private static function disagreement(string $set, array $row, string $key, string $reason): array
    {
        return [
            'kind' => 'disagreement', 'set_id' => $set, 'variant_key' => $row['variant_key'], 'group_id' => $row['group_id'],
            'product_id' => $row['product_id'], 'subtype' => $row['subtype'], 'oracle_key' => $key, 'reason' => $reason,
        ];
    }

    /**
     * @param  array{variant_key: string, card_id: string, group_id: int, product_id: int, subtype: string, finish: string, edition: string, treatment: string}  $row
     * @return list<string>|null the oracle price keys for this variant, best first; null when the oracle has none
     */
    private static function oracleKeys(array $row): ?array
    {
        if ($row['group_id'] === 1663 || $row['edition'] === 'shadowless' || $row['treatment'] !== '-') {
            return null;
        }
        $holo = $row['finish'] === 'holo';

        return match (true) {
            $row['finish'] === 'reverse' => ['reverseHolofoil'],
            $row['edition'] === '1st' => $holo ? ['1stEditionHolofoil'] : ['1stEditionNormal', '1stEdition'],
            str_starts_with($row['subtype'], 'Unlimited') => $holo ? ['unlimitedHolofoil'] : ['unlimited'],
            default => $holo ? ['holofoil'] : ['normal'],
        };
    }

    /** @param  array{group_id: int, edition: string, treatment: string}  $row */
    private static function blindClass(string $set, array $row): string
    {
        return match (true) {
            $row['group_id'] === 1663 || $row['edition'] === 'shadowless' => 'group_1663',
            preg_match('/\Atk\d/', $set) === 1 => 'trainer_kit',
            $set === 'basep' => 'basep',
            in_array($row['treatment'], self::PATTERNS, true) => 'pattern',
            preg_match('/\Ame\d/', $set) === 1 => 'me_era',
            default => 'other',
        };
    }

    /**
     * @return list<array{variant_key: string, card_id: string, group_id: int, product_id: int, subtype: string, finish: string, edition: string, treatment: string}>
     */
    private function mappedRows(): array
    {
        $out = [];
        $rows = $this->db->table('source_product_map as m')
            ->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')
            ->where('m.source', MapBuilder::SOURCE)
            ->orderBy('m.variant_key')
            ->get(['m.variant_key', 'v.card_id', 'v.finish', 'v.edition', 'v.treatment', 'm.source_group_id', 'm.source_product_id', 'm.source_subtype']);
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[] = [
                'variant_key' => (string) $r['variant_key'],
                'card_id' => (string) $r['card_id'],
                'group_id' => (int) $r['source_group_id'],
                'product_id' => (int) $r['source_product_id'],
                'subtype' => (string) $r['source_subtype'],
                'finish' => (string) $r['finish'],
                'edition' => (string) $r['edition'],
                'treatment' => (string) $r['treatment'],
            ];
        }

        return $out;
    }

    /**
     * Our marketPrice per "productId|subTypeName" of one group of the collected day (first row wins, as in
     * SnapshotReader). Held in memory only.
     *
     * @return array<string, float|null>
     */
    private static function marketPrices(string $dayDir, int $group): array
    {
        $path = "{$dayDir}/prices/{$group}.json.zst";
        $bytes = is_file($path) ? @file_get_contents($path) : false;
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
     * @return array{pages_expected: int, late: bool, marker: string, pages: list<array{n: int, status: int, fetched_at: string}>}
     */
    private static function readFetch(string $dir): array
    {
        $path = rtrim($dir, '/').'/fetch.json';
        $fetch = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($fetch) || ! is_int($fetch['pages_expected'] ?? null) || $fetch['pages_expected'] < 1 || ! is_array($fetch['pages'] ?? null)) {
            throw new RuntimeException("oracle {$path} is missing or has no pages_expected and pages");
        }
        $pages = [];
        foreach ($fetch['pages'] as $page) {
            if (! is_array($page) || ! is_int($page['n'] ?? null) || ! is_int($page['status'] ?? null) || ! is_string($page['fetched_at'] ?? null)) {
                throw new RuntimeException("oracle {$path} has a page without n, status and fetched_at");
            }
            $pages[] = ['n' => $page['n'], 'status' => $page['status'], 'fetched_at' => $page['fetched_at']];
        }
        $ok = array_unique(array_column(array_filter($pages, fn (array $p): bool => $p['status'] === 200), 'n'));
        if (count($ok) < $fetch['pages_expected']) {
            throw new RuntimeException("oracle {$path} is incomplete: ".count($ok)." of {$fetch['pages_expected']} pages were fetched");
        }

        return [
            'pages_expected' => $fetch['pages_expected'],
            'late' => ($fetch['late'] ?? false) === true,
            'marker' => is_string($fetch['marker'] ?? null) ? $fetch['marker'] : '',
            'pages' => $pages,
        ];
    }

    /**
     * The oracle cards by id: whether a tcgplayer block exists, its updatedAt and its market per price key.
     *
     * @return array<string, array{block: bool, updated: CarbonImmutable|null, prices: array<string, float|null>}>
     */
    private static function readOracle(string $dir, int $pages): array
    {
        $cards = [];
        for ($n = 1; $n <= $pages; $n++) {
            $path = sprintf('%s/cards-%03d.json', rtrim($dir, '/'), $n);
            try {
                $page = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException $e) {
                throw new RuntimeException("oracle page {$path} is not valid JSON: {$e->getMessage()}", 0, $e);
            }
            if (! is_array($page) || ! is_array($page['data'] ?? null)) {
                throw new RuntimeException("oracle page {$path} is missing or has no data list");
            }
            foreach ($page['data'] as $card) {
                if (! is_array($card) || ! is_string($card['id'] ?? null)) {
                    continue;
                }
                $block = $card['tcgplayer'] ?? null;
                $prices = [];
                foreach (is_array($block) && is_array($block['prices'] ?? null) ? $block['prices'] : [] as $key => $values) {
                    $market = is_array($values) ? ($values['market'] ?? null) : null;
                    $prices[(string) $key] = is_int($market) || is_float($market) ? (float) $market : null;
                }
                $cards[$card['id']] = [
                    'block' => is_array($block),
                    'updated' => is_array($block) ? self::oracleDate($block['updatedAt'] ?? null) : null,
                    'prices' => $prices,
                ];
            }
        }

        return $cards;
    }

    private static function oracleDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}\/\d{2}\/\d{2}\z/', $value) !== 1) {
            return null;
        }

        // createFromFormat rolls an impossible date over (2026/13/45 → 2027-02-14); such a value is not a date.
        $date = CarbonImmutable::createFromFormat('!Y/m/d', $value, 'UTC');

        return $date instanceof CarbonImmutable && $date->format('Y/m/d') === $value ? $date : null;
    }

    /**
     * fetch_window_violation: the recorder ran late, recorded another marker, or fetched a page outside
     * [marker, marker+24h).
     *
     * @param  array{late: bool, marker: string, pages: list<array{n: int, status: int, fetched_at: string}>}  $fetch
     * @return array{violation: bool, from: string, to: string}
     */
    private static function window(array $fetch, CarbonImmutable $marker): array
    {
        $violation = $fetch['late'];
        try {
            $violation = $violation || ! CarbonImmutable::parse($fetch['marker'])->equalTo($marker);
        } catch (Throwable) {
            $violation = true;
        }
        $end = $marker->addDay();
        $times = [];
        foreach ($fetch['pages'] as $page) {
            try {
                $at = CarbonImmutable::parse($page['fetched_at']);
            } catch (Throwable) {
                $violation = true;

                continue;
            }
            $violation = $violation || $at->lessThan($marker) || $at->greaterThanOrEqualTo($end);
            $times[$page['fetched_at']] = $at->getTimestamp();
        }
        asort($times);

        return ['violation' => $violation, 'from' => (string) array_key_first($times), 'to' => (string) array_key_last($times)];
    }

    /** @return array<string, true> the classes named by any row of the golden file; none when it is absent */
    private static function goldenClasses(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException("golden file {$path} is not a JSON list");
        }
        $classes = [];
        foreach ($rows as $row) {
            foreach (is_array($row) && is_array($row['classes'] ?? null) ? $row['classes'] : [] as $class) {
                if (is_string($class)) {
                    $classes[$class] = true;
                }
            }
        }

        return $classes;
    }
}
