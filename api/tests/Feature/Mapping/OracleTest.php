<?php

declare(strict_types=1);

use App\Console\Commands\MapAudit as MapAuditCommand;
use App\Console\Commands\MapOracle;
use App\Domain\Mapping\MapAudit;
use App\Domain\Mapping\OracleComparison;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The oracle recorder (pokeprices:oracle:fetch) and the per-set comparison (pokeprices:map:oracle), plan T41.
// The recorder is exercised against Http::fake only (R137: the real oracle is already on disk and nobody fetches
// it again). The comparison reads a fixture oracle (tests/Fixtures/oracle/day) and a synthetic collected day that
// each test writes as zstd into a temp directory from tests/Fixtures/oracle/snapshot.json; the mapping rows go
// straight into the test database. Our marketPrice comes from that collected day and never leaves memory.

const T41_DAY = '2026-10-11';

const T41_MARKER = '2026-10-11T20:05:45+0000';

const T41_UA = 'pokeprices/0.1 (+https://andrestheperez.com)';

// Every test in this file: no request may reach a real host, and sleeps are faked (moving Carbon along).
beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
});

function t41Fixture(string $path): string
{
    return base_path('tests/Fixtures/oracle/'.$path);
}

/** A fresh temp directory, removed after the test (t41Temp(true) returns and forgets the remembered ones). */
function t41Temp(bool $drain = false): string|array
{
    static $roots = [];
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }
    $dir = sys_get_temp_dir().'/pp-t41-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    $roots[] = $dir;

    return $dir;
}

afterEach(function (): void {
    foreach (t41Temp(true) as $root) {
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

/** Writes the fixture collected day (SUCCESS, marker, groups, empty products, prices) and returns the root. */
function t41Snapshots(?string $marker = null): string
{
    $spec = json_decode((string) file_get_contents(t41Fixture('snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
    $root = t41Temp();
    $day = $root.'/'.T41_DAY;
    mkdir($day.'/products', 0775, true);
    mkdir($day.'/prices', 0775, true);
    touch($day.'/SUCCESS');
    file_put_contents($day.'/marker.txt', ($marker ?? $spec['marker'])."\n");
    $groups = [];
    foreach ($spec['groups'] as $id => $name) {
        $groups[] = ['groupId' => (int) $id, 'name' => $name];
        file_put_contents("{$day}/products/{$id}.json.zst", zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => []], JSON_THROW_ON_ERROR)));
        $rows = [];
        foreach ($spec['prices'][$id] ?? [] as [$product, $subtype, $market]) {
            $rows[] = ['productId' => $product, 'lowPrice' => null, 'midPrice' => null, 'highPrice' => null, 'marketPrice' => $market, 'directLowPrice' => null, 'subTypeName' => $subtype];
        }
        file_put_contents("{$day}/prices/{$id}.json.zst", zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => $rows], JSON_THROW_ON_ERROR)));
    }
    file_put_contents($day.'/groups.json', json_encode(['totalItems' => count($groups), 'success' => true, 'errors' => [], 'results' => $groups], JSON_THROW_ON_ERROR));

    return $root;
}

function t41Build(string $day = T41_DAY): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day,
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00',
        'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
}

/** One current mapping row for a variant key card:finish:edition:treatment. */
function t41Map(int $build, string $key, int $group, int $product, string $subtype): void
{
    [$card, $finish, $edition, $treatment] = explode(':', $key);
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment]);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'fixture product', 'source_number' => '1', 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

/** Runs pokeprices:map:oracle; returns [exit code, decoded artefact or null, output]. */
function t41Compare(array $extra = [], ?string $snapshots = null): array
{
    $out = t41Temp().'/DC2.artifact.json';
    $code = Artisan::call('pokeprices:map:oracle', [
        '--day' => T41_DAY,
        '--oracle-dir' => t41Fixture('day/'.T41_DAY),
        '--snapshots' => $snapshots ?? t41Snapshots(),
        '--golden' => t41Temp().'/no-golden-set.json',
        '--json' => true,
        '--out' => $out,
        ...$extra,
    ]);
    $output = Artisan::output();

    return [$code, is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null, $output];
}

