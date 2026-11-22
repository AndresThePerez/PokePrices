<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Mapping\MapAudit;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// pokeprices:map:audit --check priced: of the catalogue's cards, the share that has at least one current mapping row,
// overall and per era (the set's series), each against a floor, plus the build's unmapped card products ordered by
// the collected day's market price. A card whose only product was rejected is reviewed, so coverage accounts for it,
// but it has no price: priced does not count it. The share comes from the map rows, never from the price tables.

const QF_REF = '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11';

/** A fresh temp directory, removed after the test (qfTemp(true) returns and forgets the remembered ones). */
function qfTemp(bool $drain = false): string|array
{
    static $roots = [];
    if ($drain) {
        [$out, $roots] = [$roots, []];

        return $out;
    }
    $dir = sys_get_temp_dir().'/pp-qf-priced-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    $roots[] = $dir;

    return $dir;
}

afterEach(function (): void {
    foreach (qfTemp(true) as $root) {
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

/**
 * Writes a dataset to a temp directory. $sets is set id => [series, list of [card id, name, number]].
 *
 * @param  array<string, array{string, list<array{string, string, string}>}>  $sets
 */
function qfDataset(array $sets): string
{
    $dir = qfTemp();
    mkdir($dir.'/sets', 0775, true);
    mkdir($dir.'/cards/en', 0775, true);
    $list = [];
    foreach ($sets as $set => [$series, $cards]) {
        $list[] = ['id' => $set, 'name' => "Set {$set}", 'series' => $series];
        file_put_contents("{$dir}/cards/en/{$set}.json", json_encode(array_map(fn (array $c): array => ['id' => $c[0], 'name' => $c[1], 'number' => $c[2]], $cards), JSON_THROW_ON_ERROR));
    }
    file_put_contents($dir.'/sets/en.json', json_encode($list, JSON_THROW_ON_ERROR));

    return $dir;
}

/** $n cards of one set: [set-1, Card 1, 1] ... */
function qfCards(string $set, int $n): array
{
    return array_map(fn (int $i): array => ["{$set}-{$i}", "Card {$i}", (string) $i], range(1, $n));
}

function qfBuild(string $day = '2026-10-11'): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day, 'dataset_ref' => QF_REF, 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
}

function qfMap(int $build, string $card, int $product, string $subtype = 'Holofoil'): void
{
    $key = "{$card}:holo:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo', 'edition' => '-', 'treatment' => '-']);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => 501, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'Card', 'source_number' => '1', 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

function qfUnmapped(int $build, int $group, int $product, string $name, ?string $number, string $reason, array $candidates, string $subtype = 'Holofoil'): void
{
    DB::table('unmapped_source_items')->insert([
        'map_build_id' => $build, 'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product,
        'source_subtype' => $subtype, 'product_name' => $name, 'number' => $number, 'reason' => $reason,
        'candidates' => json_encode($candidates, JSON_THROW_ON_ERROR),
    ]);
}

/**
 * Writes a collected day holding only price files: group => list of [productId, subTypeName, marketPrice|null].
 *
 * @param  array<int, list<array{int, string, float|null}>>  $prices
 */
function qfSnapshots(array $prices, string $day = '2026-10-11'): string
{
    $root = qfTemp();
    $dir = "{$root}/{$day}";
    mkdir($dir.'/products', 0775, true);
    mkdir($dir.'/prices', 0775, true);
    file_put_contents($dir.'/SUCCESS', '');
    file_put_contents($dir.'/marker.txt', "{$day}T20:05:45+0000\n");
    $groups = [];
    foreach ($prices as $group => $rows) {
        $groups[] = ['groupId' => $group, 'name' => "Group {$group}"];
        $results = array_map(fn (array $r): array => ['productId' => $r[0], 'lowPrice' => null, 'midPrice' => null, 'highPrice' => null,
            'marketPrice' => $r[2], 'directLowPrice' => null, 'subTypeName' => $r[1]], $rows);
        file_put_contents("{$dir}/prices/{$group}.json.zst", (string) zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => $results], JSON_THROW_ON_ERROR)));
        file_put_contents("{$dir}/products/{$group}.json.zst", (string) zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => []], JSON_THROW_ON_ERROR)));
    }
    file_put_contents($dir.'/groups.json', json_encode(['success' => true, 'errors' => [], 'results' => $groups], JSON_THROW_ON_ERROR));

    return $root;
}

