<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// pokeprices:map:golden (plan T53, the DC4 artefact in T25's dc.py contract). Each test writes a throwaway git
// checkout to a temp directory holding api/database/data/golden-set.json and, when the case needs them,
// gates/golden-set.approved.sha256 and gates/data-baseline.json. "Pushed" means the commit that
// refs/remotes/origin/main names (the local record of Andres's last push); nothing here talks to a remote.
// Mapping rows go straight into the test database. No price value exists anywhere in these rows.

const T53_REF = '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11';

const T53_MARKER = '2026-10-11T20:05:45+00:00';

const T53_GOLDEN = 'api/database/data/golden-set.json';

const T53_APPROVAL = 'gates/golden-set.approved.sha256';

const T53_BASELINE = 'gates/data-baseline.json';

/** Every scope-8 risk class and every DC2 blind class (the DC4 classes_missing contract). */
const T53_REQUIRED = [
    'charizard_unlimited', 'charizard_shadowless', 'charizard_1st', 'basep', 'trainer_kit', 'unown_punctuation',
    'delta_energy', 'pattern_pokeball', 'pattern_masterball', 'pattern_energy', 'cosmos_holo',
    'group_1663', 'pattern', 'me_era', 'other',
];

/** A fresh temp directory, removed after the test (t53Temp(true) returns and forgets the remembered ones). */
function t53Temp(bool $drain = false): string|array
{
    static $roots = [];
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }
    $dir = sys_get_temp_dir().'/pp-t53-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    $roots[] = $dir;

    return $dir;
}

afterEach(function (): void {
    foreach (t53Temp(true) as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir((string) $file->getPathname());
            } else {
                unlink((string) $file->getPathname());
            }
        }
        rmdir($root);
    }
});

function t53Build(): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11',
        'dataset_ref' => T53_REF,
        'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00',
        'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => T53_MARKER], JSON_THROW_ON_ERROR),
    ]);
}

/** One current tcgplayer mapping row for $key (card:finish:edition:treatment) and its variant. */
function t53Map(int $build, string $key, int $group, int $product, string $subtype): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore([
        'variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment,
    ]);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'synthetic', 'source_number' => null, 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

/**
 * @param  array{int, int, string}  $expect
 * @param  list<array{int, int}>  $forbid
 * @param  list<string>  $classes
 */
function t53Row(int $row, string $key, array $expect, array $forbid, array $classes): array
{
    return [
        'row' => $row,
        'variant_key' => $key,
        'expect' => ['group_id' => $expect[0], 'product_id' => $expect[1], 'sub_type_name' => $expect[2]],
        'forbid' => array_map(fn (array $f): array => ['group_id' => $f[0], 'product_id' => $f[1]], $forbid),
        'classes' => $classes,
        'oracle' => 'no-key',
        'why' => 'synthetic test row',
    ];
}

/** Two rows that between them name every required class. */
function t53Golden(): array
{
    return [
        t53Row(1, 'base1-4:holo:unlimited:-', [604, 42382, 'Holofoil'], [[1663, 106999]], array_slice(T53_REQUIRED, 0, 8)),
        t53Row(2, 'sv8pt5-5:reverse:-:pokeball', [23821, 610540, 'Holofoil'], [[23821, 610360]], array_slice(T53_REQUIRED, 8)),
    ];
}

/** Maps both rows of t53Golden() exactly as they expect. */
function t53MapExact(int $build): void
{
    t53Map($build, 'base1-4:holo:unlimited:-', 604, 42382, 'Holofoil');
    t53Map($build, 'sv8pt5-5:reverse:-:pokeball', 23821, 610540, 'Holofoil');
}

function t53Git(string $repo, string ...$args): string
{
    $result = Process::path($repo)->run(['git', '-c', 'user.name=t53', '-c', 'user.email=t53@example.invalid', '-c', 'commit.gpgsign=false', ...$args]);
    if (! $result->successful()) {
        throw new RuntimeException('git '.implode(' ', $args).' failed: '.$result->errorOutput());
    }

    return trim($result->output());
}

/** The approval line Andres signs: `sha256sum api/database/data/golden-set.json` run at the repository root. */
function t53ApprovalLine(string $repo): string
{
    return hash_file('sha256', $repo.'/'.T53_GOLDEN).'  '.T53_GOLDEN."\n";
}

