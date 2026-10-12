<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// pokeprices:map:audit (plan T40) over synthetic map rows written straight into the test database and a small
// dataset written to a temp directory. Each check's artefact follows T25's dc.py contract: id, value,
// denominator, details (a list) and inputs {snapshot_day, dataset_ref, marker, map_build_id}; coverage adds
// coverage_pct and unlisted_sets. No price value exists anywhere in these rows.

const T40_REF = '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11';

const T40_MARKER = '2026-10-11T20:05:45+00:00';

/** A fresh temp directory, removed after the test (t40Temp(true) returns and forgets the remembered ones). */
function t40Temp(bool $drain = false): string|array
{
    static $roots = [];
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }
    $dir = sys_get_temp_dir().'/pp-t40-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    $roots[] = $dir;

    return $dir;
}

afterEach(function (): void {
    foreach (t40Temp(true) as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

/** Writes a dataset (sets/en.json and cards/en/<set>.json) to a temp directory and returns it. */
function t40Dataset(array $cardsBySet): string
{
    $dir = t40Temp();
    mkdir($dir.'/sets', 0775, true);
    mkdir($dir.'/cards/en', 0775, true);
    $sets = [];
    foreach ($cardsBySet as $set => $cards) {
        $sets[] = ['id' => $set, 'name' => "Set {$set}"];
        $list = [];
        foreach ($cards as [$id, $name, $number]) {
            $list[] = ['id' => $id, 'name' => $name, 'number' => $number];
        }
        file_put_contents("{$dir}/cards/en/{$set}.json", json_encode($list, JSON_THROW_ON_ERROR));
    }
    file_put_contents($dir.'/sets/en.json', json_encode($sets, JSON_THROW_ON_ERROR));

    return $dir;
}

function t40Build(string $day = '2026-10-11'): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day,
        'dataset_ref' => T40_REF,
        'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00',
        'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => T40_MARKER], JSON_THROW_ON_ERROR),
    ]);
}

function t40Alias(string $set, int $group, string $role = 'primary', ?string $rule = null): void
{
    DB::table('set_aliases')->insert([
        'pokesearch_set_id' => $set, 'source' => 'tcgplayer', 'source_group_id' => $group, 'role' => $role,
        'number_rule' => $rule, 'method' => 'manual', 'unlimited_means' => null, 'note' => 'test row',
    ]);
}

/** One current mapping row and its variant (finish holo, edition and treatment '-'). */
function t40Map(int $build, string $card, int $group, int $product, string $name, string $number, string $method = 'number+name', string $subtype = 'Holofoil'): string
{
    $key = "{$card}:holo:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo', 'edition' => '-', 'treatment' => '-']);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => $name, 'source_number' => $number, 'variant_key' => $key, 'method' => $method,
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);

    return $key;
}

function t40Unmapped(int $build, int $group, int $product, string $name, ?string $number, string $reason, array $candidates): void
{
    DB::table('unmapped_source_items')->insert([
        'map_build_id' => $build, 'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product,
        'source_subtype' => 'Holofoil', 'product_name' => $name, 'number' => $number, 'reason' => $reason,
        'candidates' => json_encode($candidates, JSON_THROW_ON_ERROR),
    ]);
}

/** Runs the audit with --json and --out; returns [exit code, decoded artefact from the file, stdout]. */
function t40Audit(string $check, string $dataset, array $extra = []): array
{
    $out = t40Temp().'/artefact.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => $check, '--json' => true, '--out' => $out, '--dataset' => $dataset, ...$extra]);
    $stdout = Artisan::output();
    $artefact = is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null;

    return [$code, $artefact, $stdout];
}