/** Runs the priced check with --json and --out; returns [exit code, decoded artefact or null, stdout]. */
function qfAudit(string $dataset, string $snapshots, array $extra = []): array
{
    $out = qfTemp().'/artefact.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'priced', '--json' => true, '--out' => $out, '--dataset' => $dataset, '--snapshots' => $snapshots, ...$extra]);
    $stdout = Artisan::output();
    $artefact = is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null;

    return [$code, $artefact, $stdout];
}

/** @return list<array<string, mixed>> the details of one kind */
function qfDetails(array $artefact, string $kind): array
{
    return array_values(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === $kind));
}

/** @return array<string, mixed>|null the share row for an era (series) or for 'overall' */
function qfShare(array $artefact, ?string $series): ?array
{
    foreach (qfDetails($artefact, 'priced_share') as $row) {
        if ($row['series'] === $series) {
            return $row;
        }
    }

    return null;
}

/** The smallest card count at which $floorBp basis points is a whole number of cards. */
function qfStep(int $floorBp): int
{
    $gcd = function (int $a, int $b) use (&$gcd): int {
        return $b === 0 ? $a : $gcd($b, $a % $b);
    };

    return intdiv(10000, $gcd($floorBp, 10000));
}

test('the floors are named constants: an overall floor and a floor per era, each a whole or half percent', function (): void {
    expect(MapAudit::PRICED_FLOOR_BP)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(10000)
        ->and(MapAudit::DEFAULT_ERA_FLOOR_BP)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(MapAudit::PRICED_FLOOR_BP)
        ->and(MapAudit::ERA_FLOORS_BP)->not->toBeEmpty()
        ->and(MapAudit::ERA_FLOORS_BP)->toHaveKeys(['Sun & Moon', 'XY', 'Black & White', 'Sword & Shield', 'HeartGold & SoulSilver'])
        ->and(MapAudit::UNMAPPED_LIST)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
    foreach ([MapAudit::PRICED_FLOOR_BP, MapAudit::DEFAULT_ERA_FLOOR_BP, ...array_values(MapAudit::ERA_FLOORS_BP)] as $floor) {
        expect($floor % 50)->toBe(0);
    }
});

test('a card whose only product was rejected is reviewed for coverage but is not priced', function (): void {
    $build = qfBuild();
    DB::table('set_aliases')->insert(['pokesearch_set_id' => 's1', 'source' => 'tcgplayer', 'source_group_id' => 501, 'role' => 'primary',
        'number_rule' => null, 'method' => 'manual', 'unlimited_means' => null, 'note' => 'test row']);
    $dataset = qfDataset(['s1' => ['Sun & Moon', [['s1-1', 'Pikachu', '1'], ['s1-2', 'Charizard-GX', '2']]]]);
    qfMap($build, 's1-1', 7001);
    qfUnmapped($build, 501, 7002, 'Charizard GX', '2', 'name_disagreement', ['s1-2']);
    $snapshots = qfSnapshots([501 => [[7001, 'Holofoil', 1.0], [7002, 'Holofoil', 50.0]]]);

    $coverage = Artisan::call('pokeprices:map:audit', ['--check' => 'coverage', '--json' => true, '--dataset' => $dataset]);
    $coverageArtefact = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    [$code, $artefact, $stdout] = qfAudit($dataset, $snapshots);

    expect($coverage)->toBe(0)
        ->and($coverageArtefact['value'])->toBe(0)
        ->and($code)->toBe(0)
        ->and(array_keys($artefact))->toBe(['id', 'value', 'denominator', 'details', 'inputs', 'priced_pct', 'floor_pct'])
        ->and($artefact['id'])->toBe('DC7')
        ->and($artefact['priced_pct'])->toBe(50.0)
        ->and($artefact['floor_pct'])->toBe(MapAudit::PRICED_FLOOR_BP / 100.0)
        ->and($artefact['inputs'])->toBe(['snapshot_day' => '2026-10-11', 'dataset_ref' => QF_REF, 'marker' => '2026-10-11T20:05:45+00:00', 'map_build_id' => $build])
        ->and(qfShare($artefact, null))->toMatchArray(['scope' => 'overall', 'cards' => 2, 'priced' => 1, 'share_pct' => 50.0, 'met' => false])
        ->and(qfShare($artefact, 'Sun & Moon'))->toMatchArray(['scope' => 'era', 'cards' => 2, 'priced' => 1, 'share_pct' => 50.0,
            'floor_pct' => MapAudit::ERA_FLOORS_BP['Sun & Moon'] / 100.0, 'met' => false])
        ->and($artefact['value'])->toBe(2)
        ->and($artefact['denominator'])->toBe(2)
        ->and(json_decode($stdout, true))->toBe($artefact);
});

