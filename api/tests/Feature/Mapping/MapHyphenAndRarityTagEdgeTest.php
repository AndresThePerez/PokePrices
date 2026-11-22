<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Whole builds that try to make the hyphen fold, the Unown rule and the neutral descriptors move or merge a mapping.
// The shared fixture snapshot is only read; every changed day is a temp copy or a temp day written here.

const QFE_DAY = '2026-10-11';

/**
 * The shared fixture's map as the rules before the hyphen and descriptor change built it over the unchanged fixture,
 * where product 610390 was still `Vaporeon - 008/131 (Secret)`: [variant_key, group, product, subtype, method].
 */
const QFE_BASE_MAP = [
    ['base1-4:holo:1st:-', 1663, 106999, '1st Edition Holofoil', 'number+name'],
    ['base1-4:holo:shadowless:-', 1663, 106999, 'Unlimited Holofoil', 'number+name'],
    ['base1-4:holo:unlimited:-', 604, 42382, 'Holofoil', 'number+name'],
    ['base1-4:holo:unlimited:error-black-dot', 604, 657516, 'Holofoil', 'number+name'],
    ['base1-8:holo:1st:-', 1840, 42425, '1st Edition Holofoil', 'number+name'],
    ['base1-8:holo:1st:shadowless', 1840, 107004, '1st Edition Holofoil', 'number+name'],
    ['basep-13:holo:-:-', 1418, 85001, 'Holofoil', 'number+name'],
    ['basep-8:holo:-:-', 1418, 85000, 'Holofoil', 'number+name'],
    ['ex10-!:holo:-:-', 1398, 90166, 'Holofoil', 'number+name'],
    ['ex10-?:holo:-:-', 1398, 90167, 'Holofoil', 'number+name'],
    ['sv8pt5-5:holo:-:-', 23821, 610360, 'Holofoil', 'number+name'],
    ['sv8pt5-5:reverse:-:-', 23821, 610360, 'Reverse Holofoil', 'number+name'],
    ['sv8pt5-5:reverse:-:masterball', 23821, 610641, 'Holofoil', 'number+name'],
    ['sv8pt5-5:reverse:-:pokeball', 23821, 610540, 'Holofoil', 'number+name'],
    ['sv8pt5-7:normal:-:-', 23821, 610380, 'Normal', 'number+name'],
    ['svp-46:holo:-:-', 22872, 517271, 'Holofoil', 'number+name'],
    ['svp-5:holo:-:-', 22872, 487751, 'Holofoil', 'number+name'],
    ['svp-5:holo:-:stamp-prerelease-staff', 22872, 522650, 'Holofoil', 'number+name'],
    ['tk1a-1:normal:-:-', 1543, 83694, 'Normal', 'name_tiebreak'],
    ['tk1a-8:normal:-:-', 1543, 88335, 'Normal', 'name_tiebreak'],
    ['tk1b-1:normal:-:-', 1543, 85131, 'Normal', 'name_tiebreak'],
    ['tk1b-8:normal:-:-', 1543, 88336, 'Normal', 'name_tiebreak'],
];

/** Remembers a temp root when given one; without an argument, returns the remembered roots and forgets them. */
function qfeRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

function qfeTemp(): string
{
    $root = sys_get_temp_dir().'/pp-qfe-'.bin2hex(random_bytes(6));
    mkdir($root, 0775, true);
    qfeRoots($root);

    return $root;
}