/**
 * A throwaway checkout. $pushed files are committed and recorded as pushed (refs/remotes/origin/main);
 * $unpushed files are committed after that tip. Names: 'approval', 'baseline'. The golden file is always pushed.
 *
 * @param  list<string>  $pushed
 * @param  list<string>  $unpushed
 */
function t53Repo(array $golden, array $pushed = ['approval', 'baseline'], array $unpushed = [], ?array $baseline = null): string
{
    $repo = t53Temp();
    t53Git($repo, 'init', '-q', '-b', 'main');
    mkdir($repo.'/api/database/data', 0775, true);
    mkdir($repo.'/gates', 0775, true);
    file_put_contents($repo.'/'.T53_GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    $write = function (string $name) use ($repo, $baseline): string {
        if ($name === 'approval') {
            file_put_contents($repo.'/'.T53_APPROVAL, t53ApprovalLine($repo));

            return T53_APPROVAL;
        }
        file_put_contents($repo.'/'.T53_BASELINE, json_encode($baseline ?? [
            'snapshot_day' => '2026-10-11', 'dataset_ref' => T53_REF, 'mapped_cards' => 2, 'dataset_cards' => 3, 'ambiguous_cards' => 0,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        return T53_BASELINE;
    };
    t53Git($repo, 'add', T53_GOLDEN, ...array_map($write, $pushed));
    t53Git($repo, 'commit', '-q', '-m', 'pushed');
    t53Git($repo, 'update-ref', 'refs/remotes/origin/main', 'HEAD');
    if ($unpushed !== []) {
        t53Git($repo, 'add', ...array_map($write, $unpushed));
        t53Git($repo, 'commit', '-q', '-m', 'not pushed');
    }

    return $repo;
}

/** Runs the command with --json and --out; returns [exit code, decoded artefact from the file, stdout]. */
function t53Run(string $file, array $extra = []): array
{
    $out = t53Temp().'/DC4.artifact.json';
    $code = Artisan::call('pokeprices:map:golden', ['--file' => $file, '--json' => true, '--out' => $out, ...$extra]);
    $stdout = Artisan::output();
    $artefact = is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null;

    return [$code, $artefact, $stdout];
}

/** @return list<array<string, mixed>> the details of one kind */
function t53Details(array $artefact, string $kind): array
{
    return array_values(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === $kind));
}

test('an exact match with a pushed approval and baseline gives value 0 and the full DC4 artefact', function (): void {
    $build = t53Build();
    t53MapExact($build);
    $repo = t53Repo(t53Golden());

    [$code, $artefact, $stdout] = t53Run($repo.'/'.T53_GOLDEN);

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe([
            'id', 'value', 'denominator', 'details', 'inputs', 'classes_missing', 'mapped_cards_regression', 'golden_sha256', 'approval_ratified',
        ])
        ->and($artefact['id'])->toBe('DC4')
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(2)
        ->and($artefact['classes_missing'])->toBe(0)
        ->and($artefact['mapped_cards_regression'])->toBe(0)
        ->and($artefact['approval_ratified'])->toBe(1)
        ->and($artefact['golden_sha256'])->toBe(hash_file('sha256', $repo.'/'.T53_GOLDEN))
        ->and($artefact['inputs'])->toBe(['snapshot_day' => '2026-10-11', 'dataset_ref' => T53_REF, 'marker' => T53_MARKER, 'map_build_id' => $build])
        ->and(t53Details($artefact, 'mismatch'))->toBe([])
        ->and(t53Details($artefact, 'forbidden_hit'))->toBe([])
        ->and(json_decode($stdout, true))->toBe($artefact);
});

test('a forbidden product mapped to a golden key is counted as a forbidden hit on top of the mismatch', function (): void {
    $build = t53Build();
    t53Map($build, 'base1-4:holo:unlimited:-', 1663, 106999, 'Unlimited Holofoil');
    t53Map($build, 'sv8pt5-5:reverse:-:pokeball', 23821, 610540, 'Holofoil');
    $repo = t53Repo(t53Golden());

    [$code, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    $hits = t53Details($artefact, 'forbidden_hit');
    $mismatches = t53Details($artefact, 'mismatch');
    expect($code)->toBe(0)
        ->and($artefact['approval_ratified'])->toBe(1)
        ->and($artefact['value'])->toBe(2)
        ->and($hits)->toHaveCount(1)
        ->and($hits[0])->toMatchArray(['row' => 1, 'variant_key' => 'base1-4:holo:unlimited:-', 'group_id' => 1663, 'product_id' => 106999])
        ->and($mismatches)->toHaveCount(1)
        ->and($mismatches[0]['actual'])->toBe(['group_id' => 1663, 'product_id' => 106999, 'sub_type_name' => 'Unlimited Holofoil']);
});

test('a golden key the map does not hold at all is a mismatch with no actual product', function (): void {
    $build = t53Build();
    t53Map($build, 'base1-4:holo:unlimited:-', 604, 42382, 'Holofoil');
    $repo = t53Repo(t53Golden());

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    $mismatches = t53Details($artefact, 'mismatch');
    expect($artefact['value'])->toBe(1)
        ->and($mismatches)->toHaveCount(1)
        ->and($mismatches[0])->toMatchArray(['row' => 2, 'variant_key' => 'sv8pt5-5:reverse:-:pokeball', 'actual' => null]);
});

test('the right product under the wrong subTypeName is a mismatch', function (): void {
    $build = t53Build();
    t53Map($build, 'base1-4:holo:unlimited:-', 604, 42382, 'Holofoil');
    t53Map($build, 'sv8pt5-5:reverse:-:pokeball', 23821, 610540, 'Reverse Holofoil');
    $repo = t53Repo(t53Golden());

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['value'])->toBe(1)
        ->and(t53Details($artefact, 'mismatch')[0]['row'])->toBe(2);
});

test('an approval committed but not pushed adds 1 and leaves approval_ratified at 0', function (): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), ['baseline'], ['approval']);

    [$code, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['classes_missing'])->toBe(0)
        ->and($artefact['mapped_cards_regression'])->toBe(0)
        ->and(t53Details($artefact, 'approval')[0]['reason'])->toBe('approval_unpushed');
});

