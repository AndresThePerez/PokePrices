<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use RuntimeException;

/**
 * Builds the TCGplayer product map from one collected TCGCSV day and one dataset checkout. Every priced (productId, subTypeName) pair of the day is either mapped to one card variant
 * or written to unmapped_source_items with its reason; no price value is read or stored.
 *
 * Join order for one price row:
 *  1. set → groups through set_aliases only. A group no alias row lists, or a number outside every listed row's
 *     number_rule, is `no_set_alias`; a product without a Number is `non_card`.
 *  2. the normalised number (CardNumber) within the sets those rows name (`no_number_match` when none). A product
 *     with an unknown tag is `unknown_tag`.
 *  3. the trainer-kit deck tag must equal the kit's deck, then the name (NameAliases, one-way delta, `Delta <name> Energy` included) must agree.
 *     One agreeing card is the match: method `number+name` when the number had one candidate, `name_tiebreak` when
 *     it had several. None agreeing is `name_disagreement`, several is `ambiguous`.
 *
 * The variant key is card:finish:edition:treatment. The finish comes from the treatment for pattern products and
 * from subTypeName otherwise. `1st Edition*` is edition 1st, `Unlimited*` is the alias row's unlimited_means;
 * Normal/Holofoil are `unlimited` in the primary group of a set that also has an edition-split group (base1's
 * 604) and `-` elsewhere. OQ6 default: Machamp 107004 "(Base Set Shadowless)" is base1-8:holo:1st:shadowless
 * beside 42425 base1-8:holo:1st:-. OQ7 default: in a promo set, a card whose products for one finish and edition
 * are all tagged takes `-` for the one product whose treatment tags are a subset of every other's (svp-5
 * "(Prerelease)" beside "(Prerelease) [Staff]", svp-46 whose only product is Cosmos); the others keep their tags.
 * A second price row on a key already taken is `duplicate_target`, so no key is ever written twice.
 *
 * Cards: a dataset card that no price row reached (neither matched nor a candidate of an unmapped item) is listed
 * in unmapped_cards for the build, `no_set_alias` when its set has no alias row and `no_priced_product` otherwise,
 * so every catalog card is either mapped or listed for review.
 *
 * Epochs: a key whose (product, subtype) changes has its row moved to source_product_map_history with
 * ended_at and end_reason `remapped`, and the new row gets series_epoch + 1. A row whose (product, subtype) is now
 * claimed by another key is ended the same way. Every other current row is left exactly as it is.
 */
