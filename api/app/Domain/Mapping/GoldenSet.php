<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Judges the current product map against the golden file Andres signs off (the artefact follows the dc.py contract).
 *
 * Per golden row: the current tcgplayer mapping of `variant_key` must be exactly `expect` (groupId, productId,
 * subTypeName), else a mismatch (an unmapped key is a mismatch with actual null); a mapping to any `forbid`
 * product, whatever its subtype, is also a forbidden hit. value = mismatches + forbidden hits + 1 unless the
 * approval is ratified.
 *
 * Ratified (the manifest's andres_ratified_data_rule, kept offline): the file lives in a git checkout, and its
 * working copy is blob-equal to the copy at HEAD and at the pushed tip T, where T is the commit the pushed ref
 * names (default refs/remotes/origin/main, the local record of Andres's last push; nothing here talks to a remote).
 * The approval is ratified when gates/golden-set.approved.sha256 is exactly one `sha256sum` line for the golden file
 * (its lower-case sha256, two spaces, its path from the checkout root), that hash is the golden file's, and both
 * files are ratified.
 *
 * classes_missing counts the required classes (every scope-8 risk class and every oracle-blind class) that no row
 * names. mapped_cards_regression is 0 only when gates/data-baseline.json is well-formed and ratified and the cards
 * the current map reaches are at least 99.5% of its mapped_cards; anything it cannot decide counts as 1.
 * denominator = golden rows evaluated. Reads only.
 */
final readonly class GoldenSet
{
    public const string APPROVAL = 'gates/golden-set.approved.sha256';

    public const string BASELINE = 'gates/data-baseline.json';

    public const string PUSHED_REF = 'refs/remotes/origin/main';

    /** Phase scope 8: Base Charizard x 3 editions, basep, trainer kits, Unown !/?, delta energies, pattern reverses, a Cosmos holo. */
    public const array SCOPE_CLASSES = [
        'charizard_unlimited', 'charizard_shadowless', 'charizard_1st', 'basep', 'trainer_kit', 'unown_punctuation',
        'delta_energy', 'pattern_pokeball', 'pattern_masterball', 'pattern_energy', 'cosmos_holo',
    ];

    /** The classes OracleComparison sorts oracle-blind variants into. */
    public const array BLIND_CLASSES = ['group_1663', 'trainer_kit', 'basep', 'pattern', 'me_era', 'other'];

    /** Mapped cards must stay at or above this many per mille of the baseline's mapped_cards. */
    public const int FLOOR_PER_MILLE = 995;

    private const array SUBTYPES = [
        'Normal', 'Holofoil', 'Reverse Holofoil', '1st Edition', 'Unlimited', '1st Edition Holofoil', 'Unlimited Holofoil',
    ];

    private const string KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._!?-]{0,127}:(normal|holo|reverse):(-|1st|unlimited|shadowless):(-|[a-z0-9]+(-[a-z0-9]+)*)\z/';

    private const array ROW_KEYS = ['row', 'variant_key', 'expect', 'forbid', 'classes', 'oracle', 'why'];

    private const array BASELINE_KEYS = ['snapshot_day', 'dataset_ref', 'mapped_cards', 'dataset_cards', 'ambiguous_cards'];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array{id: int, snapshot_day: string, dataset_ref: string, marker: string}  $build
     * @return array<string, mixed> the golden-set artefact
     *
     * @throws InvalidArgumentException when the golden file breaks the row shape
     * @throws RuntimeException when the golden file cannot be read
     */
    public function evaluate(array $build, string $file, string $pushedRef = self::PUSHED_REF): array
    {
        $bytes = is_file($file) ? file_get_contents($file) : false;
        if (! is_string($bytes)) {
            throw new RuntimeException("golden file {$file} is missing or unreadable");
        }
        $rows = self::parse($bytes);
        $sha = hash('sha256', $bytes);

        $current = $this->currentMappings(array_column($rows, 'variant_key'));
        $mismatches = [];
        $hits = [];
        foreach ($rows as $row) {
            $actual = $current[$row['variant_key']] ?? null;
            if ($actual !== $row['expect']) {
                $mismatches[] = ['kind' => 'mismatch', 'row' => $row['row'], 'variant_key' => $row['variant_key'], 'expect' => $row['expect'], 'actual' => $actual];
            }
            foreach ($row['forbid'] as $forbid) {
                if ($actual !== null && $actual['group_id'] === $forbid['group_id'] && $actual['product_id'] === $forbid['product_id']) {
                    $hits[] = ['kind' => 'forbidden_hit', 'row' => $row['row'], 'variant_key' => $row['variant_key'], 'group_id' => $forbid['group_id'], 'product_id' => $forbid['product_id']];
                }
            }
        }

        $named = [];
        foreach ($rows as $row) {
            foreach ($row['classes'] as $class) {
                $named[$class] = true;
            }
        }
        $missing = [];
        foreach (array_unique([...self::SCOPE_CLASSES, ...self::BLIND_CLASSES]) as $class) {
            if (! isset($named[$class])) {
                $missing[] = ['kind' => 'class_missing', 'class' => $class];
            }
        }

        $real = realpath($file);
        $root = $real === false ? null : self::checkoutRoot(dirname($real));
        $relative = $root !== null && $real !== false && str_starts_with($real, $root.'/') ? substr($real, strlen($root) + 1) : null;
        $tip = $root === null ? null : self::git($root, 'rev-parse', '--verify', '--quiet', $pushedRef.'^{commit}');

        $approvalReason = self::approvalReason($root, $relative, $tip, $sha);
        $approval = [
            'kind' => 'approval', 'ratified' => $approvalReason === null, 'reason' => $approvalReason,
            'file' => self::APPROVAL, 'pushed_ref' => $pushedRef, 'pushed_tip' => $tip,
        ];

        $mappedCards = $this->mappedCards();
        [$baseline, $baselineReason] = self::baseline($root, $tip);
        if ($baseline !== null && $mappedCards * 1000 < $baseline['mapped_cards'] * self::FLOOR_PER_MILLE) {
            $baselineReason = 'below_floor';
        }
        $baselineDetail = [
            'kind' => 'baseline', 'reason' => $baselineReason, 'file' => self::BASELINE, 'mapped_cards' => $mappedCards,
            'baseline_mapped_cards' => $baseline['mapped_cards'] ?? null, 'floor_per_mille' => self::FLOOR_PER_MILLE,
        ];

        $ratified = $approvalReason === null ? 1 : 0;
        $details = [
            ['kind' => 'summary', 'rows' => count($rows), 'mismatches' => count($mismatches), 'forbidden_hits' => count($hits)],
            $approval,
            $baselineDetail,
            ...$mismatches,
            ...$hits,
            ...$missing,
        ];

        return [
            'id' => 'DC4',
            'value' => count($mismatches) + count($hits) + (1 - $ratified),
            'denominator' => count($rows),
            'details' => $details,
            'inputs' => ['snapshot_day' => $build['snapshot_day'], 'dataset_ref' => $build['dataset_ref'], 'marker' => $build['marker'], 'map_build_id' => $build['id']],
            'classes_missing' => count($missing),
            'mapped_cards_regression' => $baselineReason === null ? 0 : 1,
            'golden_sha256' => $sha,
            'approval_ratified' => $ratified,
        ];
    }

    /**
     * @return list<array{row: int, variant_key: string, expect: array{group_id: int, product_id: int, sub_type_name: string}, forbid: list<array{group_id: int, product_id: int}>, classes: list<string>}>
     *
     * @throws InvalidArgumentException on any row that breaks the golden shape
     */
    public static function parse(string $bytes): array
    {
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('golden file is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($document) || ! array_is_list($document) || $document === []) {
            throw new InvalidArgumentException('golden file must be a non-empty JSON list of rows');
        }

        $rows = [];
        $seenRows = [];
        $seenKeys = [];
        foreach ($document as $i => $row) {
            $at = 'golden row #'.($i + 1);
            if (! is_array($row) || self::keysAre($row, self::ROW_KEYS) === false) {
                throw new InvalidArgumentException("{$at} must have exactly the keys ".implode(', ', self::ROW_KEYS));
            }
            if (! is_int($row['row']) || $row['row'] < 1 || isset($seenRows[$row['row']])) {
                throw new InvalidArgumentException("{$at}: row must be a unique positive integer");
            }
            if (! is_string($row['variant_key']) || preg_match(self::KEY_PATTERN, $row['variant_key']) !== 1 || isset($seenKeys[$row['variant_key']])) {
                throw new InvalidArgumentException("{$at}: variant_key must be a unique card:finish:edition:treatment key");
            }
            $expect = $row['expect'];
            if (! is_array($expect) || ! self::keysAre($expect, ['group_id', 'product_id', 'sub_type_name'])
                || ! self::positiveInt($expect['group_id']) || ! self::positiveInt($expect['product_id'])
                || ! in_array($expect['sub_type_name'], self::SUBTYPES, true)) {
                throw new InvalidArgumentException("{$at}: expect must be {group_id, product_id, sub_type_name} with positive ids and a TCGplayer subTypeName");
            }
            $forbid = [];
            foreach (is_array($row['forbid']) && array_is_list($row['forbid']) ? $row['forbid'] : [null] as $f) {
                if (! is_array($f) || ! self::keysAre($f, ['group_id', 'product_id']) || ! self::positiveInt($f['group_id']) || ! self::positiveInt($f['product_id'])) {
                    throw new InvalidArgumentException("{$at}: forbid must be a list of {group_id, product_id} with positive ids");
                }
                if ($f['product_id'] === $expect['product_id'] && $f['group_id'] === $expect['group_id']) {
                    throw new InvalidArgumentException("{$at}: the expected product cannot also be forbidden");
                }
                $forbid[] = ['group_id' => (int) $f['group_id'], 'product_id' => (int) $f['product_id']];
            }
            $classes = $row['classes'];
            if (! is_array($classes) || ! array_is_list($classes) || $classes === []) {
                throw new InvalidArgumentException("{$at}: classes must be a non-empty list");
            }
            foreach ($classes as $class) {
                if (! is_string($class) || preg_match('/\A[a-z0-9]+(_[a-z0-9]+)*\z/', $class) !== 1) {
                    throw new InvalidArgumentException("{$at}: every class must be a lower-case snake_case name");
                }
            }
            if (! is_string($row['oracle']) || ! is_string($row['why']) || trim($row['why']) === '') {
                throw new InvalidArgumentException("{$at}: oracle must be a string and why a non-empty string");
            }

            $seenRows[$row['row']] = true;
            $seenKeys[$row['variant_key']] = true;
            $rows[] = [
                'row' => $row['row'],
                'variant_key' => $row['variant_key'],
                'expect' => ['group_id' => (int) $expect['group_id'], 'product_id' => (int) $expect['product_id'], 'sub_type_name' => (string) $expect['sub_type_name']],
                'forbid' => $forbid,
                'classes' => array_values(array_map(strval(...), $classes)),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{group_id: int, product_id: int, sub_type_name: string}>
     */
    private function currentMappings(array $keys): array
    {
        $out = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $rows = $this->db->table('source_product_map')
                ->where('source', MapBuilder::SOURCE)
                ->whereIn('variant_key', $chunk)
                ->get(['variant_key', 'source_group_id', 'source_product_id', 'source_subtype']);
            foreach ($rows as $row) {
                $r = (array) $row;
                $out[(string) $r['variant_key']] = [
                    'group_id' => (int) $r['source_group_id'],
                    'product_id' => (int) $r['source_product_id'],
                    'sub_type_name' => (string) $r['source_subtype'],
                ];
            }
        }

        return $out;
    }

    /** Distinct cards the current tcgplayer map reaches. */
    private function mappedCards(): int
    {
        $row = $this->db->table('source_product_map as m')
            ->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')
            ->where('m.source', MapBuilder::SOURCE)
            ->selectRaw('count(distinct v.card_id) as n')
            ->first();

        return $row === null ? 0 : (int) ((array) $row)['n'];
    }

    /** @return string|null why the approval is not ratified; null when it is */
    private static function approvalReason(?string $root, ?string $relative, ?string $tip, string $sha): ?string
    {
        if ($root === null || $relative === null) {
            return 'not_in_git_checkout';
        }
        $line = is_file($root.'/'.self::APPROVAL) ? file_get_contents($root.'/'.self::APPROVAL) : false;
        if (! is_string($line)) {
            return 'approval_missing';
        }
        if (preg_match('/\A([0-9a-f]{64})  (.+)\n?\z/', $line, $m) !== 1 || $m[2] !== $relative) {
            return 'approval_malformed';
        }
        if (! hash_equals($m[1], $sha)) {
            return 'approval_stale';
        }
        if (! self::ratified($root, self::APPROVAL, $tip) || ! self::ratified($root, $relative, $tip)) {
            return 'approval_unpushed';
        }

        return null;
    }

    /**
     * @return array{0: array{snapshot_day: string, dataset_ref: string, mapped_cards: int, dataset_cards: int, ambiguous_cards: int}|null, 1: string|null}
     */
    private static function baseline(?string $root, ?string $tip): array
    {
        if ($root === null) {
            return [null, 'not_in_git_checkout'];
        }
        $bytes = is_file($root.'/'.self::BASELINE) ? file_get_contents($root.'/'.self::BASELINE) : false;
        if (! is_string($bytes)) {
            return [null, 'baseline_missing'];
        }
        $b = json_decode($bytes, true);
        if (! is_array($b) || ! self::keysAre($b, self::BASELINE_KEYS)
            || ! is_string($b['snapshot_day']) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $b['snapshot_day']) !== 1
            || ! is_string($b['dataset_ref']) || preg_match('/\A[0-9a-f]{40}\z/', $b['dataset_ref']) !== 1
            || ! is_int($b['mapped_cards']) || ! is_int($b['dataset_cards']) || ! is_int($b['ambiguous_cards'])
            || $b['mapped_cards'] < 1 || $b['ambiguous_cards'] < 0 || $b['mapped_cards'] > $b['dataset_cards']) {
            return [null, 'baseline_malformed'];
        }
        if (! self::ratified($root, self::BASELINE, $tip)) {
            return [null, 'baseline_unpushed'];
        }

        return [[
            'snapshot_day' => $b['snapshot_day'], 'dataset_ref' => $b['dataset_ref'], 'mapped_cards' => $b['mapped_cards'],
            'dataset_cards' => $b['dataset_cards'], 'ambiguous_cards' => $b['ambiguous_cards'],
        ], null];
    }

    /** The working copy of $path is blob-equal to its copy at HEAD and at the pushed tip. */
    private static function ratified(string $root, string $path, ?string $tip): bool
    {
        $bytes = is_file($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;
        if ($tip === null || ! is_string($bytes)) {
            return false;
        }
        $blob = sha1('blob '.strlen($bytes)."\0".$bytes);
        foreach (['HEAD', $tip] as $commit) {
            if (self::git($root, 'rev-parse', '--verify', '--quiet', $commit.':'.$path) !== $blob) {
                return false;
            }
        }

        return true;
    }

    private static function checkoutRoot(string $dir): ?string
    {
        $root = self::git($dir, 'rev-parse', '--show-toplevel');
        $real = $root === null ? false : realpath($root);

        return $real === false ? null : $real;
    }

    /** One git command's trimmed stdout, or null when it fails or prints nothing. */
    private static function git(string $dir, string ...$args): ?string
    {
        try {
            $result = Process::path($dir)->timeout(60)->run(['git', ...$args]);
        } catch (Throwable) {
            return null;
        }
        $out = trim($result->output());

        return $result->successful() && $out !== '' ? $out : null;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $keys
     */
    private static function keysAre(array $value, array $keys): bool
    {
        $have = array_keys($value);
        sort($have);
        sort($keys);

        return $have === $keys;
    }

    private static function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
