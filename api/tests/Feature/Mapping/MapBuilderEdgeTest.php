<?php

declare(strict_types=1);

use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Adversarial edge tests for pokeprices:map:build (plan T39 review). Self-contained: own helpers, own temp roots,
// the shared synthetic day in tests/Fixtures/tcgcsv is only ever read.

const T39E_DAY = '2026-10-11';

function t39eFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv'.($path === '' ? '' : '/'.$path));
}

/** @return array{int, string} exit code and output */
function t39eBuild(?string $snapshots = null, string $day = T39E_DAY): array
{
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => $day,
        '--dataset' => t39eFixture('dataset'),
        '--snapshots' => $snapshots ?? t39eFixture('snapshots'),
    ]);

    return [$code, Artisan::output()];
}

/** @param  list<string>|null  $add  remembered roots are removed after the test */
function t39eRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** A writable copy of the fixture day. The root also holds an empty `sub` directory for traversal shapes. */
function t39eCopy(): string
{
    $root = sys_get_temp_dir().'/pp-t39e-'.bin2hex(random_bytes(6));
    $from = t39eFixture('snapshots/'.T39E_DAY);
    $to = $root.'/'.T39E_DAY;
    foreach (['', '/products', '/prices'] as $dir) {
        mkdir($to.$dir, 0775, true);
    }
    mkdir($root.'/sub', 0775, true);
    foreach (['SUCCESS', 'marker.txt', 'groups.json'] as $file) {
        copy("{$from}/{$file}", "{$to}/{$file}");
    }
    foreach (['products', 'prices'] as $dir) {
        foreach (glob("{$from}/{$dir}/*.json.zst") ?: [] as $file) {
            copy($file, "{$to}/{$dir}/".basename($file));
        }
    }
    t39eRoots($root);

    return $root;
}