test('the share is reported per era from the set series, each era against its own floor', function (): void {
    $build = qfBuild();
    $dataset = qfDataset([
        'sm1' => ['Sun & Moon', qfCards('sm1', 2)],
        'xy1' => ['XY', qfCards('xy1', 2)],
        'base1' => ['Base', qfCards('base1', 1)],
    ]);
    qfMap($build, 'sm1-1', 1);
    qfMap($build, 'sm1-2', 2);
    qfMap($build, 'xy1-1', 3);
    qfMap($build, 'base1-1', 4);

    [, $artefact] = qfAudit($dataset, qfSnapshots([]));

    $eras = array_column(array_filter(qfDetails($artefact, 'priced_share'), fn (array $r): bool => $r['scope'] === 'era'), null, 'series');
    expect(array_keys($eras))->toBe(['Base', 'Sun & Moon', 'XY'])
        ->and($eras['Sun & Moon'])->toMatchArray(['cards' => 2, 'priced' => 2, 'share_pct' => 100.0, 'met' => true])
        ->and($eras['XY'])->toMatchArray(['cards' => 2, 'priced' => 1, 'share_pct' => 50.0, 'met' => false])
        ->and($eras['Base'])->toMatchArray(['cards' => 1, 'priced' => 1, 'share_pct' => 100.0])
        ->and($eras['Base']['floor_pct'])->toBe((MapAudit::ERA_FLOORS_BP['Base'] ?? MapAudit::DEFAULT_ERA_FLOOR_BP) / 100.0)
        ->and(qfShare($artefact, null))->toMatchArray(['cards' => 5, 'priced' => 4, 'share_pct' => 80.0])
        ->and($artefact['denominator'])->toBe(4);
});

test('the overall check passes at its floor and fails one card under it', function (int $under): void {
    // One era with no floor of its own takes the default era floor, which is at or below the overall floor, so only
    // the overall floor is at stake when the share sits exactly on it.
    $n = qfStep(MapAudit::PRICED_FLOOR_BP);
    $atFloor = intdiv(MapAudit::PRICED_FLOOR_BP * $n, 10000);
    expect(MapAudit::ERA_FLOORS_BP)->not->toHaveKey('Test Era');
    $build = qfBuild();
    $dataset = qfDataset(['t1' => ['Test Era', qfCards('t1', $n)]]);
    foreach (range(1, $atFloor - $under) as $i) {
        qfMap($build, "t1-{$i}", $i);
    }

    [$code, $artefact] = qfAudit($dataset, qfSnapshots([]));

    $overall = qfShare($artefact, null);
    expect($code)->toBe(0)
        ->and($overall['priced'])->toBe($atFloor - $under)
        ->and($overall['met'])->toBe($under === 0)
        ->and(qfShare($artefact, 'Test Era')['floor_pct'])->toBe(MapAudit::DEFAULT_ERA_FLOOR_BP / 100.0)
        ->and($artefact['value'])->toBe($under === 0 ? 0 : (qfShare($artefact, 'Test Era')['met'] ? 1 : 2));
})->with(['at the floor' => [0], 'one card under' => [1]]);