function t41Set(array $artefact, string $set): ?array
{
    foreach ($artefact['per_set'] as $row) {
        if ($row['set_id'] === $set) {
            return $row;
        }
    }

    return null;
}

/** The blind class counts the artefact lists in details. */
function t41BlindClasses(array $artefact): array
{
    $out = [];
    foreach ($artefact['details'] as $d) {
        if ($d['kind'] === 'blind_class') {
            $out[$d['class']] = $d['variants'];
        }
    }
    ksort($out);

    return $out;
}

/** A snapshots root holding only marker.txt for the recorder's window check. */
function t41MarkerRoot(string $marker = T41_MARKER): string
{
    $root = t41Temp();
    mkdir($root.'/'.T41_DAY);
    file_put_contents($root.'/'.T41_DAY.'/marker.txt', $marker."\n");

    return $root;
}

function t41Fetch(string $oracleRoot, array $extra = []): int
{
    return Artisan::call('pokeprices:oracle:fetch', ['--day' => T41_DAY, '--snapshots' => t41MarkerRoot(), '--oracle-root' => $oracleRoot, ...$extra]);
}

function t41PageUrl(int $n): string
{
    return "https://api.pokemontcg.io/v2/cards?page={$n}&pageSize=250&select=id,set,tcgplayer";
}

// ---- the comparison ------------------------------------------------------------------------------------------

test('basep mapped to group 1840 is a failing set: one price far off and one key the priced oracle card lacks', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1840, 42501, 'Holofoil');
    t41Map($build, 'basep-2:holo:1st:-', 1840, 42502, '1st Edition Holofoil');

    [$code, $artefact] = t41Compare();

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(1)
        ->and(t41Set($artefact, 'basep'))->toBe(['set_id' => 'basep', 'compared' => 2, 'disagree' => 2, 'blind' => 0])
        ->and($artefact['denominator'])->toBe(2)
        ->and($artefact['global_disagreement_pct'])->toEqual(100.0)
        ->and(array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'disagreement'), 'reason', 'variant_key'))
        ->toBe(['basep-1:holo:-:-' => 'delta', 'basep-2:holo:1st:-' => 'key_absent']);
});

test('basep mapped to its own group 1418 has zero disagreements', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1418, 85101, 'Holofoil');
    t41Map($build, 'basep-2:holo:-:-', 1418, 85102, 'Holofoil');

    [$code, $artefact] = t41Compare();

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(0)
        ->and(t41Set($artefact, 'basep'))->toBe(['set_id' => 'basep', 'compared' => 2, 'disagree' => 0, 'blind' => 0])
        ->and($artefact['global_disagreement_pct'])->toEqual(0.0);
});

test('base1 mapped to group 1663 has nothing comparable and is blind in class group_1663', function (): void {
    $build = t41Build();
    t41Map($build, 'base1-4:holo:1st:-', 1663, 106999, '1st Edition Holofoil');
    t41Map($build, 'base1-4:holo:shadowless:-', 1663, 106999, 'Unlimited Holofoil');

    [, $artefact] = t41Compare();

    expect(t41Set($artefact, 'base1'))->toBe(['set_id' => 'base1', 'compared' => 0, 'disagree' => 0, 'blind' => 2])
        ->and($artefact['denominator'])->toBe(0)
        ->and($artefact['value'])->toBe(0)
        ->and(t41BlindClasses($artefact))->toBe(['group_1663' => 2])
        ->and($artefact['blind_classes_uncovered'])->toBe(1);
});

test('Sneasel sv8pt5-61 at 0.06 against 0.08 is not a disagreement under the $0.25 floor', function (): void {
    $build = t41Build();
    t41Map($build, 'sv8pt5-61:normal:-:-', 23821, 610800, 'Normal');
    t41Map($build, 'sv8pt5-61:reverse:-:-', 23821, 610800, 'Reverse Holofoil');

    [, $artefact] = t41Compare();

    expect(t41Set($artefact, 'sv8pt5'))->toBe(['set_id' => 'sv8pt5', 'compared' => 1, 'disagree' => 0, 'blind' => 0])
        ->and($artefact['value'])->toBe(0);
});

