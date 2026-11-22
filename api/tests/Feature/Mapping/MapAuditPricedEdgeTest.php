<?php

declare(strict_types=1);

use App\Domain\Mapping\MapAudit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Edges of pokeprices:map:audit --check priced: a card counts as priced once however many rows map it and whatever
// else was rejected beside it, a mapping row of a card outside the dataset never lifts a share, an empty set or an
// empty catalogue divides by nothing, and the unmapped list is ordered and cut the same way every time.

const QFP_REF = '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11';

/** Remembers a temp root when given one; without an argument, returns the remembered roots and forgets them. */
function qfpRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

function qfpTemp(): string
{
    $dir = sys_get_temp_dir().'/pp-qfp-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    qfpRoots($dir);

    return $dir;
}

afterEach(function (): void {
    foreach (qfpRoots() as $root) {
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

/** @param array<string, array{string, list<array{string, string, string}>}> $sets set id => [series, cards] */
function qfpDataset(array $sets): string
{
    $dir = qfpTemp();
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

function qfpCards(string $set, int $n): array
{
    return array_map(fn (int $i): array => ["{$set}-{$i}", "Card {$i}", (string) $i], range(1, $n));
}

function qfpBuild(string $day = '2026-10-11'): int
{
    return (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day, 'dataset_ref' => QFP_REF, 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
}

function qfpMap(int $build, string $card, int $product, string $finish = 'holo', string $subtype = 'Holofoil'): void
{
    $key = "{$card}:{$finish}:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => '-', 'treatment' => '-']);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => 501, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'source_product_name' => 'Card', 'source_number' => '1', 'variant_key' => $key, 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
}

function qfpUnmapped(int $build, int $product, string $reason = 'unknown_tag', string $subtype = 'Holofoil', int $group = 501): void
{
    DB::table('unmapped_source_items')->insert([
        'map_build_id' => $build, 'source' => 'tcgplayer', 'source_group_id' => $group, 'source_product_id' => $product,
        'source_subtype' => $subtype, 'product_name' => "Card {$product} (Odd)", 'number' => '1', 'reason' => $reason,
        'candidates' => json_encode(['s1-1'], JSON_THROW_ON_ERROR),
    ]);
}

/** A collected day holding only price files: group => list of [productId, subTypeName, marketPrice|null]. */
function qfpSnapshots(array $prices, string $day = '2026-10-11'): string
{
    $root = qfpTemp();
    $dir = "{$root}/{$day}";
    mkdir($dir.'/products', 0775, true);
    mkdir($dir.'/prices', 0775, true);
    file_put_contents($dir.'/SUCCESS', '');
    file_put_contents($dir.'/marker.txt', "{$day}T20:05:45+0000\n");
    $groups = [];
    foreach ($prices as $group => $rows) {
        $groups[] = ['groupId' => $group, 'name' => "Group {$group}"];
        $results = array_map(fn (array $r): array => ['productId' => $r[0], 'marketPrice' => $r[2], 'subTypeName' => $r[1]], $rows);
        file_put_contents("{$dir}/prices/{$group}.json.zst", (string) zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => $results], JSON_THROW_ON_ERROR)));
        file_put_contents("{$dir}/products/{$group}.json.zst", (string) zstd_compress(json_encode(['success' => true, 'errors' => [], 'results' => []], JSON_THROW_ON_ERROR)));
    }
    file_put_contents($dir.'/groups.json', json_encode(['success' => true, 'errors' => [], 'results' => $groups], JSON_THROW_ON_ERROR));

    return $root;
}

/** @return array{int, array<string, mixed>|null} [exit code, artefact] */
function qfpAudit(string $dataset, string $snapshots, array $extra = []): array
{
    $out = qfpTemp().'/artefact.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'priced', '--json' => true, '--out' => $out, '--dataset' => $dataset, '--snapshots' => $snapshots, ...$extra]);
    $artefact = is_file($out) ? json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR) : null;

    return [$code, $artefact];
}

function qfpShare(array $artefact, ?string $series): ?array
{
    foreach ($artefact['details'] as $row) {
        if ($row['kind'] === 'priced_share' && $row['series'] === $series) {
            return $row;
        }
    }

    return null;
}

/** @return list<array<string, mixed>> */
function qfpList(array $artefact): array
{
    return array_values(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'unmapped_product'));
}