test('each era check passes at its floor and fails one card under it', function (string $era, int $under): void {
    // A fully priced filler era keeps the overall share above its floor, so only the era floor is at stake.
    $floor = MapAudit::ERA_FLOORS_BP[$era];
    $n = qfStep($floor);
    $atFloor = intdiv($floor * $n, 10000);
    $priced = $atFloor - $under;
    expect(MapAudit::PRICED_FLOOR_BP)->toBeLessThan(10000);
    // The fewest fully priced filler cards that lift the overall share to its floor, plus one.
    $filler = intdiv(max(0, MapAudit::PRICED_FLOOR_BP * $n - 10000 * $priced) + 9999 - MapAudit::PRICED_FLOOR_BP, 10000 - MapAudit::PRICED_FLOOR_BP) + 1;
    $build = qfBuild();
    $dataset = qfDataset(['e1' => [$era, qfCards('e1', $n)], 'f1' => ['Filler Era', qfCards('f1', $filler)]]);
    foreach (range(1, $priced) as $i) {
        qfMap($build, "e1-{$i}", $i);
    }
    foreach (range(1, $filler) as $i) {
        qfMap($build, "f1-{$i}", 100000 + $i);
    }

    [, $artefact] = qfAudit($dataset, qfSnapshots([]));

    expect(qfShare($artefact, null)['met'])->toBeTrue()
        ->and(qfShare($artefact, 'Filler Era')['met'])->toBeTrue()
        ->and(qfShare($artefact, $era))->toMatchArray(['cards' => $n, 'priced' => $priced, 'floor_pct' => $floor / 100.0, 'met' => $under === 0])
        ->and($artefact['value'])->toBe($under);
})->with(['Sun & Moon', 'XY', 'Black & White', 'Sword & Shield', 'HeartGold & SoulSilver'])->with(['at the floor' => [0], 'one card under' => [1]]);

test('the unmapped card products of the build are listed by market price, highest first, and the list is bounded', function (): void {
    $old = qfBuild('2026-10-10');
    $build = qfBuild();
    $dataset = qfDataset(['s1' => ['Sun & Moon', qfCards('s1', 2)]]);
    qfMap($build, 's1-1', 1);
    $limit = MapAudit::UNMAPPED_LIST;
    $prices = [];
    foreach (range(1, $limit + 5) as $i) {
        // product 1000+i costs i dollars, so the most expensive products are the highest ids
        qfUnmapped($build, 501, 1000 + $i, "Card {$i} (Secret)", (string) $i, $i % 2 === 0 ? 'unknown_tag' : 'name_disagreement', ['s1-2']);
        $prices[] = [1000 + $i, 'Holofoil', (float) $i];
    }
    // A reverse-holo row of the same product is its own item with its own price.
    qfUnmapped($build, 501, 1003, 'Card 3 (Secret)', '3', 'unknown_tag', ['s1-2'], 'Reverse Holofoil');
    $prices[] = [1003, 'Reverse Holofoil', 999.0];
    // Sealed products and groups with no catalogue set are not card products and never listed, however dear.
    qfUnmapped($build, 501, 5001, 'Booster Box', null, 'non_card', []);
    qfUnmapped($build, 502, 5002, 'Charizard (Japanese)', '4', 'no_set_alias', []);
    $prices[] = [5001, 'Normal', 5000.0];
    // An item of an older build is not this build's.
    qfUnmapped($old, 501, 5003, 'Card 9 (Old)', '9', 'unknown_tag', ['s1-2']);
    $prices[] = [5003, 'Holofoil', 4000.0];

    [$code, $artefact] = qfAudit($dataset, qfSnapshots([501 => $prices, 502 => [[5002, 'Holofoil', 3000.0]]]));

    $list = qfDetails($artefact, 'unmapped_product');
    expect($code)->toBe(0)
        ->and($list)->toHaveCount($limit)
        ->and($list[0])->toMatchArray(['rank' => 1, 'product_id' => 1003, 'subtype' => 'Reverse Holofoil', 'market_price' => 999.0, 'reason' => 'unknown_tag', 'candidates' => ['s1-2']])
        ->and($list[1])->toMatchArray(['rank' => 2, 'product_id' => 1000 + $limit + 5, 'subtype' => 'Holofoil', 'market_price' => (float) ($limit + 5),
            'product_name' => 'Card '.($limit + 5).' (Secret)', 'group_id' => 501, 'number' => (string) ($limit + 5)])
        ->and(array_column($list, 'rank'))->toBe(range(1, $limit))
        ->and(array_column($list, 'product_id'))->not->toContain(5001, 5002, 5003);
    $market = array_column($list, 'market_price');
    $sorted = $market;
    rsort($sorted);
    expect($market)->toBe($sorted);
});