function t39eRead(string $root, string $kind, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/".T39E_DAY."/{$kind}/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

function t39eWrite(string $root, string $kind, int $group, array $document): void
{
    file_put_contents("{$root}/".T39E_DAY."/{$kind}/{$group}.json.zst", zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Adds a product with the given price rows (subTypeName => marketPrice) to a group of a copied root. */
function t39eAddProduct(string $root, int $group, int $productId, string $name, string $number, array $subtypes): void
{
    $products = t39eRead($root, 'products', $group);
    $products['results'][] = ['productId' => $productId, 'name' => $name, 'cleanName' => $name, 'categoryId' => 3, 'groupId' => $group,
        'extendedData' => [['name' => 'Number', 'displayName' => 'Card Number', 'value' => $number]]];
    $products['totalItems'] = count($products['results']);
    t39eWrite($root, 'products', $group, $products);
    t39eAddPrices($root, $group, $productId, $subtypes);
}

function t39eAddPrices(string $root, int $group, int $productId, array $subtypes): void
{
    $prices = t39eRead($root, 'prices', $group);
    foreach ($subtypes as $subtype => $market) {
        $prices['results'][] = ['productId' => $productId, 'lowPrice' => 1.5, 'midPrice' => 2.5, 'highPrice' => 3.5,
            'marketPrice' => $market, 'directLowPrice' => null, 'subTypeName' => $subtype];
    }
    t39eWrite($root, 'prices', $group, $prices);
}

function t39eRows(string $table, string $order): array
{
    return DB::table($table)->orderBy($order)->get()->map(fn (object $r): array => (array) $r)->all();
}

beforeEach(function (): void {
    $this->seed(SetAliasSeeder::class);
});

afterEach(function (): void {
    foreach (t39eRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('a trainer-kit deck tag that disagrees with the name is never mapped to the other kit or to the named card', function (): void {
    // Plan T39 item 3.4: the deck tag must equal the kit's deck. "Electrike (Latias)" is tk1b's card with tk1a's tag;
    // "Potion (Plusle)" carries a tk2a tag inside the tk1 group. Neither may be guessed onto a kit.
    $root = t39eCopy();
    $products = t39eRead($root, 'products', 1543);
    foreach ($products['results'] as $i => $p) {
        $products['results'][$i]['name'] = match ($p['productId']) {
            85131 => 'Electrike (Latias)',
            88335 => 'Potion (Plusle)',
            default => $p['name'],
        };
    }
    t39eWrite($root, 'products', 1543, $products);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    expect(DB::table('source_product_map')->whereIn('source_product_id', [85131, 88335])->count())->toBe(0);
    expect(DB::table('unmapped_source_items')->whereIn('source_product_id', [85131, 88335])->orderBy('source_product_id')
        ->pluck('reason', 'source_product_id')->all())->toBe([85131 => 'name_disagreement', 88335 => 'name_disagreement']);
    // The properly tagged Latios potion still lands on tk1b-8 and nothing else lands on tk1a-8 or tk1b-1.
    expect(DB::table('source_product_map')->where('source_group_id', 1543)->orderBy('variant_key')->pluck('source_product_id', 'variant_key')->all())
        ->toBe(['tk1a-1:normal:-:-' => 83694, 'tk1b-8:normal:-:-' => 88336]);
});

test('flipping a key back to its earlier product keeps counting epochs and never clashes with history', function (): void {
    // R131: series_epoch is max(history)+1 per (variant_key, source); history is unique on (variant_key, source, epoch).
    [$first] = t39eBuild();
    $moved = t39eCopy();
    $products = t39eRead($moved, 'products', 22872);
    $prices = t39eRead($moved, 'prices', 22872);
    foreach ($products['results'] as $i => $p) {
        $products['results'][$i]['productId'] = $p['productId'] === 517271 ? 599001 : $p['productId'];
    }
    foreach ($prices['results'] as $i => $r) {
        $prices['results'][$i]['productId'] = $r['productId'] === 517271 ? 599001 : $r['productId'];
    }
    t39eWrite($moved, 'products', 22872, $products);
    t39eWrite($moved, 'prices', 22872, $prices);

    [$second] = t39eBuild($moved);
    [$third, $output] = t39eBuild();

    expect([$first, $second, $third])->toBe([0, 0, 0], $output);
    $now = DB::table('source_product_map')->where('variant_key', 'svp-46:holo:-:-')->sole();
    expect([(int) $now->source_product_id, (int) $now->series_epoch])->toBe([517271, 3]);
    expect(DB::table('source_product_map_history')->where('variant_key', 'svp-46:holo:-:-')->orderBy('series_epoch')
        ->get()->map(fn (object $r): array => [(int) $r->source_product_id, (int) $r->series_epoch])->all())
        ->toBe([[517271, 1], [599001, 2]]);
    expect(DB::table('source_product_map_history')->count())->toBe(2);
});

test('a variant whose product disappears from the day keeps its row, its variant and its epoch', function (): void {
    t39eBuild();
    $map = t39eRows('source_product_map', 'id');
    $variants = t39eRows('card_variants', 'variant_key');

    $root = t39eCopy();
    $products = t39eRead($root, 'products', 22872);
    $products['results'] = array_values(array_filter($products['results'], fn (array $p): bool => $p['productId'] !== 517271));
    $products['totalItems'] = count($products['results']);
    $prices = t39eRead($root, 'prices', 22872);
    $prices['results'] = array_values(array_filter($prices['results'], fn (array $r): bool => $r['productId'] !== 517271));
    t39eWrite($root, 'products', 22872, $products);
    t39eWrite($root, 'prices', 22872, $prices);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    expect(DB::table('source_product_map_history')->count())->toBe(0);
    expect(t39eRows('source_product_map', 'id'))->toBe($map);
    expect(t39eRows('card_variants', 'variant_key'))->toBe($variants);
    $counts = json_decode((string) DB::table('map_builds')->orderByDesc('id')->value('counts'), true);
    expect([$counts['variants'], $counts['stale_kept']])->toBe([21, 1]);
});

test('a price row whose product is not in the products file creates no variant and no mapping', function (): void {
    $root = t39eCopy();
    t39eAddPrices($root, 604, 777777, ['Holofoil' => 12.34, 'Normal' => null]);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    expect(DB::table('source_product_map')->where('source_product_id', 777777)->count())->toBe(0);
    expect(DB::table('unmapped_source_items')->where('source_product_id', 777777)->count())->toBe(0);
    expect(DB::table('source_product_map')->count())->toBe(22);
    expect(DB::table('card_variants')->count())->toBe(22);
    $counts = json_decode((string) DB::table('map_builds')->value('counts'), true);
    expect($counts['orphan_price_rows'])->toBe(2);
});

test('the order of rows inside the day files cannot flip which product wins a duplicate key', function (): void {
    // Two Eevee products collide on sv8pt5-7:normal:-:-. Reversing every results list must not change the winner,
    // and rebuilding over the earlier order must write no history (no epoch churn).
    $root = t39eCopy();
    foreach (['products', 'prices'] as $kind) {
        foreach (array_map('intval', array_map(fn (string $f): string => basename($f, '.json.zst'), glob("{$root}/".T39E_DAY."/{$kind}/*.json.zst") ?: [])) as $group) {
            $doc = t39eRead($root, $kind, $group);
            $doc['results'] = array_reverse($doc['results']);
            t39eWrite($root, $kind, $group, $doc);
        }
    }

    [$reversed, $output] = t39eBuild($root);
    $map = DB::table('source_product_map')->orderBy('variant_key')->pluck('source_product_id', 'variant_key')->all();
    $dup = DB::table('unmapped_source_items')->where('reason', 'duplicate_target')->pluck('source_product_id')->map(fn ($v): int => (int) $v)->all();
    $rows = t39eRows('source_product_map', 'id');
    [$natural] = t39eBuild();

    expect([$reversed, $natural])->toBe([0, 0], $output);
    expect($map['sv8pt5-7:normal:-:-'])->toBe(610380);
    expect($dup)->toBe([610381]);
    expect(DB::table('source_product_map_history')->count())->toBe(0);
    expect(t39eRows('source_product_map', 'id'))->toBe($rows);
});

test('number_rule 8 on the base1 deck-exclusives row routes only the number 8, nothing that merely contains it', function (): void {
    $root = t39eCopy();
    t39eAddProduct($root, 1840, 700001, 'Machamp - 18/102', '018/102', ['1st Edition Holofoil' => 1.0]);
    t39eAddProduct($root, 1840, 700002, 'Machamp - 80/102', '080/102', ['1st Edition Holofoil' => 1.0]);
    t39eAddProduct($root, 1840, 700003, 'Machamp - 8a/102', '8a/102', ['1st Edition Holofoil' => 1.0]);
    t39eAddProduct($root, 1840, 700004, 'Machamp - 8/102', '8/102', ['1st Edition' => 1.0]);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    expect(DB::table('unmapped_source_items')->whereIn('source_product_id', [700001, 700002, 700003])->orderBy('source_product_id')
        ->pluck('reason', 'source_product_id')->all())->toBe([700001 => 'no_set_alias', 700002 => 'no_set_alias', 700003 => 'no_set_alias']);
    expect(DB::table('source_product_map')->whereIn('source_product_id', [700001, 700002, 700003])->count())->toBe(0);
    // An unpadded "8/102" still reaches base1-8 (the rule compares the normalised number).
    expect(DB::table('source_product_map')->where('source_product_id', 700004)->value('variant_key'))->toBe('base1-8:normal:1st:-');
});

test('the subtype matrix: Normal in 604 is unlimited, 1st/Unlimited in 1663 are 1st/shadowless, a ball pattern labelled Normal is reverse', function (): void {
    $root = t39eCopy();
    t39eAddPrices($root, 604, 42382, ['Normal' => null]);
    t39eAddPrices($root, 1663, 106999, ['1st Edition' => 9.0, 'Unlimited' => null]);
    t39eAddProduct($root, 23821, 610777, 'Leafeon - 005/131 (Quick Ball Pattern)', '005/131', ['Normal' => 1.0]);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    $keys = DB::table('source_product_map')->whereIn('variant_key', [
        'base1-4:normal:unlimited:-', 'base1-4:normal:1st:-', 'base1-4:normal:shadowless:-', 'sv8pt5-5:reverse:-:quickball',
    ])->orderBy('variant_key')->get()->map(fn (object $r): array => [$r->variant_key, (int) $r->source_group_id, (int) $r->source_product_id, $r->source_subtype])->all();
    expect($keys)->toBe([
        ['base1-4:normal:1st:-', 1663, 106999, '1st Edition'],
        ['base1-4:normal:shadowless:-', 1663, 106999, 'Unlimited'],
        ['base1-4:normal:unlimited:-', 604, 42382, 'Normal'],
        ['sv8pt5-5:reverse:-:quickball', 23821, 610777, 'Normal'],
    ]);
});

test('truncated or wrongly shaped JSON never builds anything, whatever zstd hands back', function (string $kind, int $group, string $plain): void {
    $root = t39eCopy();
    file_put_contents("{$root}/".T39E_DAY."/{$kind}/{$group}.json.zst", zstd_compress($plain));

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(1, $output);
    expect($output)->toContain((string) $group);
    foreach (['map_builds', 'card_variants', 'source_product_map', 'unmapped_source_items'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
})->with([
    'trailing garbage' => ['products', 23821, '{"success":true,"errors":[],"results":[]}{"x"'],
    'results is an object' => ['products', 23821, '{"success":true,"errors":[],"results":{"a":{"productId":1,"name":"x"}}}'],
    'results is null' => ['prices', 23821, '{"success":true,"errors":[],"results":null}'],
    'success false' => ['prices', 23821, '{"success":false,"errors":["boom"],"results":[]}'],
    'cut mid-array' => ['prices', 23821, '{"success":true,"errors":[],"results":[{"productId":610360,"subTypeName":"Holofoil"},{"productId":6'],
    'productId as string' => ['products', 23821, '{"success":true,"errors":[],"results":[{"productId":"610360","name":"Leafeon"}]}'],
    'price row without subtype' => ['prices', 23821, '{"success":true,"errors":[],"results":[{"productId":610360}]}'],
]);

test('a groups.json cut mid-document is refused and writes nothing', function (): void {
    $root = t39eCopy();
    $path = "{$root}/".T39E_DAY.'/groups.json';
    file_put_contents($path, substr((string) file_get_contents($path), 0, 120));

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(1, $output);
    expect(DB::table('map_builds')->count())->toBe(0);
});

test('a --day that is not a plain calendar date is refused even when it would resolve to a real day', function (string $day): void {
    $root = t39eCopy();

    $code = Artisan::call('pokeprices:map:build', ['--day' => $day, '--dataset' => t39eFixture('dataset'), '--snapshots' => $root.'/sub']);

    expect($code)->toBe(1, Artisan::output());
    expect(DB::table('map_builds')->count())->toBe(0);
    expect(DB::table('source_product_map')->count())->toBe(0);
})->with([
    'parent traversal' => '../'.T39E_DAY,
    'embedded traversal' => T39E_DAY.'/../'.T39E_DAY,
    'absolute path' => '/etc',
    'impossible date' => '2026-02-30',
    'compact' => '20261011',
]);

test('integer and large prices in the day never reach any column, counts or note included', function (): void {
    $root = t39eCopy();
    $prices = t39eRead($root, 'prices', 604);
    foreach ($prices['results'] as $i => $r) {
        $prices['results'][$i]['lowPrice'] = 8675309;
        $prices['results'][$i]['midPrice'] = 31337.5;
        $prices['results'][$i]['highPrice'] = 2718281;
        $prices['results'][$i]['marketPrice'] = 424242.42;
    }
    t39eWrite($root, 'prices', 604, $prices);

    [$code, $output] = t39eBuild($root);

    expect($code)->toBe(0, $output);
    $dump = json_encode(array_map(fn (string $t): array => t39eRows($t, $t === 'card_variants' ? 'variant_key' : 'id'),
        ['card_variants', 'source_product_map', 'source_product_map_history', 'map_builds', 'unmapped_source_items']), JSON_THROW_ON_ERROR);
    expect($dump)->toContain('base1-4:holo:unlimited:-');
    foreach (['8675309', '31337', '2718281', '424242'] as $sentinel) {
        expect(str_contains($dump, $sentinel))->toBeFalse();
    }
    expect($output)->not->toContain('424242');
});