test('a missing approval adds 1', function (): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), ['baseline']);

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['value'])->toBe(1)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and(t53Details($artefact, 'approval')[0]['reason'])->toBe('approval_missing');
});

test('a golden file edited after it was signed makes the approval stale and adds 1', function (): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden());
    $rows = t53Golden();
    $rows[0]['why'] = 'edited after signing';
    file_put_contents($repo.'/'.T53_GOLDEN, json_encode($rows, JSON_THROW_ON_ERROR));

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['value'])->toBe(1)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and(t53Details($artefact, 'approval')[0]['reason'])->toBe('approval_stale');
});

test('an approval line that is not sha256sum output for the golden path is malformed and adds 1', function (Closure $line): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), ['baseline']);
    file_put_contents($repo.'/'.T53_APPROVAL, $line(hash_file('sha256', $repo.'/'.T53_GOLDEN)));
    t53Git($repo, 'add', T53_APPROVAL);
    t53Git($repo, 'commit', '-q', '-m', 'signed');
    t53Git($repo, 'update-ref', 'refs/remotes/origin/main', 'HEAD');

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['value'])->toBe(1)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and(t53Details($artefact, 'approval')[0]['reason'])->toBe('approval_malformed');
})->with([
    'the bare hash' => fn (string $h): string => "{$h}\n",
    'another path' => fn (string $h): string => "{$h}  golden-set.json\n",
    'one space' => fn (string $h): string => "{$h} ".T53_GOLDEN."\n",
    'two lines' => fn (string $h): string => str_repeat("{$h}  ".T53_GOLDEN."\n", 2),
    'upper-case hex' => fn (string $h): string => strtoupper($h).'  '.T53_GOLDEN."\n",
]);

test('a golden file outside any git checkout can never be ratified', function (): void {
    t53MapExact(t53Build());
    $file = t53Temp().'/golden-set.json';
    file_put_contents($file, json_encode(t53Golden(), JSON_THROW_ON_ERROR));

    [$code, $artefact] = t53Run($file);

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['mapped_cards_regression'])->toBe(1);
});