test('a card with one mapped and one rejected product is priced, counted once, and the rejected one is still listed', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', [['s1-1', 'Card 1', '1'], ['s1-2', 'Card 2', '2']]]]);
    qfpMap($build, 's1-1', 7001);
    qfpMap($build, 's1-1', 7001, 'reverse', 'Reverse Holofoil');
    qfpMap($build, 's1-1', 7003, 'normal', 'Normal');
    qfpUnmapped($build, 7002);

    [$code, $artefact] = qfpAudit($dataset, qfpSnapshots([501 => [[7002, 'Holofoil', 12.5]]]));

    expect($code)->toBe(0)
        ->and(qfpShare($artefact, null))->toMatchArray(['cards' => 2, 'priced' => 1, 'share_pct' => 50.0])
        ->and(qfpShare($artefact, 'Sun & Moon'))->toMatchArray(['cards' => 2, 'priced' => 1])
        ->and(array_column(qfpList($artefact), 'product_id'))->toBe([7002])
        ->and(qfpList($artefact)[0]['market_price'])->toBe(12.5);
});

test('a mapping row of a card outside the dataset never counts as a priced card', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 2)]]);
    qfpMap($build, 's1-1', 1);
    qfpMap($build, 'gone-1', 2);
    qfpMap($build, 'gone-2', 3);

    [, $artefact] = qfpAudit($dataset, qfpSnapshots([]));

    expect(qfpShare($artefact, null))->toMatchArray(['cards' => 2, 'priced' => 1, 'share_pct' => 50.0, 'met' => false])
        ->and(qfpShare($artefact, 'Sun & Moon'))->toMatchArray(['cards' => 2, 'priced' => 1, 'met' => false]);
    foreach (array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'priced_share') as $share) {
        expect($share['priced'])->toBeLessThanOrEqual($share['cards']);
    }
});

test('a current mapping row kept from an earlier build still prices its card', function (): void {
    $old = qfpBuild('2026-10-10');
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    qfpMap($old, 's1-1', 1);

    [$code, $artefact] = qfpAudit($dataset, qfpSnapshots([]), ['--build' => (string) $build]);

    expect($code)->toBe(0)
        ->and($artefact['inputs']['map_build_id'])->toBe($build)
        ->and(qfpShare($artefact, null))->toMatchArray(['cards' => 1, 'priced' => 1, 'share_pct' => 100.0, 'met' => true]);
});

test('a set with no cards adds no era and divides by nothing', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)], 'e0' => ['Empty Era', []]]);
    qfpMap($build, 's1-1', 1);

    [$code, $artefact] = qfpAudit($dataset, qfpSnapshots([]));

    $series = array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'priced_share'), 'series');
    expect($code)->toBe(0)
        ->and($series)->toBe([null, 'Sun & Moon'])
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(2);
});

test('a catalogue with no cards at all reports a zero share without an error', function (): void {
    qfpBuild();
    $dataset = qfpDataset(['e0' => ['Empty Era', []]]);

    [$code, $artefact] = qfpAudit($dataset, qfpSnapshots([]));

    expect($code)->toBe(0)
        ->and($artefact['priced_pct'])->toBe(0.0)
        ->and(qfpShare($artefact, null))->toMatchArray(['cards' => 0, 'priced' => 0, 'share_pct' => 0.0])
        ->and($artefact['denominator'])->toBe(1);
});

test('the overall share one card under its floor fails on a large catalogue too, and passes at it', function (int $under): void {
    // 10,000 cards: the floor in basis points is exactly the number of priced cards it takes.
    $n = 10000;
    $atFloor = MapAudit::PRICED_FLOOR_BP;
    $build = qfpBuild();
    $dataset = qfpDataset(['t1' => ['Test Era', qfpCards('t1', $n)]]);
    $rows = [];
    $variants = [];
    foreach (range(1, $atFloor - $under) as $i) {
        $variants[] = ['variant_key' => "t1-{$i}:holo:-:-", 'card_id' => "t1-{$i}", 'finish' => 'holo', 'edition' => '-', 'treatment' => '-'];
        $rows[] = ['source' => 'tcgplayer', 'source_group_id' => 501, 'source_product_id' => $i, 'source_subtype' => 'Holofoil',
            'source_product_name' => 'Card', 'source_number' => '1', 'variant_key' => "t1-{$i}:holo:-:-", 'method' => 'number+name',
            'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build];
    }
    foreach (array_chunk($variants, 500) as $chunk) {
        DB::table('card_variants')->insert($chunk);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('source_product_map')->insert($chunk);
    }

    [, $artefact] = qfpAudit($dataset, qfpSnapshots([]));

    expect(qfpShare($artefact, null))->toMatchArray(['cards' => $n, 'priced' => $atFloor - $under, 'met' => $under === 0]);
})->with(['at the floor' => [0], 'one card under' => [1]]);

test('ties in market price are broken by product id then subtype, and unpriced items follow in the same order', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    // inserted out of order on purpose
    foreach ([[30, 'Reverse Holofoil'], [10, 'Holofoil'], [30, 'Holofoil'], [20, 'Holofoil'], [50, 'Holofoil'], [40, 'Holofoil'], [60, 'Holofoil']] as [$product, $subtype]) {
        qfpUnmapped($build, $product, 'unknown_tag', $subtype);
    }

    [, $artefact] = qfpAudit($dataset, qfpSnapshots([501 => [
        [10, 'Holofoil', 5.0], [20, 'Holofoil', 5.0], [30, 'Holofoil', 5.0], [30, 'Reverse Holofoil', 5.0], [40, 'Holofoil', 9.0],
        [50, 'Holofoil', null],
        // product 60 has no price row at all
    ]]));

    $list = qfpList($artefact);
    expect(array_map(fn (array $r): array => [$r['product_id'], $r['subtype'], $r['market_price']], $list))->toBe([
        [40, 'Holofoil', 9.0],
        [10, 'Holofoil', 5.0],
        [20, 'Holofoil', 5.0],
        [30, 'Holofoil', 5.0],
        [30, 'Reverse Holofoil', 5.0],
        [50, 'Holofoil', null],
        [60, 'Holofoil', null],
    ])->and(array_column($list, 'rank'))->toBe(range(1, 7));
});

