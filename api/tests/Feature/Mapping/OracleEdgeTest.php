<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Reviewer edge cases for T41: the exact disagreement limits, the stale boundary, the key mapping, and that the
// comparison leaks no price. Each test writes its own oracle page and its own collected day (zstd) to a temp dir.

const T41E_DAY = '2026-10-11';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

afterEach(function (): void {
    foreach (t41eRoots(null, true) as $root) {
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

/** Remembers a root, or (drain) returns and forgets the remembered ones. */
function t41eRoots(?string $add, bool $drain = false): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;
    }
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }

    return $roots;
}

function t41eTemp(): string
{
    $dir = sys_get_temp_dir().'/pp-t41e-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    t41eRoots($dir);

    return $dir;
}

/**
 * Runs the comparison over a one-page oracle and a collected day, both written here.
 *
 * @param  array<string, array{0: string, 1: array<string, float|null>}>  $oracleCards  card id => [updatedAt, prices by oracle key (market)]
 * @param  array<int, list<array{0: int, 1: string, 2: float|null}>>  $prices  group => [[product, subtype, market]]
 * @return array{0: int, 1: array<string, mixed>|null, 2: string}
 */
function t41eCompare(array $oracleCards, array $prices): array
{
    $oracle = t41eTemp();
    $data = [];
    foreach ($oracleCards as $id => [$updated, $keys]) {
        $block = ['url' => "https://example.invalid/{$id}"];
        if ($updated !== null) {
            $block['updatedAt'] = $updated;
            $block['prices'] = [];
            foreach ($keys as $key => $market) {
                $block['prices'][$key] = ['low' => 0.01, 'mid' => 0.5, 'high' => 9.0, 'market' => $market, 'directLow' => null];
            }
        }
        $data[] = ['id' => $id, 'set' => ['id' => explode('-', $id)[0]], 'tcgplayer' => $block];
    }
    file_put_contents($oracle.'/cards-001.json', json_encode(['data' => $data, 'page' => 1, 'pageSize' => 250, 'count' => count($data), 'totalCount' => count($data)], JSON_THROW_ON_ERROR));
    file_put_contents($oracle.'/fetch.json', json_encode([
        'day' => T41E_DAY, 'marker' => '2026-10-11T20:05:45+0000', 'started_at' => '2026-10-12T14:00:00Z', 'finished_at' => '2026-10-12T14:00:05Z',
        'pages_expected' => 1, 'late' => false, 'pages' => [['n' => 1, 'status' => 200, 'attempts' => 1, 'fetched_at' => '2026-10-12T14:00:01Z']],
    ], JSON_THROW_ON_ERROR));

    $snap = t41eTemp();
    $day = $snap.'/'.T41E_DAY;
    mkdir($day.'/products', 0775, true);
    mkdir($day.'/prices', 0775, true);
    touch($day.'/SUCCESS');
    file_put_contents($day.'/marker.txt', "2026-10-11T20:05:45+0000\n");
    $groups = [];
    foreach ($prices as $group => $rows) {
        $groups[] = ['groupId' => $group, 'name' => "Group {$group}"];
        file_put_contents("{$day}/products/{$group}.json.zst", zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => []], JSON_THROW_ON_ERROR)));
        $results = [];
        foreach ($rows as [$product, $subtype, $market]) {
            $results[] = ['productId' => $product, 'lowPrice' => null, 'midPrice' => null, 'highPrice' => null, 'marketPrice' => $market, 'directLowPrice' => null, 'subTypeName' => $subtype];
        }
        file_put_contents("{$day}/prices/{$group}.json.zst", zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => $results], JSON_THROW_ON_ERROR)));
    }
    file_put_contents($day.'/groups.json', json_encode(['totalItems' => count($groups), 'success' => true, 'errors' => [], 'results' => $groups], JSON_THROW_ON_ERROR));

    $out = t41eTemp().'/DC2.json';
    $code = Artisan::call('pokeprices:map:oracle', [
        '--day' => T41E_DAY, '--oracle-dir' => $oracle, '--snapshots' => $snap, '--golden' => $oracle.'/none.json', '--json' => true, '--out' => $out,
    ]);

    return [$code, is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null, Artisan::output().(is_file($out) ? (string) file_get_contents($out) : '')];
}

