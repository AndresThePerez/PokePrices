<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// Reviewer edge cases for pokeprices:map:golden (T53). Self-contained (own helpers, prefix t53e) so the file runs alone.
// Every case builds a throwaway git checkout in a temp directory; nothing reads the shipped gates files or any price.

const T53E_REF = '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11';

const T53E_GOLDEN = 'api/database/data/golden-set.json';

const T53E_APPROVAL = 'gates/golden-set.approved.sha256';

const T53E_BASELINE = 'gates/data-baseline.json';

const T53E_CLASSES = [
    'charizard_unlimited', 'charizard_shadowless', 'charizard_1st', 'basep', 'trainer_kit', 'unown_punctuation',
    'delta_energy', 'pattern_pokeball', 'pattern_masterball', 'pattern_energy', 'cosmos_holo',
    'group_1663', 'pattern', 'me_era', 'other',
];

/** @return list<string> */
function t53eRoots(?string $new = null, bool $drain = false): array
{
    static $roots = [];
    if ($new !== null) {
        $roots[] = $new;
    }
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }

    return $roots;
}

function t53eTemp(): string
{
    $dir = sys_get_temp_dir().'/pp-t53e-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    t53eRoots($dir);

    return $dir;
}

afterEach(function (): void {
    foreach (t53eRoots(null, true) as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

function t53eBuild(): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => T53E_REF, 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
}

function t53eMap(int $build, string $key, int $group, int $product, string $subtype): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment]);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'synthetic', 'source_number' => null, 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

/** Two rows that between them name every required class. */
function t53eGolden(): array
{
    $row = fn (int $n, string $key, array $e, array $f, array $classes): array => [
        'row' => $n, 'variant_key' => $key,
        'expect' => ['group_id' => $e[0], 'product_id' => $e[1], 'sub_type_name' => $e[2]],
        'forbid' => [['group_id' => $f[0], 'product_id' => $f[1]]], 'classes' => $classes, 'oracle' => 'no-key', 'why' => 'synthetic test row',
    ];

    return [
        $row(1, 'base1-4:holo:unlimited:-', [604, 42382, 'Holofoil'], [1663, 106999], array_slice(T53E_CLASSES, 0, 8)),
        $row(2, 'sv8pt5-5:reverse:-:pokeball', [23821, 610540, 'Holofoil'], [23821, 610360], array_slice(T53E_CLASSES, 8)),
    ];
}

function t53eMapExact(int $build): void
{
    t53eMap($build, 'base1-4:holo:unlimited:-', 604, 42382, 'Holofoil');
    t53eMap($build, 'sv8pt5-5:reverse:-:pokeball', 23821, 610540, 'Holofoil');
}

function t53eGit(string $repo, string ...$args): string
{
    $result = Process::path($repo)->run(['git', '-c', 'user.name=t53e', '-c', 'user.email=t53e@example.invalid', '-c', 'commit.gpgsign=false', ...$args]);
    if (! $result->successful()) {
        throw new RuntimeException('git '.implode(' ', $args).' failed: '.$result->errorOutput());
    }

    return trim($result->output());
}