test('the list is cut at its bound by the same order, so a tie at the cut keeps the lowest product ids', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    $limit = MapAudit::UNMAPPED_LIST;
    $prices = [];
    // one dear product, then limit + 10 products all at the same price, inserted from the highest id down
    qfpUnmapped($build, 1, 'name_disagreement');
    $prices[] = [1, 'Holofoil', 100.0];
    foreach (range($limit + 10, 1) as $i) {
        qfpUnmapped($build, 1000 + $i, $i % 3 === 0 ? 'duplicate_target' : 'unknown_tag');
        $prices[] = [1000 + $i, 'Holofoil', 2.0];
    }
    // plus unpriced items that must never push a priced one out
    foreach (range(1, 5) as $i) {
        qfpUnmapped($build, 5000 + $i, 'no_number_match');
    }

    [, $artefact] = qfpAudit($dataset, qfpSnapshots([501 => $prices]));

    $list = qfpList($artefact);
    expect($list)->toHaveCount($limit)
        ->and(array_column($list, 'product_id'))->toBe([1, ...range(1001, 1000 + $limit - 1)])
        ->and(array_filter(array_column($list, 'market_price'), fn (?float $p): bool => $p === null))->toBe([]);
});

test('a group of the build with no prices file on the collected day lists its items unpriced, without an error', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    qfpUnmapped($build, 1, 'unknown_tag', 'Holofoil', 777);
    qfpUnmapped($build, 2, 'unknown_tag', 'Holofoil', 501);

    [$code, $artefact] = qfpAudit($dataset, qfpSnapshots([501 => [[2, 'Holofoil', 3.0]]]));

    expect($code)->toBe(0)
        ->and(array_map(fn (array $r): array => [$r['group_id'], $r['product_id'], $r['market_price']], qfpList($artefact)))
        ->toBe([[501, 2, 3.0], [777, 1, null]]);
});

test('a collected day that is there but unfinished, or a missing day for another build, is refused with no artefact', function (string $case): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    qfpMap($build, 's1-1', 1);
    $snapshots = qfpSnapshots([]);
    $extra = [];
    if ($case === 'unfinished') {
        unlink("{$snapshots}/2026-10-11/SUCCESS");
    } else {
        // an older build collected on a day the snapshots root does not hold
        $extra = ['--build' => (string) qfpBuild('2026-10-09')];
    }

    [$code, $artefact] = qfpAudit($dataset, $snapshots, $extra);

    expect($code)->toBe(1)
        ->and($artefact)->toBeNull();
})->with(['unfinished', 'another day']);

test('a prices file that does not decode is refused, not read as unpriced', function (): void {
    $build = qfpBuild();
    $dataset = qfpDataset(['s1' => ['Sun & Moon', qfpCards('s1', 1)]]);
    qfpUnmapped($build, 1);
    $snapshots = qfpSnapshots([501 => [[1, 'Holofoil', 3.0]]]);
    file_put_contents("{$snapshots}/2026-10-11/prices/501.json.zst", 'not zstd');

    [$code, $artefact] = qfpAudit($dataset, $snapshots);

    expect($code)->toBe(1)
        ->and($artefact)->toBeNull();
});