test('dc-inputs.json names the collected day, the dataset ref and the oracle directory exactly', function (): void {
    $inputs = json_decode((string) file_get_contents(base_path('database/data/dc-inputs.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($inputs)->toBe([
        'snapshot_day' => '2026-10-11',
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'oracle_dir' => '.data/oracle/2026-10-11',
    ]);
});

test('names counts every mapped row whose card and product names disagree after the alias grammar', function (): void {
    $build = t40Build();
    $dataset = t40Dataset([
        'base1' => [['base1-4', 'Charizard', '4'], ['base1-2', 'Blastoise', '2']],
        'ex12' => [['ex12-1', 'Unown', '!']],
        'ex11' => [['ex11-15', 'Starmie δ', '15']],
    ]);
    t40Map($build, 'base1-4', 604, 42382, 'Charizard', '004/102');
    t40Map($build, 'ex12-1', 1398, 90166, 'Unown (!)', '!/28');
    t40Map($build, 'ex11-15', 1430, 88001, 'Starmie - 015/113 (Delta Species)', '015/113', 'name_tiebreak');
    $wrong = t40Map($build, 'base1-2', 604, 42400, 'Venusaur', '002/102');
    $missing = t40Map($build, 'base1-999', 604, 42499, 'Mewtwo', '999/102');

    [$code, $artefact, $stdout] = t40Audit('names', $dataset);

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs'])
        ->and($artefact['id'])->toBe('DC1')
        ->and($artefact['value'])->toBe(2)
        ->and($artefact['denominator'])->toBe(5)
        ->and(array_column($artefact['details'], 'variant_key'))->toEqualCanonicalizing([$wrong, $missing])
        ->and($artefact['inputs'])->toBe(['snapshot_day' => '2026-10-11', 'dataset_ref' => T40_REF, 'marker' => T40_MARKER, 'map_build_id' => $build])
        ->and(json_decode($stdout, true))->toBe($artefact);
});

test('constraints reads the five unique constraints from pg_constraint and finds no duplicate on a clean schema', function (): void {
    $build = t40Build();
    t40Map($build, 'base1-4', 604, 42382, 'Charizard', '004/102');

    [$code, $artefact] = t40Audit('constraints', t40Dataset(['base1' => [['base1-4', 'Charizard', '4']]]));

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs'])
        ->and($artefact['id'])->toBe('DC3')
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(12)
        ->and($artefact['details'])->toBe([]);
});

test('constraints counts a dropped unique constraint and the duplicate rows it let in', function (): void {
    $build = t40Build();
    t40Map($build, 'base1-4', 604, 42382, 'Charizard', '004/102');
    DB::statement('ALTER TABLE source_product_map DROP CONSTRAINT source_product_map_variant_source_unique');
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 42383, 'source_subtype' => 'Holofoil',
        'source_product_name' => 'Charizard', 'source_number' => '004/102', 'variant_key' => 'base1-4:holo:-:-',
        'method' => 'number+name', 'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);

    [, $artefact] = t40Audit('constraints', t40Dataset(['base1' => [['base1-4', 'Charizard', '4']]]));

    expect($artefact['value'])->toBe(2)
        ->and($artefact['denominator'])->toBe(12)
        ->and(array_column($artefact['details'], 'kind'))->toEqualCanonicalizing(['missing_constraint', 'duplicate_rows'])
        ->and(array_column($artefact['details'], 'columns'))->each->toBe(['variant_key', 'source']);
});

test('constraints counts a unique constraint whose columns differ from the required ones as missing', function (): void {
    t40Build();
    DB::statement('ALTER TABLE source_product_map_history DROP CONSTRAINT source_product_map_history_variant_source_epoch_unique');
    DB::statement('ALTER TABLE source_product_map_history ADD CONSTRAINT source_product_map_history_variant_source_epoch_unique UNIQUE (variant_key, source)');

    [, $artefact] = t40Audit('constraints', t40Dataset(['base1' => [['base1-4', 'Charizard', '4']]]));

    expect($artefact['value'])->toBe(1)
        ->and($artefact['details'][0]['table'])->toBe('source_product_map_history')
        ->and($artefact['details'][0]['columns'])->toBe(['variant_key', 'source', 'series_epoch']);
});

test('coverage counts dataset cards neither mapped nor listed for review in the build, and unlisted sets', function (): void {
    $old = t40Build('2026-10-10');
    $build = t40Build();
    t40Alias('s1', 501);
    t40Alias('s2', 502);
    $dataset = t40Dataset([
        's1' => [['s1-1', 'Pikachu', '1'], ['s1-2', 'Raichu', '2'], ['s1-3', 'Pichu', '3']],
        's2' => [['s2-1', 'Eevee', '1'], ['s2-2', 'Vaporeon', '2']],
        's3' => [['s3-1', 'Mew', '1']],
        'fut20' => [],
    ]);
    t40Map($build, 's1-1', 501, 7001, 'Pikachu', '1');
    t40Map($build, 's1-2', 501, 7002, 'Raichu', '2');
    t40Unmapped($build, 501, 7003, 'Pichu (Unknown Tag)', '3', 'unknown_tag', ['s1-3']);
    t40Unmapped($old, 502, 7004, 'Eevee', '1', 'name_disagreement', ['s2-1']);

    [$code, $artefact] = t40Audit('coverage', $dataset);

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs', 'coverage_pct', 'unlisted_sets'])
        ->and($artefact['id'])->toBe('DC5')
        ->and($artefact['value'])->toBe(3)
        ->and($artefact['denominator'])->toBe(6)
        ->and($artefact['coverage_pct'])->toBe(33.33)
        ->and($artefact['unlisted_sets'])->toBe(1)
        ->and($artefact['inputs']['map_build_id'])->toBe($build)
        ->and(array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'uncovered_card'), 'card_id'))
        ->toEqualCanonicalizing(['s2-1', 's2-2', 's3-1'])
        ->and(array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'unlisted_set'), 'set_id'))
        ->toBe(['s3']);
});