test('an unmapped product without a market price sorts after every priced one', function (): void {
    $build = qfBuild();
    $dataset = qfDataset(['s1' => ['Sun & Moon', qfCards('s1', 3)]]);
    qfUnmapped($build, 501, 11, 'Card 1 (Secret)', '1', 'unknown_tag', ['s1-1']);
    qfUnmapped($build, 501, 12, 'Card 2 (Secret)', '2', 'unknown_tag', ['s1-2']);
    qfUnmapped($build, 501, 13, 'Card 3 (Secret)', '3', 'unknown_tag', ['s1-3']);

    [, $artefact] = qfAudit($dataset, qfSnapshots([501 => [[11, 'Holofoil', null], [12, 'Holofoil', 0.5]]]));

    $list = qfDetails($artefact, 'unmapped_product');
    expect(array_column($list, 'product_id'))->toBe([12, 11, 13])
        ->and(array_column($list, 'market_price'))->toBe([0.5, null, null]);
});

test('after a real build the priced check counts the rejected products and lists them by price', function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $json = fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // A Team Rocket reverse pattern (the Ascended Heroes tag) stays rejected wherever it appears, so two made-up
    // Hidden Fates products carry it here, beside the real Charizard GX.
    $products = [[197651, 'Charizard GX', '9/68', 9.57], [990101, 'Moltres (Team Rocket)', '10/68', 40.0], [990102, 'Zapdos (Team Rocket)', '11/68', 70.0]];
    $root = qfTemp();
    $day = "{$root}/2026-10-11";
    mkdir($day.'/products', 0775, true);
    mkdir($day.'/prices', 0775, true);
    file_put_contents($day.'/SUCCESS', '');
    file_put_contents($day.'/marker.txt', "2026-10-11T20:05:45+0000\n");
    file_put_contents($day.'/groups.json', $json(['success' => true, 'errors' => [], 'results' => [['groupId' => 2480, 'name' => 'Hidden Fates']]]));
    file_put_contents($day.'/products/2480.json.zst', (string) zstd_compress($json(['totalItems' => 3, 'success' => true, 'errors' => [], 'results' => array_map(
        fn (array $p): array => ['productId' => $p[0], 'name' => $p[1], 'groupId' => 2480, 'extendedData' => [['name' => 'Number', 'value' => $p[2]]]], $products)])));
    file_put_contents($day.'/prices/2480.json.zst', (string) zstd_compress($json(['success' => true, 'errors' => [], 'results' => array_map(
        fn (array $p): array => ['productId' => $p[0], 'marketPrice' => $p[3], 'subTypeName' => 'Holofoil'], $products)])));
    $dataset = qfDataset(['sm115' => ['Sun & Moon', [['sm115-9', 'Charizard-GX', '9'], ['sm115-10', 'Moltres', '10'], ['sm115-11', 'Zapdos', '11']]]]);

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $root]))->toBe(0);
    [$code, $artefact] = qfAudit($dataset, $root);

    expect($code)->toBe(0)
        ->and(qfShare($artefact, null))->toMatchArray(['cards' => 3, 'priced' => 1])
        ->and(array_column(qfDetails($artefact, 'unmapped_product'), 'product_id'))->toBe([990102, 990101])
        ->and(array_column(qfDetails($artefact, 'unmapped_product'), 'reason'))->toBe(['unknown_tag', 'unknown_tag']);
});

test('the priced check refuses when the build\'s collected day is missing, writing no artefact', function (): void {
    qfBuild();
    $dataset = qfDataset(['s1' => ['Sun & Moon', qfCards('s1', 1)]]);

    [$code, $artefact, $stdout] = qfAudit($dataset, qfTemp());

    expect($code)->toBe(1)
        ->and($artefact)->toBeNull()
        ->and($stdout)->toContain('map audit refused');
});

test('a set without a series is its own era, named so', function (): void {
    $build = qfBuild();
    $dir = qfTemp();
    mkdir($dir.'/sets', 0775, true);
    mkdir($dir.'/cards/en', 0775, true);
    file_put_contents($dir.'/sets/en.json', json_encode([['id' => 'x1', 'name' => 'No Series']], JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/cards/en/x1.json', json_encode([['id' => 'x1-1', 'name' => 'Mew', 'number' => '1']], JSON_THROW_ON_ERROR));
    qfMap($build, 'x1-1', 1);

    [$code, $artefact] = qfAudit($dir, qfSnapshots([]));

    expect($code)->toBe(0)
        ->and(qfShare($artefact, '(no series)'))->toMatchArray(['scope' => 'era', 'cards' => 1, 'priced' => 1, 'met' => true]);
});