function t53eWriteGolden(string $repo, array $rows): void
{
    file_put_contents($repo.'/'.T53E_GOLDEN, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

function t53eSign(string $repo): void
{
    file_put_contents($repo.'/'.T53E_APPROVAL, hash_file('sha256', $repo.'/'.T53E_GOLDEN).'  '.T53E_GOLDEN."\n");
}

function t53eBaseline(string $repo, int $mapped, int $datasetCards = 1000): void
{
    file_put_contents($repo.'/'.T53E_BASELINE, json_encode([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => T53E_REF, 'mapped_cards' => $mapped, 'dataset_cards' => $datasetCards, 'ambiguous_cards' => 0,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
}

/** A checkout whose golden file, approval and baseline are all committed and recorded as pushed. */
function t53eSignedRepo(int $baselineMapped = 2): string
{
    $repo = t53eTemp();
    t53eGit($repo, 'init', '-q', '-b', 'main');
    mkdir($repo.'/api/database/data', 0775, true);
    mkdir($repo.'/gates', 0775, true);
    t53eWriteGolden($repo, t53eGolden());
    t53eSign($repo);
    t53eBaseline($repo, $baselineMapped);
    t53eGit($repo, 'add', T53E_GOLDEN, T53E_APPROVAL, T53E_BASELINE);
    t53eGit($repo, 'commit', '-q', '-m', 'signed and pushed');
    t53eGit($repo, 'update-ref', 'refs/remotes/origin/main', 'HEAD');

    return $repo;
}

/** @return array{0: int, 1: array<string, mixed>|null} */
function t53eRun(string $file, array $extra = []): array
{
    $out = t53eTemp().'/DC4.json';
    $code = Artisan::call('pokeprices:map:golden', ['--file' => $file, '--json' => true, '--out' => $out, ...$extra]);

    return [$code, is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null];
}

/** @return list<array<string, mixed>> */
function t53eDetails(array $artefact, string $kind): array
{
    return array_values(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === $kind));
}

test('the baseline floor is inclusive at exactly 99.5 percent and fails one card below it', function (int $mappedCards, int $regression): void {
    $build = t53eBuild();
    t53eMapExact($build);
    for ($i = 1; $i <= $mappedCards - 2; $i++) {
        t53eMap($build, "pad-{$i}:normal:-:-", 9000, 100000 + $i, 'Normal');
    }
    $repo = t53eSignedRepo(200);

    [$code, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($code)->toBe(0)
        ->and(t53eDetails($artefact, 'baseline')[0]['mapped_cards'])->toBe($mappedCards)
        ->and($artefact['mapped_cards_regression'])->toBe($regression)
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['approval_ratified'])->toBe(1);
})->with([
    'exactly 199 of 200' => [199, 0],
    '198 of 200' => [198, 1],
]);

test('a golden file re-hashed into a new approval but committed after the pushed tip is not ratified', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();
    $rows = t53eGolden();
    $rows[0]['why'] = 'quietly reworded after the signature';
    t53eWriteGolden($repo, $rows);
    t53eSign($repo);
    t53eGit($repo, 'add', T53E_GOLDEN, T53E_APPROVAL);
    t53eGit($repo, 'commit', '-q', '-m', 'edit and re-sign, not pushed');

    [, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and(t53eDetails($artefact, 'approval')[0]['reason'])->toBe('approval_unpushed');
});

test('a whitespace-only change to the signed golden file makes the approval stale', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();
    file_put_contents($repo.'/'.T53E_GOLDEN, "\n", FILE_APPEND);

    [$code, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($code)->toBe(0)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and(t53eDetails($artefact, 'approval')[0]['reason'])->toBe('approval_stale');
});

test('an approval holding the right hash with a CRLF line ending is not ratified', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();
    file_put_contents($repo.'/'.T53E_APPROVAL, hash_file('sha256', $repo.'/'.T53E_GOLDEN).'  '.T53E_GOLDEN."\r\n");
    t53eGit($repo, 'add', T53E_APPROVAL);
    t53eGit($repo, 'commit', '-q', '-m', 'crlf approval');
    t53eGit($repo, 'update-ref', 'refs/remotes/origin/main', 'HEAD');

    [, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['value'])->toBe(1);
});

test('a baseline lowered in the working copy after the push cannot hide a regression', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo(5000);
    t53eBaseline($repo, 1);

    [, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($artefact['mapped_cards_regression'])->toBe(1)
        ->and(t53eDetails($artefact, 'baseline')[0]['reason'])->toBe('baseline_unpushed');
});

test('a pushed ref that does not exist ratifies nothing', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();

    [$code, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN, ['--pushed-ref' => 'refs/remotes/origin/nonexistent']);

    expect($code)->toBe(0)
        ->and($artefact['approval_ratified'])->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and($artefact['mapped_cards_regression'])->toBe(1);
});

test('an empty, truncated or non-list golden file errors out instead of reporting value 0', function (string $content): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();
    file_put_contents($repo.'/'.T53E_GOLDEN, $content);

    [$code, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect($code)->toBe(1)
        ->and($artefact)->toBeNull();
})->with([
    'zero bytes' => [''],
    'whitespace only' => ["  \n"],
    'empty list' => ['[]'],
    'json null' => ['null'],
    'truncated mid-row' => ['[{"row": 1, "variant_key": "base1-4:holo:unlim'],
    'empty object' => ['{}'],
]);

test('a missing golden file errors out and a bad build option is refused', function (): void {
    t53eMapExact(t53eBuild());
    $repo = t53eSignedRepo();

    [$missingCode, $missing] = t53eRun($repo.'/api/database/data/absent.json');
    $bad = [];
    foreach (['0', '-1', 'abc', '1.5'] as $build) {
        $bad[$build] = t53eRun($repo.'/'.T53E_GOLDEN, ['--build' => $build]);
    }

    expect($missingCode)->toBe(1)
        ->and($missing)->toBeNull()
        ->and(array_map(fn (array $r): int => $r[0], $bad))->toBe(['0' => 1, '-1' => 1, 'abc' => 1, '1.5' => 1]);
});

test('a forbidden product is a hit on every golden key whatever its subtype, and a hit never hides behind a matching expect', function (): void {
    $build = t53eBuild();
    t53eMap($build, 'base1-4:holo:unlimited:-', 1663, 106999, 'Holofoil');
    t53eMap($build, 'sv8pt5-5:reverse:-:pokeball', 23821, 610360, 'Holofoil');
    $repo = t53eSignedRepo();

    [, $artefact] = t53eRun($repo.'/'.T53E_GOLDEN);

    expect(array_column(t53eDetails($artefact, 'forbidden_hit'), 'product_id'))->toBe([106999, 610360])
        ->and(count(t53eDetails($artefact, 'mismatch')))->toBe(2)
        ->and($artefact['value'])->toBe(4)
        ->and($artefact['approval_ratified'])->toBe(1);
});