test('a stale 2023 oracle block is excluded and counted, never judged', function (): void {
    $build = t41Build();
    t41Map($build, 'ex10-1:holo:-:-', 1398, 90200, 'Holofoil');

    [, $artefact] = t41Compare();

    expect($artefact['excluded_stale'])->toBe(1)
        ->and(t41Set($artefact, 'ex10'))->toBe(['set_id' => 'ex10', 'compared' => 0, 'disagree' => 0, 'blind' => 0])
        ->and($artefact['value'])->toBe(0);
});

test('an oracle block with only a url (no updatedAt, no prices) is blind, not stale', function (): void {
    $build = t41Build();
    t41Map($build, 'me2pt5-2:normal:-:-', 24380, 700002, 'Normal');

    [, $artefact] = t41Compare();

    expect($artefact['excluded_stale'])->toBe(0)
        ->and(t41Set($artefact, 'me2pt5'))->toBe(['set_id' => 'me2pt5', 'compared' => 0, 'disagree' => 0, 'blind' => 1])
        ->and(t41BlindClasses($artefact))->toBe(['me_era' => 1]);
});

test('old print subtypes compare on the oracle keys 1stEdition and unlimited', function (): void {
    $build = t41Build();
    t41Map($build, 'neo1-50:normal:1st:-', 1396, 89612, '1st Edition');
    t41Map($build, 'neo1-50:normal:unlimited:-', 1396, 89612, 'Unlimited');

    [, $artefact] = t41Compare();

    expect(t41Set($artefact, 'neo1'))->toBe(['set_id' => 'neo1', 'compared' => 2, 'disagree' => 0, 'blind' => 0]);
});

test('variants without an oracle key are blind in their class, and the golden file on disk covers classes', function (): void {
    $build = t41Build();
    t41Map($build, 'base1-4:holo:1st:-', 1663, 106999, '1st Edition Holofoil');
    t41Map($build, 'tk1a-1:holo:-:-', 1543, 1001, 'Holofoil');
    t41Map($build, 'basep-3:holo:-:-', 1418, 85103, 'Holofoil');
    t41Map($build, 'sv8pt5-61:reverse:-:pokeball', 23821, 610801, 'Holofoil');
    t41Map($build, 'me2pt5-1:normal:-:-', 24380, 700001, 'Normal');
    t41Map($build, 'svp-99:holo:-:-', 22872, 520099, 'Holofoil');
    $snapshots = t41Snapshots();

    [, $absent] = t41Compare([], $snapshots);

    $golden = t41Temp().'/golden-set.json';
    file_put_contents($golden, json_encode([
        ['row' => 1, 'variant_key' => 'base1-4:holo:1st:-', 'classes' => ['group_1663', 'base_charizard']],
        ['row' => 2, 'variant_key' => 'tk1a-1:holo:-:-', 'classes' => ['trainer_kit']],
    ], JSON_THROW_ON_ERROR));
    [, $covered] = t41Compare(['--golden' => $golden], $snapshots);

    expect(t41BlindClasses($absent))->toBe(['basep' => 1, 'group_1663' => 1, 'me_era' => 1, 'other' => 1, 'pattern' => 1, 'trainer_kit' => 1])
        ->and($absent['blind_classes_uncovered'])->toBe(6)
        ->and($covered['blind_classes_uncovered'])->toBe(4)
        ->and($absent['denominator'])->toBe(0);
});