test('a golden file that names no row for a required class reports classes_missing', function (): void {
    t53MapExact(t53Build());
    $rows = t53Golden();
    $rows[1]['classes'] = array_values(array_diff($rows[1]['classes'], ['cosmos_holo', 'me_era']));
    $repo = t53Repo($rows);

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['classes_missing'])->toBe(2)
        ->and(array_column(t53Details($artefact, 'class_missing'), 'class'))->toEqualCanonicalizing(['cosmos_holo', 'me_era'])
        ->and($artefact['value'])->toBe(0);
});

test('mapped cards below 99.5 percent of the pushed baseline are a regression', function (): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), baseline: [
        'snapshot_day' => '2026-10-11', 'dataset_ref' => T53_REF, 'mapped_cards' => 3, 'dataset_cards' => 3, 'ambiguous_cards' => 0,
    ]);

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['mapped_cards_regression'])->toBe(1)
        ->and(t53Details($artefact, 'baseline')[0])->toMatchArray(['mapped_cards' => 2, 'baseline_mapped_cards' => 3])
        ->and($artefact['value'])->toBe(0);
});

test('a baseline that is missing or not pushed cannot vouch for the mapped-card floor', function (array $pushed, array $unpushed, string $reason): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), $pushed, $unpushed);

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['mapped_cards_regression'])->toBe(1)
        ->and(t53Details($artefact, 'baseline')[0]['reason'])->toBe($reason);
})->with([
    'missing' => [['approval'], [], 'baseline_missing'],
    'committed, not pushed' => [['approval'], ['baseline'], 'baseline_unpushed'],
]);

test('a baseline with a wrong field set is malformed', function (): void {
    t53MapExact(t53Build());
    $repo = t53Repo(t53Golden(), baseline: ['snapshot_day' => '2026-10-11', 'dataset_ref' => T53_REF, 'mapped_cards' => 2]);

    [, $artefact] = t53Run($repo.'/'.T53_GOLDEN);

    expect($artefact['mapped_cards_regression'])->toBe(1)
        ->and(t53Details($artefact, 'baseline')[0]['reason'])->toBe('baseline_malformed');
});

test('a golden file that breaks the row shape is refused and writes no artefact', function (Closure $break): void {
    t53MapExact(t53Build());
    $rows = t53Golden();
    $file = t53Temp().'/golden-set.json';
    file_put_contents($file, json_encode($break($rows), JSON_THROW_ON_ERROR));

    [$code, $artefact] = t53Run($file);

    expect($code)->toBe(1)
        ->and($artefact)->toBeNull();
})->with([
    'not a list' => fn (array $r): array => ['rows' => $r],
    'a row without expect' => function (array $r): array {
        unset($r[0]['expect']);

        return $r;
    },
    'an extra key' => function (array $r): array {
        $r[0]['note'] = 'x';

        return $r;
    },
    'a string product id' => function (array $r): array {
        $r[0]['expect']['product_id'] = '42382';

        return $r;
    },
    'an unknown subTypeName' => function (array $r): array {
        $r[0]['expect']['sub_type_name'] = 'Foil';

        return $r;
    },
    'a duplicate variant key' => function (array $r): array {
        $r[1]['variant_key'] = $r[0]['variant_key'];

        return $r;
    },
    'a duplicate row number' => function (array $r): array {
        $r[1]['row'] = 1;

        return $r;
    },
    'no classes' => function (array $r): array {
        $r[0]['classes'] = [];

        return $r;
    },
    'expect also forbidden' => function (array $r): array {
        $r[0]['forbid'][] = ['group_id' => 604, 'product_id' => 42382];

        return $r;
    },
    'a malformed variant key' => function (array $r): array {
        $r[0]['variant_key'] = 'base1-4:holo';

        return $r;
    },
]);

test('the shipped golden file has at least 50 well-formed rows naming every required class', function (): void {
    t53Build();
    $rows = json_decode((string) file_get_contents(base_path('database/data/golden-set.json')), true, 512, JSON_THROW_ON_ERROR);

    [$code, $artefact] = t53Run(base_path('database/data/golden-set.json'));

    expect($code)->toBe(0)
        ->and(count($rows))->toBeGreaterThanOrEqual(50)
        ->and($artefact['denominator'])->toBe(count($rows))
        ->and($artefact['classes_missing'])->toBe(0)
        ->and(array_unique(array_merge(...array_column($rows, 'classes'))))->toContain(...T53_REQUIRED);
});