final readonly class MapBuilder
{
    public const string SOURCE = 'tcgplayer';

    /** The EX trainer-kit decks. */
    private const array KIT_DECKS = ['tk1a' => 'Latias', 'tk1b' => 'Latios', 'tk2a' => 'Plusle', 'tk2b' => 'Minun'];

    private const array SUBTYPE_ORDER = [
        'Normal' => 0, 'Holofoil' => 1, 'Reverse Holofoil' => 2, '1st Edition' => 3, '1st Edition Holofoil' => 4,
        'Unlimited' => 5, 'Unlimited Holofoil' => 6,
    ];

    private const array CONFIDENCE = ['number+name' => '1.000', 'name_tiebreak' => '0.900', 'promo_canonical' => '0.800'];

    private const int CHUNK = 500;

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Reads the whole day and dataset first, then writes the build in one transaction. Nothing is written when
     * any input is unreadable.
     *
     * @return array{build_id: int, counts: array<string, mixed>}
     *
     * @throws RuntimeException when set_aliases is empty or an input file is unreadable
     */
    public function build(SnapshotReader $snapshot, string $day, string $datasetDir): array
    {
        $startedAt = CarbonImmutable::now('UTC');
        $aliases = $this->aliases();
        $dataset = self::readDataset($datasetDir);
        $plan = $this->plan($snapshot, $aliases, $dataset);

        return $this->db->transaction(fn (): array => $this->write($plan, $day, $dataset['ref'], $startedAt, $snapshot));
    }

    /**
     * @return array<int, list<array{set: string, role: string, number_rule: string|null, unlimited_means: string|null}>> groupId => rows
     */
    private function aliases(): array
    {
        $byGroup = [];
        foreach ($this->db->table('set_aliases')->where('source', self::SOURCE)->orderBy('pokesearch_set_id')->get() as $row) {
            $r = (array) $row;
            $byGroup[(int) $r['source_group_id']][] = [
                'set' => (string) $r['pokesearch_set_id'],
                'role' => (string) $r['role'],
                'number_rule' => is_string($r['number_rule']) ? $r['number_rule'] : null,
                'unlimited_means' => is_string($r['unlimited_means']) ? $r['unlimited_means'] : null,
            ];
        }
        if ($byGroup === []) {
            throw new RuntimeException('set_aliases has no tcgplayer rows; run the SetAliasSeeder first');
        }
        ksort($byGroup);

        return $byGroup;
    }

    /**
     * @return array{ref: string, sets: array<string, string>, cards: array<string, list<array{id: string, name: string, number: string}>>}
     */
    private static function readDataset(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $sets = [];
        foreach (self::readJsonList($dir.'/sets/en.json') as $set) {
            if (! is_array($set) || ! is_string($set['id'] ?? null) || ! is_string($set['name'] ?? null)) {
                throw new RuntimeException("{$dir}/sets/en.json has a set without an id and a name");
            }
            $sets[$set['id']] = $set['name'];
        }

        $cards = [];
        foreach (array_keys($sets) as $setId) {
            $path = "{$dir}/cards/en/{$setId}.json";
            $cards[$setId] = [];
            foreach (self::readJsonList($path) as $card) {
                if (! is_array($card) || ! is_string($card['id'] ?? null) || ! is_string($card['name'] ?? null) || ! is_string($card['number'] ?? null)) {
                    throw new RuntimeException("{$path} has a card without an id, a name and a number");
                }
                $cards[$setId][] = ['id' => $card['id'], 'name' => $card['name'], 'number' => $card['number']];
            }
        }

        $ref = basename((string) realpath($dir));
        $source = is_file($dir.'/SOURCE.json') ? json_decode((string) file_get_contents($dir.'/SOURCE.json'), true) : null;
        if (is_array($source) && is_string($source['sha'] ?? null) && preg_match('/\A[0-9a-f]{40}\z/', $source['sha']) === 1) {
            $ref = $source['sha'];
        }

        return ['ref' => $ref, 'sets' => $sets, 'cards' => $cards];
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

    /**
     * Resolves every price row of the day. Pure: reads the snapshot and the inputs, writes nothing.
     *
     * @param  array<int, list<array{set: string, role: string, number_rule: string|null, unlimited_means: string|null}>>  $aliases
     * @param  array{ref: string, sets: array<string, string>, cards: array<string, list<array{id: string, name: string, number: string}>>}  $dataset
     * @return array{mapped: list<array<string, mixed>>, unmapped: list<array<string, mixed>>, unmapped_cards: list<array{card: string, reason: string}>, counts: array<string, mixed>}
     */
    private function plan(SnapshotReader $snapshot, array $aliases, array $dataset): array
    {
        $byNumber = [];
        $cardCount = 0;
        foreach ($dataset['cards'] as $setId => $cards) {
            foreach ($cards as $card) {
                $byNumber[$setId][CardNumber::normalise($card['number'])][] = $card;
                $cardCount++;
            }
        }
        $editionSplit = [];
        $listed = [];
        foreach ($aliases as $rows) {
            foreach ($rows as $row) {
                $listed[$row['set']] = true;
                if ($row['role'] === 'edition-split') {
                    $editionSplit[$row['set']] = true;
                }
            }
        }

        $resolved = [];
        $unmapped = [];
        $orphans = 0;
        $emptyGroups = [];
        $groupIds = array_keys($snapshot->groups);
        sort($groupIds);
        foreach ($groupIds as $groupId) {
            $products = [];
            foreach ($snapshot->products($groupId) as $product) {
                $products[$product['productId']] = $product;
            }
            $priceRows = $snapshot->priceRows($groupId);
            if ($products === []) {
                $emptyGroups[] = $groupId;
            }
            usort($priceRows, fn (array $a, array $b): int => [$a['productId'], self::SUBTYPE_ORDER[$a['subTypeName']] ?? 99, $a['subTypeName']]
                <=> [$b['productId'], self::SUBTYPE_ORDER[$b['subTypeName']] ?? 99, $b['subTypeName']]);

            foreach ($priceRows as $price) {
                $product = $products[$price['productId']] ?? null;
                if ($product === null) {
                    $orphans++;

                    continue;
                }
                $item = [
                    'group' => $groupId, 'product' => $product['productId'], 'subtype' => $price['subTypeName'],
                    'name' => $product['name'], 'number' => $product['number'],
                ];
                $outcome = self::resolve($item, $aliases[$groupId] ?? [], $byNumber, $editionSplit);
                if (isset($outcome['reason'])) {
                    $unmapped[] = $item + ['reason' => $outcome['reason'], 'candidates' => $outcome['candidates']];
                } else {
                    $resolved[] = $item + $outcome;
                }
            }
        }

        $promoCanonical = self::applyPromoCanonical($resolved, $dataset['sets']);

        $mapped = [];
        $taken = [];
        foreach ($resolved as $row) {
            $key = "{$row['card']}:{$row['finish']}:{$row['edition']}:{$row['treatment']}";
            if (isset($taken[$key])) {
                $unmapped[] = array_intersect_key($row, array_flip(['group', 'product', 'subtype', 'name', 'number']))
                    + ['reason' => 'duplicate_target', 'candidates' => [$row['card']]];

                continue;
            }
            $taken[$key] = true;
            $mapped[] = $row + ['variant_key' => $key];
        }

        $matchedCards = array_unique(array_column($mapped, 'card'));
        $ambiguousCards = [];
        $byReason = [];
        foreach ($unmapped as $row) {
            $byReason[$row['reason']] = ($byReason[$row['reason']] ?? 0) + 1;
            if ($row['reason'] === 'ambiguous') {
                foreach ($row['candidates'] as $card) {
                    $ambiguousCards[$card] = true;
                }
            }
        }
        ksort($byReason);
        $unlisted = array_values(array_diff(array_keys($dataset['sets']), array_keys($listed)));
        sort($unlisted);

        // Every dataset card no priced item reached (neither matched nor a candidate of an unmapped item) is listed
        // per build, so the coverage audit sees each card as mapped or listed for review.
        $reached = array_flip($matchedCards);
        foreach ($unmapped as $row) {
            foreach ($row['candidates'] as $card) {
                $reached[$card] = true;
            }
        }
        $unmappedCards = [];
        foreach ($dataset['cards'] as $setId => $cards) {
            foreach ($cards as $card) {
                if (! isset($reached[$card['id']])) {
                    $unmappedCards[] = ['card' => $card['id'], 'reason' => isset($listed[$setId]) ? 'no_priced_product' : 'no_set_alias'];
                }
            }
        }

        return [
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'unmapped_cards' => $unmappedCards,
            'counts' => [
                'cards' => $cardCount,
                'variants' => count($mapped),
                'matched' => count($matchedCards),
                'ambiguous' => count($ambiguousCards),
                'unmatched' => $cardCount - count($matchedCards),
                'by_reason' => $byReason,
                'source_items' => count($mapped) + count($unmapped),
                'name_tiebreak' => count(array_filter($mapped, fn (array $r): bool => $r['method'] === 'name_tiebreak')),
                'promo_canonical' => $promoCanonical,
                'orphan_price_rows' => $orphans,
                'empty_groups' => $emptyGroups,
                'unlisted_sets' => $unlisted,
                'unmapped_cards' => count($unmappedCards),
            ],
        ];
    }

    /**
     * One price row through the join order: either a resolution (card, set, finish, edition, treatment, tags,
     * method) or an unmapped reason with its candidate card ids.
     *
     * @param  array{group: int, product: int, subtype: string, name: string, number: string|null}  $item
     * @param  list<array{set: string, role: string, number_rule: string|null, unlimited_means: string|null}>  $rows
     * @param  array<string, array<string, list<array{id: string, name: string, number: string}>>>  $byNumber
     * @param  array<string, true>  $editionSplit
     * @return array<string, mixed>
     */
    private static function resolve(array $item, array $rows, array $byNumber, array $editionSplit): array
    {
        if ($rows === []) {
            return ['reason' => 'no_set_alias', 'candidates' => []];
        }
        if ($item['number'] === null || trim($item['number']) === '') {
            return ['reason' => 'non_card', 'candidates' => []];
        }
        $number = CardNumber::normalise($item['number']);
        $rows = array_values(array_filter($rows, fn (array $r): bool => $r['number_rule'] === null || fnmatch($r['number_rule'], $number)));
        if ($rows === []) {
            return ['reason' => 'no_set_alias', 'candidates' => []];
        }

        $candidates = [];
        foreach ($rows as $row) {
            foreach ($byNumber[$row['set']][$number] ?? [] as $card) {
                $candidates[] = $card + ['row' => $row];
            }
        }
        $ids = array_column($candidates, 'id');
        if ($candidates === []) {
            return ['reason' => 'no_number_match', 'candidates' => []];
        }

        $parsed = ProductName::parse($item['name']);
        $treatment = $parsed->treatment();
        $finish = $parsed->finish($item['subtype']);
        if ($treatment === null || $finish === null) {
            return ['reason' => 'unknown_tag', 'candidates' => $ids];
        }

        $deck = $parsed->deck();
        $compareAs = $parsed->compareName();
        $agreeing = array_values(array_filter($candidates, fn (array $c): bool => ($deck === null || ! isset(self::KIT_DECKS[$c['row']['set']]) || self::KIT_DECKS[$c['row']['set']] === $deck)
            && NameAliases::equal($c['name'], $compareAs)));
        if ($agreeing === []) {
            return ['reason' => 'name_disagreement', 'candidates' => $ids];
        }
        if (count($agreeing) > 1) {
            return ['reason' => 'ambiguous', 'candidates' => array_column($agreeing, 'id')];
        }

        $card = $agreeing[0];
        $edition = match ($item['subtype']) {
            '1st Edition', '1st Edition Holofoil' => '1st',
            'Unlimited', 'Unlimited Holofoil' => $card['row']['unlimited_means'],
            'Normal', 'Holofoil' => $card['row']['role'] === 'primary' && isset($editionSplit[$card['row']['set']])
                ? ($card['row']['unlimited_means'] ?? '-') : '-',
            default => '-',
        };
        if ($edition === null) {
            return ['reason' => 'unknown_tag', 'candidates' => $ids];
        }

        $tags = [];
        foreach ($parsed->tags as $tag) {
            if ($tag['class'] === ProductName::TREATMENT && $tag['value'] !== null) {
                $tags[] = $tag['value'];
            }
        }

        return [
            'card' => $card['id'],
            'set' => $card['row']['set'],
            'finish' => $finish,
            'edition' => $edition,
            'treatment' => $treatment,
            'tags' => $tags,
            'method' => count($candidates) > 1 ? 'name_tiebreak' : 'number+name',
            'confidence' => self::CONFIDENCE[count($candidates) > 1 ? 'name_tiebreak' : 'number+name'],
        ];
    }

    /**
     * OQ7 default: in a promo set, when every product of a card for one finish and edition is tagged, the one
     * whose treatment tags are a subset of every other's is the card itself and takes treatment `-`.
     *
     * @param  list<array<string, mixed>>  $resolved
     * @param  array<string, string>  $sets  set id => name
     * @return int rows rewritten
     */
    private static function applyPromoCanonical(array &$resolved, array $sets): int
    {
        $groups = [];
        foreach ($resolved as $i => $row) {
            $set = (string) $row['set'];
            if (str_contains($sets[$set] ?? '', 'Promo')) {
                $groups["{$row['card']}:{$row['finish']}:{$row['edition']}"][] = $i;
            }
        }

        $rewritten = 0;
        foreach ($groups as $indexes) {
            /** @var array<int, list<string>> $tagSets */
            $tagSets = [];
            foreach ($indexes as $i) {
                $tagSets[$i] = array_values(array_unique((array) $resolved[$i]['tags']));
            }
            if (in_array([], $tagSets, true)) {
                continue;
            }
            $smallest = min(array_map('count', $tagSets));
            $minimal = array_keys(array_filter($tagSets, fn (array $t): bool => count($t) === $smallest));
            if (count($minimal) !== 1) {
                continue;
            }
            $canonical = $tagSets[$minimal[0]];
            $subsetOfAll = array_filter($tagSets, fn (array $t): bool => array_diff($canonical, $t) === []);
            if (count($subsetOfAll) !== count($tagSets)) {
                continue;
            }
            $resolved[$minimal[0]]['treatment'] = '-';
            $resolved[$minimal[0]]['confidence'] = self::CONFIDENCE['promo_canonical'];
            $rewritten++;
        }

        return $rewritten;
    }

    /**
     * @param  array{mapped: list<array<string, mixed>>, unmapped: list<array<string, mixed>>, unmapped_cards: list<array{card: string, reason: string}>, counts: array<string, mixed>}  $plan
     * @return array{build_id: int, counts: array<string, mixed>}
     */
    private function write(array $plan, string $day, string $datasetRef, CarbonImmutable $startedAt, SnapshotReader $snapshot): array
    {
        $buildId = (int) $this->db->table('map_builds')->insertGetId([
            'snapshot_day' => $day,
            'dataset_ref' => $datasetRef,
            'seed_ref' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
            'counts' => '{}',
        ]);

        foreach (array_chunk($plan['unmapped'], self::CHUNK) as $chunk) {
            $this->db->table('unmapped_source_items')->insert(array_map(fn (array $r): array => [
                'map_build_id' => $buildId,
                'source' => self::SOURCE,
                'source_group_id' => $r['group'],
                'source_product_id' => $r['product'],
                'source_subtype' => $r['subtype'],
                'product_name' => $r['name'],
                'number' => $r['number'],
                'reason' => $r['reason'],
                'candidates' => json_encode($r['candidates'], JSON_THROW_ON_ERROR),
            ], $chunk));
        }

        foreach (array_chunk($plan['unmapped_cards'], self::CHUNK) as $chunk) {
            $this->db->table('unmapped_cards')->insert(array_map(fn (array $r): array => [
                'map_build_id' => $buildId,
                'source' => self::SOURCE,
                'card_id' => $r['card'],
                'reason' => $r['reason'],
            ], $chunk));
        }

        foreach (array_chunk($plan['mapped'], self::CHUNK) as $chunk) {
            $this->db->table('card_variants')->insertOrIgnore(array_map(fn (array $r): array => [
                'variant_key' => $r['variant_key'],
                'card_id' => $r['card'],
                'finish' => $r['finish'],
                'edition' => $r['edition'],
                'treatment' => $r['treatment'],
            ], $chunk));
        }

        $epochs = $this->writeMappings($plan['mapped'], $buildId);

        $counts = $plan['counts'] + $epochs + ['marker' => $snapshot->marker->format('Y-m-d\TH:i:sP')];
        $this->db->table('map_builds')->where('id', $buildId)->update([
            'finished_at' => CarbonImmutable::now('UTC'),
            'counts' => json_encode($counts, JSON_THROW_ON_ERROR),
        ]);

        return ['build_id' => $buildId, 'counts' => $counts];
    }

    /**
     * Applies the epoch rules to source_product_map and returns the epoch counts.
     *
     * @param  list<array<string, mixed>>  $mapped
     * @return array{remapped: int, unchanged: int, inserted: int, stale_kept: int}
     */
    private function writeMappings(array $mapped, int $buildId): array
    {
        $current = [];
        $holderOf = [];
        foreach ($this->db->table('source_product_map')->where('source', self::SOURCE)->get() as $row) {
            $r = (array) $row;
            $current[(string) $r['variant_key']] = $r;
            $holderOf[$r['source_product_id'].'|'.$r['source_subtype']] = (string) $r['variant_key'];
        }

        $desired = [];
        foreach ($mapped as $row) {
            $desired[(string) $row['variant_key']] = $row;
        }

        $ending = [];
        foreach ($desired as $key => $row) {
            $now = $current[$key] ?? null;
            if ($now !== null && ((int) $now['source_product_id'] !== $row['product'] || $now['source_subtype'] !== $row['subtype'])) {
                $ending[$key] = true;
            }
            $holder = $holderOf[$row['product'].'|'.$row['subtype']] ?? null;
            if ($holder !== null && $holder !== $key) {
                $ending[$holder] = true;
            }
        }

        $endedAt = CarbonImmutable::now('UTC');
        $history = [];
        foreach (array_keys($ending) as $key) {
            $row = $current[$key];
            unset($row['id']);
            $history[] = $row + ['ended_at' => $endedAt, 'end_reason' => 'remapped'];
        }
        foreach (array_chunk($history, self::CHUNK) as $chunk) {
            $this->db->table('source_product_map_history')->insert($chunk);
        }
        foreach (array_chunk(array_keys($ending), self::CHUNK) as $chunk) {
            $this->db->table('source_product_map')->where('source', self::SOURCE)->whereIn('variant_key', $chunk)->delete();
        }

        $lastEpoch = [];
        foreach (array_chunk(array_keys($desired), self::CHUNK) as $chunk) {
            $rows = $this->db->table('source_product_map_history')->where('source', self::SOURCE)->whereIn('variant_key', $chunk)
                ->groupBy('variant_key')->selectRaw('variant_key, max(series_epoch) as epoch')->get();
            foreach ($rows as $row) {
                $r = (array) $row;
                $lastEpoch[(string) $r['variant_key']] = (int) $r['epoch'];
            }
        }

        $inserts = [];
        $unchanged = 0;
        foreach ($desired as $key => $row) {
            $columns = [
                'source_group_id' => $row['group'],
                'source_product_name' => $row['name'],
                'source_number' => $row['number'],
                'method' => $row['method'],
                'confidence' => $row['confidence'],
            ];
            if (isset($current[$key]) && ! isset($ending[$key])) {
                $unchanged++;
                $now = $current[$key];
                if ((int) $now['source_group_id'] !== $row['group'] || $now['source_product_name'] !== $row['name']
                    || $now['source_number'] !== $row['number'] || $now['method'] !== $row['method']
                    || (string) $now['confidence'] !== $row['confidence']) {
                    $this->db->table('source_product_map')->where('id', $now['id'])->update($columns);
                }

                continue;
            }
            $inserts[] = $columns + [
                'source' => self::SOURCE,
                'source_product_id' => $row['product'],
                'source_subtype' => $row['subtype'],
                'variant_key' => $key,
                'name_agreement' => true,
                'series_epoch' => ($lastEpoch[$key] ?? 0) + 1,
                'map_build_id' => $buildId,
            ];
        }
        foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
            $this->db->table('source_product_map')->insert($chunk);
        }

        return [
            'remapped' => count($ending),
            'unchanged' => $unchanged,
            'inserted' => count($inserts),
            'stale_kept' => count(array_diff_key($current, $desired, $ending)),
        ];
    }
}