afterEach(function (): void {
    foreach (qfeRoots() as $root) {
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

function qfeFixture(string $path): string
{
    return base_path('tests/Fixtures/tcgcsv/'.$path);
}

/** A writable copy of the shared fixture snapshots root. */
function qfeCopyFixtureSnapshots(): string
{
    $root = qfeTemp();
    $from = qfeFixture('snapshots/'.QFE_DAY);
    $to = $root.'/'.QFE_DAY;
    foreach (['', '/products', '/prices'] as $dir) {
        mkdir($to.$dir, 0775, true);
    }
    foreach (['SUCCESS', 'marker.txt', 'groups.json'] as $file) {
        copy("{$from}/{$file}", "{$to}/{$file}");
    }
    foreach (['products', 'prices'] as $dir) {
        foreach (glob("{$from}/{$dir}/*.json.zst") ?: [] as $file) {
            copy($file, "{$to}/{$dir}/".basename($file));
        }
    }

    return $root;
}

/** Rewrites one product of a group in a copied snapshots root: a new name, and optionally a new product beside it. */
function qfeEditProducts(string $root, int $group, callable $edit): void
{
    foreach (['products', 'prices'] as $kind) {
        $path = "{$root}/".QFE_DAY."/{$kind}/{$group}.json.zst";
        $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        $document['results'] = $edit($kind, $document['results']);
        if (array_key_exists('totalItems', $document)) {
            $document['totalItems'] = count($document['results']);
        }
        file_put_contents($path, (string) zstd_compress(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}

/** Sets product $id's name in group $group (products file only). */
function qfeRename(string $root, int $group, int $id, string $name): void
{
    qfeEditProducts($root, $group, function (string $kind, array $rows) use ($id, $name): array {
        if ($kind === 'products') {
            foreach ($rows as $i => $row) {
                if ($row['productId'] === $id) {
                    $rows[$i]['name'] = $name;
                    $rows[$i]['cleanName'] = $name;
                }
            }
        }

        return $rows;
    });
}

/** Adds a copy of product $from (name, number and price rows) as product $to named $name. */
function qfeClone(string $root, int $group, int $from, int $to, string $name): void
{
    qfeEditProducts($root, $group, function (string $kind, array $rows) use ($from, $to, $name): array {
        foreach ($rows as $row) {
            if ($row['productId'] === $from) {
                $row['productId'] = $to;
                if ($kind === 'products') {
                    $row['name'] = $name;
                    $row['cleanName'] = $name;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    });
}

function qfeBuild(string $snapshots, ?string $dataset = null): int
{
    return Artisan::call('pokeprices:map:build', ['--day' => QFE_DAY, '--dataset' => $dataset ?? qfeFixture('dataset'), '--snapshots' => $snapshots]);
}

/** Every current mapping as [variant_key, group, product, subtype, method], ordered by variant_key. */
function qfeMappings(): array
{
    return DB::table('source_product_map')->orderBy('variant_key')->get()
        ->map(fn (object $r): array => [$r->variant_key, (int) $r->source_group_id, (int) $r->source_product_id, $r->source_subtype, $r->method])
        ->all();
}

/** The newest build's unmapped items as product => [subtype, reason, candidates]. */
function qfeUnmapped(): array
{
    $build = DB::table('map_builds')->max('id');
    $out = [];
    foreach (DB::table('unmapped_source_items')->where('map_build_id', $build)->orderBy('source_product_id')->orderBy('source_subtype')->get() as $r) {
        $out[(int) $r->source_product_id][] = [$r->source_subtype, $r->reason, json_decode((string) $r->candidates, true)];
    }

    return $out;
}

/** No source product (and subtype) is mapped to two cards. */
function qfeNoProductOnTwoCards(): void
{
    $rows = DB::table('source_product_map as m')->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')
        ->get(['m.source_product_id', 'm.source_subtype', 'v.card_id']);
    $cards = [];
    foreach ($rows as $r) {
        $cards["{$r->source_product_id}|{$r->source_subtype}"][(string) $r->card_id] = true;
    }
    foreach ($cards as $key => $set) {
        expect(count($set))->toBe(1, "product {$key} is mapped to ".implode(', ', array_keys($set)));
    }
}

describe('the shared fixture snapshot', function (): void {
    beforeEach(function (): void {
        Queue::fake([VerifyCatalog::class]);
        $this->seed(SetAliasSeeder::class);
    });

    test('the unchanged fixture, Vaporeon (Secret) included, keeps every pair the old rules built and only adds the Secret', function (): void {
        $root = qfeCopyFixtureSnapshots();
        qfeRename($root, 23821, 610390, 'Vaporeon - 008/131 (Secret)');

        expect(qfeBuild($root))->toBe(0);

        $map = qfeMappings();
        $added = array_values(array_filter($map, fn (array $r): bool => ! in_array($r, QFE_BASE_MAP, true)));
        expect(array_values(array_filter(QFE_BASE_MAP, fn (array $r): bool => ! in_array($r, $map, true))))->toBe([])
            ->and($added)->toBe([['sv8pt5-8:holo:-:-', 23821, 610390, 'Holofoil', 'number+name']])
            ->and(qfeUnmapped())->not->toHaveKey(610390);
        qfeNoProductOnTwoCards();
    });

    test('the renamed fixture keeps Jumbo rejected and maps exactly what the old rules mapped', function (): void {
        expect(qfeBuild(qfeFixture('snapshots')))->toBe(0)
            ->and(qfeMappings())->toBe(QFE_BASE_MAP)
            ->and(qfeUnmapped()[610390] ?? null)->toBe([['Holofoil', 'unknown_tag', ['sv8pt5-8']]]);
    });

    test('a rebuild where the Jumbo product reads (Secret) again only inserts: no row moves, no history', function (): void {
        expect(qfeBuild(qfeFixture('snapshots')))->toBe(0);
        $before = DB::table('source_product_map')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();

        $root = qfeCopyFixtureSnapshots();
        qfeRename($root, 23821, 610390, 'Vaporeon - 008/131 (Secret)');
        expect(qfeBuild($root))->toBe(0);

        $after = DB::table('source_product_map')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
        expect(DB::table('source_product_map_history')->count())->toBe(0)
            ->and(array_slice($after, 0, count($before)))->toBe($before)
            ->and(count($after))->toBe(count($before) + 1)
            ->and([$after[count($before)]['variant_key'], (int) $after[count($before)]['source_product_id'], (int) $after[count($before)]['series_epoch']])
            ->toBe(['sv8pt5-8:holo:-:-', 610390, 1]);
    });

    test('a (Secret) product beside a plain one of the same number never maps both: one wins, the other is listed', function (): void {
        $root = qfeCopyFixtureSnapshots();
        qfeRename($root, 23821, 610390, 'Vaporeon - 008/131 (Secret)');
        qfeClone($root, 23821, 610390, 610391, 'Vaporeon - 008/131');

        expect(qfeBuild($root))->toBe(0);

        $vaporeon = array_values(array_filter(qfeMappings(), fn (array $r): bool => str_starts_with($r[0], 'sv8pt5-8:')));
        expect($vaporeon)->toBe([['sv8pt5-8:holo:-:-', 23821, 610390, 'Holofoil', 'number+name']])
            ->and(qfeUnmapped()[610391] ?? null)->toBe([['Holofoil', 'duplicate_target', ['sv8pt5-8']]]);
        qfeNoProductOnTwoCards();
    });

    test('when a now-neutral (Secret) product takes a key a plain product held, the move is recorded, never silent', function (): void {
        $root = qfeCopyFixtureSnapshots();
        qfeClone($root, 23821, 610390, 610391, 'Vaporeon - 008/131');
        expect(qfeBuild($root))->toBe(0);
        $held = DB::table('source_product_map')->where('variant_key', 'sv8pt5-8:holo:-:-')->sole();
        expect((int) $held->source_product_id)->toBe(610391);

        qfeRename($root, 23821, 610390, 'Vaporeon - 008/131 (Secret)');
        expect(qfeBuild($root))->toBe(0);

        $history = DB::table('source_product_map_history')->get();
        expect($history)->toHaveCount(1)
            ->and([$history[0]->variant_key, (int) $history[0]->source_product_id, $history[0]->end_reason])->toBe(['sv8pt5-8:holo:-:-', 610391, 'remapped'])
            ->and(qfeUnmapped()[610391] ?? null)->toBe([['Holofoil', 'duplicate_target', ['sv8pt5-8']]]);
        qfeNoProductOnTwoCards();
    });

    test('a newly neutral tag beside Jumbo stays rejected and beside Staff maps to the staff print, never to the plain card', function (): void {
        $root = qfeCopyFixtureSnapshots();
        qfeRename($root, 23821, 610390, 'Vaporeon - 008/131 (Secret) (Jumbo)');
        qfeClone($root, 23821, 610390, 610392, 'Vaporeon - 008/131 (Secret) [Staff]');

        expect(qfeBuild($root))->toBe(0);

        $vaporeon = array_values(array_filter(qfeMappings(), fn (array $r): bool => str_starts_with($r[0], 'sv8pt5-8:')));
        expect($vaporeon)->toBe([['sv8pt5-8:holo:-:stamp-staff', 23821, 610392, 'Holofoil', 'number+name']])
            ->and(qfeUnmapped()[610390] ?? null)->toBe([['Holofoil', 'unknown_tag', ['sv8pt5-8']]]);
    });
});

/**
 * Writes a temp day and dataset and inserts their own set_aliases rows.
 *
 * @param  array<int, array{string, list<array{int, string, string}>}>  $groups  group => [name, list of [productId, name, Number]]
 * @param  array<string, list<array{string, string, string}>>  $sets  set id => list of [card id, name, number]
 * @param  array<int, list<string>>  $aliases  group => the set ids it is aliased to
 * @return array{string, string} [snapshots root, dataset dir]
 */
function qfeInputs(array $groups, array $sets, array $aliases): array
{
    $json = fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $root = qfeTemp();
    $day = "{$root}/".QFE_DAY;
    mkdir($day.'/products', 0775, true);
    mkdir($day.'/prices', 0775, true);
    file_put_contents($day.'/SUCCESS', '');
    file_put_contents($day.'/marker.txt', QFE_DAY."T20:05:45+0000\n");
    $groupRows = [];
    foreach ($groups as $group => [$name, $products]) {
        $groupRows[] = ['groupId' => $group, 'name' => $name];
        file_put_contents("{$day}/products/{$group}.json.zst", (string) zstd_compress($json(['totalItems' => count($products), 'success' => true, 'errors' => [], 'results' => array_map(
            fn (array $p): array => ['productId' => $p[0], 'name' => $p[1], 'cleanName' => $p[1], 'groupId' => $group, 'extendedData' => [['name' => 'Number', 'value' => $p[2]]]], $products)])));
        file_put_contents("{$day}/prices/{$group}.json.zst", (string) zstd_compress($json(['success' => true, 'errors' => [], 'results' => array_map(
            fn (array $p): array => ['productId' => $p[0], 'marketPrice' => 1.0, 'subTypeName' => 'Holofoil'], $products)])));
    }
    file_put_contents($day.'/groups.json', $json(['success' => true, 'errors' => [], 'results' => $groupRows]));

    $dataset = qfeTemp();
    mkdir($dataset.'/sets', 0775, true);
    mkdir($dataset.'/cards/en', 0775, true);
    $list = [];
    foreach ($sets as $set => $cards) {
        $list[] = ['id' => $set, 'name' => "Set {$set}", 'series' => 'Test'];
        file_put_contents("{$dataset}/cards/en/{$set}.json", $json(array_map(fn (array $c): array => ['id' => $c[0], 'name' => $c[1], 'number' => $c[2]], $cards)));
    }
    file_put_contents($dataset.'/sets/en.json', $json($list));
    file_put_contents($dataset.'/SOURCE.json', $json(['sha' => str_repeat('0', 40)]));

    foreach ($aliases as $group => $setIds) {
        foreach ($setIds as $set) {
            DB::table('set_aliases')->insert(['pokesearch_set_id' => $set, 'source' => 'tcgplayer', 'source_group_id' => $group, 'role' => 'primary',
                'number_rule' => null, 'method' => 'manual', 'unlimited_means' => null, 'note' => 'test row']);
        }
    }

    return [$root, $dataset];
}

/** @return array<int, string> product id => the card it is mapped to */
function qfeCardByProduct(): array
{
    $out = [];
    foreach (DB::table('source_product_map as m')->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')->orderBy('m.source_product_id')->get(['m.source_product_id', 'v.card_id']) as $r) {
        $out[(int) $r->source_product_id] = (string) $r->card_id;
    }

    return $out;
}

describe('temp days', function (): void {
    beforeEach(function (): void {
        Queue::fake([VerifyCatalog::class]);
    });

    test('hyphenated names map to their own card, and a Mega, a half or a plain form at that number is a disagreement', function (): void {
        [$snapshots, $dataset] = qfeInputs(
            [990001 => ['Hyphen Set', [
                [1, 'Ho-Oh EX', '1/30'], [2, 'Ho-Oh GX', '2/30'], [3, 'Kommo-o GX', '3/30'], [4, 'Porygon-Z', '4/30'],
                [5, 'M Charizard EX', '5/30'], [6, 'Charizard EX', '6/30'], [7, 'Pikachu & Zekrom GX', '7/30'], [8, 'Jangmo-o', '8/30'],
                // the wrong name at each number
                [11, 'Charizard EX', '5/30'], [12, 'M Charizard EX', '6/30'], [13, 'Pikachu GX', '7/30'], [14, 'Ho-Oh GX', '1/30'],
                [15, 'Ho-Oh EX', '2/30'], [16, 'Porygon Z', '4/30'], [17, 'Jangmo o', '8/30'], [18, 'Kommo-o', '3/30'],
            ]]],
            ['hy1' => [
                ['hy1-1', 'Ho-Oh-EX', '1'], ['hy1-2', 'Ho-Oh-GX', '2'], ['hy1-3', 'Kommo-o-GX', '3'], ['hy1-4', 'Porygon-Z', '4'],
                ['hy1-5', 'M Charizard-EX', '5'], ['hy1-6', 'Charizard-EX', '6'], ['hy1-7', 'Pikachu & Zekrom-GX', '7'], ['hy1-8', 'Jangmo-o', '8'],
            ]],
            [990001 => ['hy1']],
        );

        expect(qfeBuild($snapshots, $dataset))->toBe(0)
            ->and(qfeCardByProduct())->toBe([1 => 'hy1-1', 2 => 'hy1-2', 3 => 'hy1-3', 4 => 'hy1-4', 5 => 'hy1-5', 6 => 'hy1-6', 7 => 'hy1-7', 8 => 'hy1-8']);
        $unmapped = qfeUnmapped();
        foreach ([11 => 'hy1-5', 12 => 'hy1-6', 13 => 'hy1-7', 14 => 'hy1-1', 15 => 'hy1-2', 16 => 'hy1-4', 17 => 'hy1-8', 18 => 'hy1-3'] as $product => $card) {
            // 14 and 15 name the other suffix; each wrong name is a disagreement, never a second mapping of the card
            expect($unmapped[$product] ?? null)->toBe([['Holofoil', 'name_disagreement', [$card]]]);
        }
        qfeNoProductOnTwoCards();
    });

    test('an -EX card and an ex card at one number of a shared group never let a product pick one of them silently', function (): void {
        [$snapshots, $dataset] = qfeInputs(
            [990002 => ['Shared Group', [[21, 'Charizard EX', '1/30'], [22, 'Charizard ex', '2/30'], [23, 'Charizard-EX', '3/30']]]],
            [
                'xa1' => [['xa1-1', 'Charizard-EX', '1'], ['xa1-2', 'Charizard-EX', '2'], ['xa1-3', 'Charizard-EX', '3']],
                'xb1' => [['xb1-1', 'Charizard ex', '1'], ['xb1-2', 'Charizard ex', '2'], ['xb1-3', 'Charizard ex', '3']],
            ],
            [990002 => ['xa1', 'xb1']],
        );

        expect(qfeBuild($snapshots, $dataset))->toBe(0)
            ->and(qfeCardByProduct())->toBe([]);
        $unmapped = qfeUnmapped();
        foreach ([21 => ['xa1-1', 'xb1-1'], 22 => ['xa1-2', 'xb1-2'], 23 => ['xa1-3', 'xb1-3']] as $product => $cards) {
            expect($unmapped[$product] ?? null)->toBe([['Holofoil', 'ambiguous', $cards]]);
        }
    });

    test('Unown letters at a shared number go to the card of their own letter, and a lettered card is never given a plain or other-letter product', function (): void {
        [$snapshots, $dataset] = qfeInputs(
            [990003 => ['Unown Group', [
                [31, 'Unown [A]', '10/30'], [32, 'Unown (B)', '10/30'], [33, 'Unown (?)', '11/30'], [34, 'Unown', '12/30'], [35, 'Unown [C] (D)', '13/30'],
            ]]],
            [
                'ua1' => [['ua1-10', 'Unown [A]', '10'], ['ua1-11', 'Unown [!]', '11'], ['ua1-12', 'Unown [Q]', '12'], ['ua1-13', 'Unown [C]', '13']],
                'ub1' => [['ub1-10', 'Unown [B]', '10']],
            ],
            [990003 => ['ua1', 'ub1']],
        );

        expect(qfeBuild($snapshots, $dataset))->toBe(0)
            ->and(qfeCardByProduct())->toBe([31 => 'ua1-10', 32 => 'ub1-10']);
        $unmapped = qfeUnmapped();
        expect($unmapped[33] ?? null)->toBe([['Holofoil', 'name_disagreement', ['ua1-11']]])
            ->and($unmapped[34] ?? null)->toBe([['Holofoil', 'name_disagreement', ['ua1-12']]])
            ->and($unmapped[35] ?? null)->toBe([['Holofoil', 'name_disagreement', ['ua1-13']]]);
        qfeNoProductOnTwoCards();
    });
});