function t41eBuild(): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => T41E_DAY, 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
}

function t41eMap(int $build, string $key, int $group, int $product, string $subtype): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment]);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'edge product', 'source_number' => '1', 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

function t41eDisagreed(array $artefact): array
{
    $keys = array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'disagreement'), 'variant_key');
    sort($keys);

    return $keys;
}

function t41eSummary(array $artefact): array
{
    return array_values(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'summary'))[0];
}

test('a disagreement needs |delta| above 20% of the oracle AND above 0.25 dollars, exactly at either limit is agreement', function (): void {
    $build = t41eBuild();
    // card => [oracle market, our market]
    $cases = [
        'e1-1' => [0.50, 0.75],   // delta exactly 0.25, 50%: agree
        'e1-2' => [0.50, 0.7501], // 0.2501: disagree
        'e1-3' => [10.0, 12.0],   // exactly 20%, 2.00: agree
        'e1-4' => [10.0, 12.01],  // 20.1%: disagree
        'e1-5' => [10.0, 8.0],    // exactly -20%: agree
        'e1-6' => [10.0, 7.99],   // -20.1%: disagree
        'e1-7' => [0.0, 0.25],    // oracle zero, delta exactly the floor: agree
        'e1-8' => [0.0, 0.26],    // oracle zero, delta over the floor: disagree, no division by zero
        'e1-9' => [0.0, 0.0],     // both zero: agree
    ];
    $oracle = [];
    $rows = [];
    $product = 100;
    foreach ($cases as $card => [$theirs, $mine]) {
        $oracle[$card] = ['2026/10/11', ['holofoil' => $theirs]];
        $rows[] = [$product, 'Holofoil', $mine];
        t41eMap($build, "{$card}:holo:-:-", 9001, $product, 'Holofoil');
        $product++;
    }

    [$code, $artefact] = t41eCompare($oracle, [9001 => $rows]);

    expect($code)->toBe(0)
        ->and($artefact['denominator'])->toBe(9)
        ->and(t41eDisagreed($artefact))->toBe(['e1-2:holo:-:-', 'e1-4:holo:-:-', 'e1-6:holo:-:-', 'e1-8:holo:-:-'])
        ->and($artefact['global_disagreement_pct'])->toEqual(round(4 / 9 * 100, 4));
});

test('a null market on either side is not compared and not a disagreement', function (): void {
    $build = t41eBuild();
    t41eMap($build, 'e2-1:holo:-:-', 9002, 200, 'Holofoil');
    t41eMap($build, 'e2-2:holo:-:-', 9002, 201, 'Holofoil');
    t41eMap($build, 'e2-3:holo:-:-', 9002, 202, 'Holofoil');

    [, $artefact] = t41eCompare(
        ['e2-1' => ['2026/10/11', ['holofoil' => null]], 'e2-2' => ['2026/10/11', ['holofoil' => 5.0]], 'e2-3' => ['2026/10/11', ['holofoil' => 5.0]]],
        [9002 => [[200, 'Holofoil', 99.0], [201, 'Holofoil', null], [202, 'Holofoil', 5.0]]],
    );

    expect($artefact['denominator'])->toBe(1)
        ->and($artefact['value'])->toBe(0)
        ->and(t41eSummary($artefact)['no_market'])->toBe(2);
});

test('an oracle block dated exactly two days before the marker day is judged, one day older is excluded, a malformed date is excluded', function (): void {
    $build = t41eBuild();
    $dates = ['e3-1' => '2026/10/09', 'e3-2' => '2026/10/08', 'e3-3' => '2026-10-09', 'e3-4' => '2026/10/12'];
    $oracle = [];
    $rows = [];
    $product = 300;
    foreach ($dates as $card => $date) {
        $oracle[$card] = [$date, ['holofoil' => 10.0]];
        $rows[] = [$product, 'Holofoil', 100.0];
        t41eMap($build, "{$card}:holo:-:-", 9003, $product, 'Holofoil');
        $product++;
    }

    [$code, $artefact] = t41eCompare($oracle, [9003 => $rows]);

    expect($code)->toBe(0)
        ->and($artefact['excluded_stale'])->toBe(2)
        ->and($artefact['denominator'])->toBe(2)
        ->and(t41eDisagreed($artefact))->toBe(['e3-1:holo:-:-', 'e3-4:holo:-:-']);
});