test('ambiguity counts multi-candidate cards resolved by a method outside the allowed list', function (): void {
    $build = t40Build();
    t40Alias('tk1a', 1543);
    t40Alias('tk1b', 1543);
    t40Alias('sc', 777);
    $dataset = t40Dataset([
        'tk1a' => [['tk1a-1', 'Latias', '1'], ['tk1a-2', 'Pikachu', '2']],
        'tk1b' => [['tk1b-1', 'Latios', '1'], ['tk1b-2', 'Pikachu', '2']],
        'sc' => [['sc-5', 'Snorlax', '5']],
    ]);
    t40Map($build, 'tk1a-1', 1543, 1001, 'Latias (Latias)', '1/10', 'name_tiebreak');
    t40Map($build, 'tk1b-1', 1543, 1003, 'Latios', '1/10', 'number+name', 'Normal');
    t40Map($build, 'sc-5', 777, 1005, 'Snorlax', '5');
    t40Unmapped($build, 1543, 1002, 'Pikachu', '2/10', 'ambiguous', ['tk1a-2', 'tk1b-2']);

    [$code, $artefact] = t40Audit('ambiguity', $dataset);

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs'])
        ->and($artefact['id'])->toBe('DC6')
        ->and($artefact['value'])->toBe(1)
        ->and($artefact['denominator'])->toBe(4)
        ->and(array_column($artefact['details'], 'card_id'))->toBe(['tk1b-1'])
        ->and($artefact['details'][0]['method'])->toBe('number+name');
});

test('without --dataset the audit reads services.dataset.root/<the build\'s dataset_ref>', function (): void {
    $build = t40Build();
    t40Map($build, 'base1-4', 604, 42382, 'Charizard', '004/102');
    $dataset = t40Dataset(['base1' => [['base1-4', 'Charizard', '4']]]);
    $root = t40Temp();
    rename($dataset, $root.'/'.T40_REF);
    config(['services.dataset.root' => $root]);

    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'names', '--json' => true]);

    $output = Artisan::output();

    expect($code)->toBe(0, $output)
        ->and(json_decode($output, true)['denominator'])->toBe(1);
});

test('the audit refuses an unknown check and a database without a map build, writing no artefact', function (): void {
    $dataset = t40Dataset(['base1' => [['base1-4', 'Charizard', '4']]]);

    [$noBuild, $none] = t40Audit('names', $dataset);
    t40Build();
    [$badCheck, $none2] = t40Audit('prices', $dataset);

    expect($noBuild)->toBe(1)
        ->and($none)->toBeNull()
        ->and($badCheck)->toBe(1)
        ->and($none2)->toBeNull();
});

test('coverage counts a card listed in unmapped_cards for the build as accounted, and only for that build', function (): void {
    $old = t40Build('2026-10-10');
    $build = t40Build();
    t40Alias('s1', 501);
    $dataset = t40Dataset(['s1' => [['s1-1', 'Pikachu', '1'], ['s1-2', 'Raichu', '2'], ['s1-3', 'Pichu', '3']]]);
    t40Map($build, 's1-1', 501, 7001, 'Pikachu', '1');
    DB::table('unmapped_cards')->insert(['map_build_id' => $build, 'source' => 'tcgplayer', 'card_id' => 's1-2', 'reason' => 'no_priced_product']);
    DB::table('unmapped_cards')->insert(['map_build_id' => $old, 'source' => 'tcgplayer', 'card_id' => 's1-3', 'reason' => 'no_priced_product']);

    [, $artefact] = t40Audit('coverage', $dataset);

    expect($artefact['value'])->toBe(1)
        ->and($artefact['coverage_pct'])->toBe(33.33)
        ->and(array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'uncovered_card'), 'card_id'))->toBe(['s1-3']);
});