test('the DC2 artefact has exactly the contract keys and its inputs name the day, the build and the fetch times', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1418, 85101, 'Holofoil');

    [$code, $artefact, $output] = t41Compare();

    expect($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs', 'global_disagreement_pct', 'fetch_window_violation', 'blind_classes_uncovered', 'per_set', 'excluded_stale'])
        ->and($artefact['id'])->toBe('DC2')
        ->and($artefact['fetch_window_violation'])->toBe(0)
        ->and($artefact['inputs'])->toBe([
            'snapshot_day' => T41_DAY,
            'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
            'marker' => '2026-10-11T20:05:45+00:00',
            'map_build_id' => $build,
            'oracle_fetched_from' => '2026-10-12T14:47:39Z',
            'oracle_fetched_to' => '2026-10-12T14:47:39Z',
        ])
        ->and(json_decode($output, true))->toBe($artefact);
});

test('a late or out-of-window oracle sets fetch_window_violation', function (array $change): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1418, 85101, 'Holofoil');
    $dir = t41Temp();
    copy(t41Fixture('day/'.T41_DAY.'/cards-001.json'), $dir.'/cards-001.json');
    $fetch = json_decode((string) file_get_contents(t41Fixture('day/'.T41_DAY.'/fetch.json')), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($dir.'/fetch.json', json_encode(array_replace_recursive($fetch, $change), JSON_THROW_ON_ERROR));

    [$code, $artefact] = t41Compare(['--oracle-dir' => $dir]);

    expect($code)->toBe(0)->and($artefact['fetch_window_violation'])->toBe(1);
})->with([
    'late flag' => [['late' => true]],
    'page fetched a day after the marker' => [['pages' => [['fetched_at' => '2026-10-12T20:05:45Z']]]],
    'page fetched before the marker' => [['pages' => [['fetched_at' => '2026-10-11T20:05:44Z']]]],
]);

test('the comparison only reads: the oracle files, the collected day and the map tables are unchanged, and no price leaves memory', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1840, 42501, 'Holofoil');
    t41Map($build, 'basep-2:holo:1st:-', 1840, 42502, '1st Edition Holofoil');
    t41Map($build, 'ex10-1:holo:-:-', 1398, 90200, 'Holofoil');
    $snapshots = t41Snapshots();
    $hash = function (string $dir): array {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $out[substr((string) $file, strlen($dir))] = [hash_file('sha256', (string) $file), $file->getMTime()];
        }
        ksort($out);

        return $out;
    };
    $tables = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), ['map_builds', 'card_variants', 'source_product_map', 'source_product_map_history', 'unmapped_source_items', 'set_aliases']);
    [$oracleBefore, $snapBefore, $rowsBefore] = [$hash(t41Fixture('day')), $hash($snapshots), $tables()];

    [$code, , $output] = t41Compare([], $snapshots);

    expect($code)->toBe(0)
        ->and($hash(t41Fixture('day')))->toBe($oracleBefore)
        ->and($hash($snapshots))->toBe($snapBefore)
        ->and($tables())->toBe($rowsBefore);
    foreach (['31.07', '55.55', '12.34', '107.49', '84.97', '135.45'] as $price) {
        expect($output)->not->toContain($price);
    }
});

test('the comparison refuses a day other than the build\'s, a missing oracle and an incomplete oracle', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1418, 85101, 'Holofoil');
    $incomplete = t41Temp();
    copy(t41Fixture('day/'.T41_DAY.'/cards-001.json'), $incomplete.'/cards-001.json');
    $fetch = json_decode((string) file_get_contents(t41Fixture('day/'.T41_DAY.'/fetch.json')), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($incomplete.'/fetch.json', json_encode(['pages_expected' => 2] + $fetch, JSON_THROW_ON_ERROR));

    [$wrongDay, $a] = t41Compare(['--day' => '2026-10-10']);
    [$missing, $b] = t41Compare(['--oracle-dir' => t41Temp().'/absent']);
    [$short, $c] = t41Compare(['--oracle-dir' => $incomplete]);

    expect([$wrongDay, $missing, $short])->toBe([1, 1, 1])
        ->and([$a, $b, $c])->toBe([null, null, null]);
});