test('1stEdition and unlimited price keys map to their own editions and cannot be compared against each other', function (): void {
    $build = t41eBuild();
    // 1st normal 100, unlimited normal 5: a swapped or shared key would disagree.
    t41eMap($build, 'e4-1:normal:1st:-', 9004, 400, '1st Edition');
    t41eMap($build, 'e4-1:normal:unlimited:-', 9004, 400, 'Unlimited');
    // 1st holo with only an unlimitedHolofoil priced: our key is absent.
    t41eMap($build, 'e4-2:holo:1st:-', 9004, 401, '1st Edition Holofoil');
    // unlimited holo against unlimitedHolofoil.
    t41eMap($build, 'e4-3:holo:unlimited:-', 9004, 402, 'Unlimited Holofoil');

    [, $artefact] = t41eCompare(
        [
            'e4-1' => ['2026/10/11', ['1stEdition' => 100.0, 'unlimited' => 5.0]],
            'e4-2' => ['2026/10/11', ['unlimitedHolofoil' => 40.0]],
            'e4-3' => ['2026/10/11', ['unlimitedHolofoil' => 40.0, '1stEditionHolofoil' => 900.0]],
        ],
        [9004 => [[400, '1st Edition', 100.0], [400, 'Unlimited', 5.0], [401, '1st Edition Holofoil', 40.0], [402, 'Unlimited Holofoil', 40.0]]],
    );

    $reasons = array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'disagreement'), 'reason', 'variant_key');
    expect($reasons)->toBe(['e4-2:holo:1st:-' => 'key_absent'])
        ->and($artefact['denominator'])->toBe(4);
});

test('a mapped variant whose card the oracle lacks, or whose treatment has no oracle key, is blind and never a disagreement', function (): void {
    $build = t41eBuild();
    t41eMap($build, 'e5-1:holo:-:-', 9005, 500, 'Holofoil');
    t41eMap($build, 'e5-2:holo:-:pokeball', 9005, 501, 'Holofoil');

    [, $artefact] = t41eCompare(['e5-2' => ['2026/10/11', ['holofoil' => 1.0]]], [9005 => [[500, 'Holofoil', 50.0], [501, 'Holofoil', 50.0]]]);

    expect($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(0)
        ->and($artefact['global_disagreement_pct'])->toEqual(0.0)
        ->and(t41eSummary($artefact)['blind'])->toBe(2)
        ->and(t41eSummary($artefact)['no_oracle_card'])->toBe(1);
});

test('no per-variant price of either side reaches the artefact, the printed output or the database', function (): void {
    $build = t41eBuild();
    t41eMap($build, 'e6-1:holo:-:-', 9006, 600, 'Holofoil');
    $before = DB::table('source_product_map')->count() + DB::table('card_variants')->count() + DB::table('map_builds')->count();

    [, $artefact, $everything] = t41eCompare(['e6-1' => ['2026/10/11', ['holofoil' => 4321.98]]], [9006 => [[600, 'Holofoil', 8765.43]]]);

    expect($artefact['value'])->toBe(1)
        ->and($everything)->not->toContain('4321.98')
        ->and($everything)->not->toContain('8765.43')
        ->and($everything)->not->toContain('4321')
        ->and($everything)->not->toContain('8765')
        ->and(DB::table('source_product_map')->count() + DB::table('card_variants')->count() + DB::table('map_builds')->count())->toBe($before);
});

test('an updatedAt that is not a real calendar date counts as undated and is excluded, never normalised into a fresh date', function (): void {
    $build = t41eBuild();
    $oracle = [];
    $rows = [];
    $product = 700;
    foreach (['e7-1' => '2026/13/45', 'e7-2' => '2026/10/32'] as $card => $date) {
        $oracle[$card] = [$date, ['holofoil' => 10.0]];
        $rows[] = [$product, 'Holofoil', 10.0];
        t41eMap($build, "{$card}:holo:-:-", 9007, $product, 'Holofoil');
        $product++;
    }

    [$code, $artefact] = t41eCompare($oracle, [9007 => $rows]);

    expect($code)->toBe(0)
        ->and($artefact['excluded_stale'])->toBe(2)
        ->and($artefact['denominator'])->toBe(0);
});
