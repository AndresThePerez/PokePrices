<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for the Delta energy rule through the whole build: a Delta product must land on a δ card and
// on nothing else, even when a plain card of the same number is a candidate or is the only one there.

/** @param array<string, string> $files relative path => bytes */
function deltaEdgeTree(array $files): string
{
    $root = sys_get_temp_dir().'/pp-delta-edge-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $bytes) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0775, true);
        }
        file_put_contents($root.'/'.$path, $bytes);
    }
    register_shutdown_function(function () use ($root): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir((string) $f->getPathname()) : unlink((string) $f->getPathname());
        }
        rmdir($root);
    });

    return $root;
}

/**
 * One group (1379, aliased to ex13) with one product, and a dataset whose cards are given.
 *
 * @param  array<string, list<array{string, string, string}>>  $cardsBySet  set id => list of [card id, name, number]
 * @return array{string, string}
 */
function deltaEdgeInputs(string $productName, string $productNumber, array $cardsBySet): array
{
    $json = fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $zst = fn (mixed $v): string => (string) zstd_compress($json($v), 3);
    $group = ['groupId' => 1379, 'name' => 'EX Holon Phantoms', 'abbreviation' => 'FX', 'isSupplemental' => false,
        'publishedOn' => '2026-01-01T00:00:00', 'modifiedOn' => '2026-10-01T00:00:00', 'categoryId' => 3];
    $product = ['productId' => 84754, 'name' => $productName, 'cleanName' => $productName, 'categoryId' => 3, 'groupId' => 1379,
        'extendedData' => [['name' => 'Number', 'displayName' => 'Card Number', 'value' => $productNumber]]];
    $price = ['productId' => 84754, 'lowPrice' => 1.11, 'midPrice' => 2.22, 'highPrice' => 3.33, 'marketPrice' => 2.5,
        'directLowPrice' => null, 'subTypeName' => 'Normal'];
    $day = [
        '2026-10-11/SUCCESS' => '',
        '2026-10-11/marker.txt' => "2026-10-11T20:05:45+0000\n",
        '2026-10-11/groups.json' => $json(['totalItems' => 1, 'success' => true, 'errors' => [], 'results' => [$group]]),
        '2026-10-11/products/1379.json.zst' => $zst(['totalItems' => 1, 'success' => true, 'errors' => [], 'results' => [$product]]),
        '2026-10-11/prices/1379.json.zst' => $zst(['success' => true, 'errors' => [], 'results' => [$price]]),
    ];

    $files = [
        'sets/en.json' => $json(array_map(fn (string $id): array => ['id' => $id, 'name' => strtoupper($id)], array_keys($cardsBySet))),
        'SOURCE.json' => $json(['sha' => str_repeat('0', 40)]),
    ];
    foreach ($cardsBySet as $set => $cards) {
        $files["cards/en/{$set}.json"] = $json(array_map(
            fn (array $c): array => ['id' => $c[0], 'name' => $c[1], 'supertype' => 'Energy', 'number' => $c[2]],
            $cards,
        ));
    }

    return [deltaEdgeTree($day), deltaEdgeTree($files)];
}

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
});

test('a Delta energy product is never put on a plain Rainbow Energy card, and the audit has nothing mapped to disagree about', function (): void {
    [$snapshots, $dataset] = deltaEdgeInputs('Delta Rainbow Energy', '098/110', ['ex13' => [['ex13-98', 'Rainbow Energy', '98']]]);

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    expect(DB::table('source_product_map')->count())->toBe(0)
        ->and(DB::table('unmapped_source_items')->where('source_product_id', 84754)->pluck('reason')->all())->toBe(['name_disagreement']);
});

test('when a second set of the same group holds a plain card at the same number, the Delta product maps to the δ card only, not ambiguous', function (): void {
    DB::table('set_aliases')->insert([
        'source' => 'tcgplayer', 'pokesearch_set_id' => 'exz', 'source_group_id' => 1379, 'role' => 'primary',
        'number_rule' => null, 'method' => 'manual', 'unlimited_means' => null, 'note' => 'review fixture',
    ]);
    [$snapshots, $dataset] = deltaEdgeInputs('Delta Rainbow Energy', '098/110', [
        'ex13' => [['ex13-98', 'δ Rainbow Energy', '98']],
        'exz' => [['exz-98', 'Rainbow Energy', '98']],
    ]);

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    $map = DB::table('source_product_map')->pluck('source_product_id', 'variant_key')->map(fn (mixed $id): int => (int) $id)->all();

    expect($map)->toBe(['ex13-98:normal:-:-' => 84754])
        ->and(DB::table('unmapped_source_items')->count())->toBe(0);
});

test('a Delta energy product of another energy name does not map to a δ Rainbow Energy card at the same number', function (): void {
    [$snapshots, $dataset] = deltaEdgeInputs('Delta Holon Energy', '098/110', ['ex13' => [['ex13-98', 'δ Rainbow Energy', '98']]]);

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    expect(DB::table('source_product_map')->count())->toBe(0)
        ->and(DB::table('unmapped_source_items')->where('source_product_id', 84754)->pluck('reason')->all())->toBe(['name_disagreement']);
});