test('without --oracle-dir the comparison reads services.oracle.root/<day>', function (): void {
    $build = t41Build();
    t41Map($build, 'basep-1:holo:-:-', 1418, 85101, 'Holofoil');
    $root = t41Temp();
    mkdir($root.'/'.T41_DAY);
    foreach (['cards-001.json', 'fetch.json'] as $file) {
        copy(t41Fixture('day/'.T41_DAY.'/'.$file), $root.'/'.T41_DAY.'/'.$file);
    }
    config(['services.oracle.root' => $root]);

    $code = Artisan::call('pokeprices:map:oracle', ['--snapshots' => t41Snapshots(), '--golden' => t41Temp().'/none.json', '--json' => true]);

    $output = Artisan::output();

    expect($code)->toBe(0, $output)
        ->and(json_decode($output, true)['denominator'])->toBe(1);
});

arch('the comparison and the audit never touch the network')
    ->expect([OracleComparison::class, MapAudit::class, MapOracle::class, MapAuditCommand::class])
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp']);

// ---- the recorder (Http::fake only; R137) --------------------------------------------------------------------

test('the recorder fetches every page keyless with its user agent, 2 s apart, and writes the pages verbatim', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    $pages = [1 => (string) file_get_contents(t41Fixture('recorder/page-1.json')), 2 => (string) file_get_contents(t41Fixture('recorder/page-2.json'))];
    Http::fake(function (Request $request) use ($pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return Http::response($pages[(int) $q['page']], 200, ['Content-Type' => 'application/json; charset=utf-8']);
    });

    $code = t41Fetch($root);

    $dir = $root.'/'.T41_DAY;
    $fetch = json_decode((string) file_get_contents($dir.'/fetch.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)
        ->and(file_get_contents($dir.'/cards-001.json'))->toBe($pages[1])
        ->and(file_get_contents($dir.'/cards-002.json'))->toBe($pages[2])
        ->and(array_keys($fetch))->toBe(['day', 'marker', 'started_at', 'finished_at', 'pages_expected', 'late', 'pages'])
        ->and($fetch['day'])->toBe(T41_DAY)
        ->and($fetch['marker'])->toBe(T41_MARKER)
        ->and($fetch['pages_expected'])->toBe(2)
        ->and($fetch['late'])->toBeFalse()
        ->and(array_map(fn (array $p): array => array_keys($p), $fetch['pages']))->each->toBe(['n', 'status', 'attempts', 'fetched_at'])
        ->and(array_column($fetch['pages'], 'n'))->toBe([1, 2])
        ->and(array_column($fetch['pages'], 'attempts'))->toBe([1, 1])
        ->and(CarbonImmutable::parse($fetch['pages'][1]['fetched_at'])->diffInSeconds(CarbonImmutable::parse($fetch['pages'][0]['fetched_at']), true))
        ->toBeGreaterThanOrEqual(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
    Http::assertSentCount(2);
    foreach ([1, 2] as $n) {
        Http::assertSent(fn (Request $r): bool => $r->url() === t41PageUrl($n) && $r->method() === 'GET'
            && $r->header('User-Agent') === [T41_UA] && ! $r->hasHeader('X-Api-Key'));
    }
});

test('the recorder retries a page on 5xx and on an HTML body, 2 s apart, and records the attempts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    $json = str_replace('"totalCount": 300', '"totalCount": 1', (string) file_get_contents(t41Fixture('recorder/page-1.json')));
    Http::fake([
        'api.pokemontcg.io/*' => Http::sequence()
            ->push('upstream error', 503)
            ->push((string) file_get_contents(t41Fixture('recorder/maintenance.html')), 200, ['Content-Type' => 'text/html'])
            ->push($json, 200, ['Content-Type' => 'application/json']),
    ]);

    $code = t41Fetch($root);

    $fetch = json_decode((string) file_get_contents($root.'/'.T41_DAY.'/fetch.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)
        ->and($fetch['pages'])->toHaveCount(1)
        ->and($fetch['pages'][0]['attempts'])->toBe(3)
        ->and($fetch['pages'][0]['status'])->toBe(200)
        ->and(file_get_contents($root.'/'.T41_DAY.'/cards-001.json'))->toBe($json);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
});

test('the recorder gives up on a page after 5 retries and writes no page file for it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    Http::fake(['api.pokemontcg.io/*' => Http::response('bad gateway', 502)]);

    $code = t41Fetch($root);

    $fetch = json_decode((string) file_get_contents($root.'/'.T41_DAY.'/fetch.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(1)
        ->and(is_file($root.'/'.T41_DAY.'/cards-001.json'))->toBeFalse()
        ->and($fetch['pages'][0]['status'])->toBe(502)
        ->and($fetch['pages'][0]['attempts'])->toBe(6);
    Http::assertSentCount(6);
});

test('the recorder does not retry a 4xx answer', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    Http::fake(['api.pokemontcg.io/*' => Http::response('{"error":"not found"}', 404, ['Content-Type' => 'application/json'])]);

    expect(t41Fetch($root))->toBe(1);
    Http::assertSentCount(1);
});

test('the recorder refuses outside [marker, marker+24h) and sends nothing', function (string $now, array $extra): void {
    $this->travelTo(CarbonImmutable::parse($now));
    $root = t41Temp();
    Http::fake();

    $code = t41Fetch($root, $extra);

    expect($code)->toBe(1)
        ->and(glob($root.'/*/*') ?: [])->toBe([]);
    Http::assertNothingSent();
})->with([
    'exactly 24 h after the marker' => ['2026-10-12T20:05:45Z', []],
    'two days late' => ['2026-10-13T21:00:00Z', []],
    'before the marker, even with --allow-late' => ['2026-10-11T20:05:44Z', ['--allow-late' => true]],
]);

test('--allow-late records late: true and fetches', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13T21:00:00Z'));
    $root = t41Temp();
    $json = str_replace('"totalCount": 300', '"totalCount": 1', (string) file_get_contents(t41Fixture('recorder/page-1.json')));
    Http::fake(['api.pokemontcg.io/*' => Http::response($json, 200, ['Content-Type' => 'application/json'])]);

    $code = t41Fetch($root, ['--allow-late' => true]);

    $fetch = json_decode((string) file_get_contents($root.'/'.T41_DAY.'/fetch.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(0)->and($fetch['late'])->toBeTrue();
});

test('without --oracle-root the recorder writes under services.oracle.root', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    config(['services.oracle.root' => $root]);
    $json = str_replace('"totalCount": 300', '"totalCount": 1', (string) file_get_contents(t41Fixture('recorder/page-1.json')));
    Http::fake(['api.pokemontcg.io/*' => Http::response($json, 200, ['Content-Type' => 'application/json'])]);

    $code = Artisan::call('pokeprices:oracle:fetch', ['--day' => T41_DAY, '--snapshots' => t41MarkerRoot()]);

    expect($code)->toBe(0)
        ->and(file_get_contents($root.'/'.T41_DAY.'/cards-001.json'))->toBe($json);
});

test('the recorder refuses to run inside a gate run', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    Http::fake();
    putenv('GATES_OUT=.gates/tmp/T41');

    try {
        $code = t41Fetch($root);
    } finally {
        putenv('GATES_OUT');
    }

    expect($code)->toBe(1)->and(glob($root.'/*') ?: [])->toBe([]);
    Http::assertNothingSent();
});

test('the recorder never overwrites a recorded day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T14:00:00Z'));
    $root = t41Temp();
    mkdir($root.'/'.T41_DAY);
    file_put_contents($root.'/'.T41_DAY.'/fetch.json', '{"recorded":"before"}');
    file_put_contents($root.'/'.T41_DAY.'/cards-001.json', '{"data":[]}');
    Http::fake();

    $code = t41Fetch($root);

    expect($code)->toBe(1)
        ->and(file_get_contents($root.'/'.T41_DAY.'/fetch.json'))->toBe('{"recorded":"before"}')
        ->and(file_get_contents($root.'/'.T41_DAY.'/cards-001.json'))->toBe('{"data":[]}');
    Http::assertNothingSent();
});
