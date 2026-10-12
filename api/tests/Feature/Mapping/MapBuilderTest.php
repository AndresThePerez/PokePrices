<?php

declare(strict_types=1);

use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// pokeprices:map:build over the synthetic TCGCSV day in tests/Fixtures/tcgcsv (plan T39). The set_aliases rows
// come from the real CSV (SetAliasSeeder), so the fixture groups are joined exactly as the real day would be.
// The fixture snapshot is only ever read; tests that need a broken or changed day copy it to a temp directory.

const T39_DAY = '2026-10-11';

const T39_MAP_TABLES = ['set_aliases', 'card_variants', 'source_product_map', 'source_product_map_history', 'map_builds', 'unmapped_source_items'];

function t39Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv'.($path === '' ? '' : '/'.$path));
}

/** Runs the command and returns [exit code, output]. */
function t39Build(?string $snapshots = null, array $extra = []): array
{
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => T39_DAY,
        '--dataset' => t39Fixture('dataset'),
        '--snapshots' => $snapshots ?? t39Fixture('snapshots'),
        ...$extra,
    ]);

    return [$code, Artisan::output()];
}

/** A writable copy of the fixture snapshots root; removed after the test. */
function t39CopySnapshots(): string
{
    $root = sys_get_temp_dir().'/pp-t39-'.bin2hex(random_bytes(6));
    $from = t39Fixture('snapshots/'.T39_DAY);
    $to = $root.'/'.T39_DAY;
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
    t39TempRoots($root);

    return $root;
}

/** Remembers a temp root when given one; without an argument, returns the remembered roots and forgets them. */
function t39TempRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Decodes one group file of a snapshots root. */
function t39Read(string $root, string $kind, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/".T39_DAY."/{$kind}/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

function t39Write(string $root, string $kind, int $group, array $document): void
{
    file_put_contents("{$root}/".T39_DAY."/{$kind}/{$group}.json.zst", zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Renames product $from to $to (same name, number and price rows) in group $group of a copied root. */
function t39RenumberProduct(string $root, int $group, int $from, int $to, ?string $name = null): void
{
    $products = t39Read($root, 'products', $group);
    foreach ($products['results'] as $i => $p) {
        if ($p['productId'] === $from) {
            $products['results'][$i]['productId'] = $to;
            $products['results'][$i]['name'] = $name ?? $p['name'];
        }
    }
    t39Write($root, 'products', $group, $products);
    $prices = t39Read($root, 'prices', $group);
    foreach ($prices['results'] as $i => $r) {
        if ($r['productId'] === $from) {
            $prices['results'][$i]['productId'] = $to;
        }
    }
    t39Write($root, 'prices', $group, $prices);
}

/** Every current mapping as [variant_key, group, product, subtype, method], ordered by variant_key. */
function t39Mappings(): array
{
    return DB::table('source_product_map')->orderBy('variant_key')->get()
        ->map(fn (object $r): array => [$r->variant_key, (int) $r->source_group_id, (int) $r->source_product_id, $r->source_subtype, $r->method])
        ->all();
}

/** Every row of $table as an array, ordered by $order. */
function t39Rows(string $table, string $order): array
{
    return DB::table($table)->orderBy($order)->get()->map(fn (object $r): array => (array) $r)->all();
}

/** Every unmapped item of the newest build as [product, subtype, reason], ordered by product and subtype. */
function t39Unmapped(): array
{
    $build = DB::table('map_builds')->max('id');

    return DB::table('unmapped_source_items')->where('map_build_id', $build)
        ->orderBy('source_product_id')->orderBy('source_subtype')->get()
        ->map(fn (object $r): array => [(int) $r->source_product_id, $r->source_subtype, $r->reason])
        ->all();
}

/** Every file under $dir as relative path => [sha1, mtime, size]. */
function t39Tree(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = (string) $file->getPathname();
        $out[substr($path, strlen($dir))] = [sha1_file($path), filemtime($path), filesize($path)];
    }
    ksort($out);

    return $out;
}

const T39_EXPECTED_MAP = [
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

const T39_EXPECTED_UNMAPPED = [
    [88337, 'Normal', 'ambiguous'],
    [125073, 'Holofoil', 'no_set_alias'],
    [610370, 'Holofoil', 'name_disagreement'],
    [610381, 'Normal', 'duplicate_target'],
    [610390, 'Holofoil', 'unknown_tag'],
    [610399, 'Normal', 'no_number_match'],
    [610900, 'Normal', 'non_card'],
    [999001, 'Normal', 'no_set_alias'],
];

beforeEach(function (): void {
    $this->seed(SetAliasSeeder::class);
});

afterEach(function (): void {
    foreach (t39TempRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('the build maps every fixture price row to its variant by the join order and subtype rules', function (): void {
    [$code, $output] = t39Build();

    expect($code)->toBe(0, $output);
    // Unown ! and ? stay apart; the pattern products labelled Holofoil become reverse with their ball treatment;
    // 604 is unlimited, 1663 Unlimited is shadowless; Machamp follows OQ6; the svp stamp pair follows OQ7; the
    // trainer kits are split by name and by the deck tag.
    expect(t39Mappings())->toBe(T39_EXPECTED_MAP);
    expect(DB::table('source_product_map')->where('name_agreement', false)->count())->toBe(0);
    expect(DB::table('source_product_map')->distinct()->pluck('series_epoch')->all())->toBe([1]);
});

test('every price row that is not mapped is an unmapped item with its reason, and none is conflict_same_key', function (): void {
    [$code] = t39Build();

    expect($code)->toBe(0);
    expect(t39Unmapped())->toBe(T39_EXPECTED_UNMAPPED);
    expect(DB::table('unmapped_source_items')->where('reason', 'like', '%conflict%')->count())->toBe(0);
    $ambiguous = DB::table('unmapped_source_items')->where('reason', 'ambiguous')->value('candidates');
    expect(json_decode((string) $ambiguous, true))->toBe(['tk1a-8', 'tk1b-8']);
    $duplicate = DB::table('unmapped_source_items')->where('reason', 'duplicate_target')->value('candidates');
    expect(json_decode((string) $duplicate, true))->toBe(['sv8pt5-7']);
});

test('a variant row exists if and only if a mapped price row exists, a null market price included', function (): void {
    t39Build();

    $variants = DB::table('card_variants')->orderBy('variant_key')->pluck('variant_key')->all();
    expect($variants)->toBe(array_column(T39_EXPECTED_MAP, 0));
    // 1663/106999 1st Edition Holofoil has a null marketPrice and still has its variant.
    expect($variants)->toContain('base1-4:holo:1st:-');
    // Unown (A) is in the products file but has no price row: no variant.
    expect(DB::table('card_variants')->where('card_id', 'ex10-A')->count())->toBe(0);
    $row = DB::table('card_variants')->where('variant_key', 'sv8pt5-5:reverse:-:pokeball')->first();
    expect([$row->card_id, $row->finish, $row->edition, $row->treatment, $row->catalog_status])
        ->toBe(['sv8pt5-5', 'reverse', '-', 'pokeball', 'unverified']);
});

test('map_builds records the day, the dataset and the counts', function (): void {
    t39Build();

    $build = DB::table('map_builds')->sole();
    expect($build->snapshot_day)->toBe(T39_DAY);
    expect($build->dataset_ref)->toBe(str_repeat('0', 40));
    expect($build->finished_at)->not->toBeNull();
    $counts = json_decode((string) $build->counts, true);
    expect([
        $counts['cards'], $counts['variants'], $counts['matched'], $counts['ambiguous'], $counts['unmatched'],
    ])->toBe([18, 22, 14, 2, 4]);
    // jsonb keeps its own key order; compare the reasons sorted.
    $byReason = $counts['by_reason'];
    ksort($byReason);
    expect($byReason)->toBe([
        'ambiguous' => 1,
        'duplicate_target' => 1,
        'name_disagreement' => 1,
        'no_number_match' => 1,
        'no_set_alias' => 2,
        'non_card' => 1,
        'unknown_tag' => 1,
    ]);
    expect($counts['unlisted_sets'])->toBe(['fut20']);
    expect(DB::table('unmapped_source_items')->where('map_build_id', $build->id)->count())->toBe(8);
    expect(DB::table('source_product_map')->where('map_build_id', $build->id)->count())->toBe(22);
});

test('no price column exists and no price value from the day is written anywhere', function (): void {
    t39Build();

    $priceColumns = DB::table('information_schema.columns')->where('table_schema', 'public')
        ->where('column_name', 'ilike', '%price%')->pluck('column_name')->all();
    expect($priceColumns)->toBe([]);

    $values = [];
    foreach (glob(t39Fixture('snapshots/'.T39_DAY.'/prices/*.json.zst')) ?: [] as $file) {
        $doc = json_decode((string) zstd_uncompress((string) file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
        foreach ($doc['results'] as $row) {
            foreach (['lowPrice', 'midPrice', 'highPrice', 'marketPrice', 'directLowPrice'] as $field) {
                if ($row[$field] !== null) {
                    $values[] = number_format((float) $row[$field], 2, '.', '');
                }
            }
        }
    }
    expect(count($values))->toBeGreaterThan(60);

    $dump = json_encode(array_map(fn (string $t): array => t39Rows($t, $t === 'card_variants' ? 'variant_key' : 'id'), T39_MAP_TABLES), JSON_THROW_ON_ERROR);
    expect($dump)->toContain('sv8pt5-5:reverse:-:pokeball');
    $found = array_values(array_filter($values, fn (string $v): bool => str_contains($dump, $v) || str_contains($dump, rtrim(rtrim($v, '0'), '.'))));
    expect($found)->toBe([]);
});

test('the snapshot day is only read: no file is written, changed or removed', function (): void {
    $before = t39Tree(t39Fixture('snapshots'));

    [$code] = t39Build();
    clearstatcache();

    expect($code)->toBe(0);
    expect(DB::table('source_product_map')->count())->toBe(22);
    expect(t39Tree(t39Fixture('snapshots')))->toBe($before);
});

test('with basep\'s alias row absent its products fall to no_set_alias and nothing maps basep to 1840', function (): void {
    DB::table('set_aliases')->where('pokesearch_set_id', 'basep')->delete();

    [$code] = t39Build();

    expect($code)->toBe(0);
    expect(DB::table('source_product_map')->where('variant_key', 'like', 'basep-%')->count())->toBe(0);
    expect(DB::table('card_variants')->where('card_id', 'like', 'basep-%')->count())->toBe(0);
    expect(DB::table('unmapped_source_items')->whereIn('source_product_id', [85000, 85001, 125073])
        ->orderBy('source_product_id')->pluck('reason')->all())->toBe(['no_set_alias', 'no_set_alias', 'no_set_alias']);
    // Machamp 008 shares number 8 with basep-8 Mew; it maps to base1-8 only.
    expect(DB::table('source_product_map')->where('source_group_id', 1840)->orderBy('variant_key')->pluck('variant_key')->all())
        ->toBe(['base1-8:holo:1st:-', 'base1-8:holo:1st:shadowless']);
    $counts = json_decode((string) DB::table('map_builds')->value('counts'), true);
    expect($counts['unlisted_sets'])->toBe(['basep', 'fut20']);
});

test('the build refuses when SUCCESS is absent and writes nothing', function (): void {
    $root = t39CopySnapshots();
    unlink($root.'/'.T39_DAY.'/SUCCESS');

    [$code, $output] = t39Build($root);

    expect($code)->toBe(1);
    expect($output)->toContain('SUCCESS');
    expect(DB::table('map_builds')->count())->toBe(0);
    expect(DB::table('card_variants')->count())->toBe(0);
});

test('the build refuses when the marker is unreadable', function (string $marker): void {
    $root = t39CopySnapshots();
    $path = $root.'/'.T39_DAY.'/marker.txt';
    $marker === '<missing>' ? unlink($path) : file_put_contents($path, $marker);

    [$code, $output] = t39Build($root);

    expect($code)->toBe(1);
    expect($output)->toContain('marker');
    expect(DB::table('map_builds')->count())->toBe(0);
})->with([
    'missing' => '<missing>',
    'empty' => '',
    'not a timestamp' => "yesterday-ish\n",
]);

test('the build refuses a zstd file that does not decode to a whole JSON document, and writes nothing', function (string $kind, string $how): void {
    $root = t39CopySnapshots();
    $path = "{$root}/".T39_DAY."/{$kind}/23821.json.zst";
    $bytes = (string) file_get_contents($path);
    file_put_contents($path, match ($how) {
        'cut in half' => substr($bytes, 0, intdiv(strlen($bytes), 2)),
        'magic only' => "\x28\xb5\x2f\xfd",
        'not zstd' => '{"success": true, "results": []}',
        'no results' => (string) zstd_compress('{"success": true, "errors": []}'),
        'empty file' => '',
        'totalItems mismatch' => (string) zstd_compress('{"totalItems": 9, "success": true, "errors": [], "results": []}'),
    });

    [$code, $output] = t39Build($root);

    expect($code)->toBe(1);
    expect($output)->toContain('23821');
    expect(DB::table('map_builds')->count())->toBe(0);
    expect(DB::table('source_product_map')->count())->toBe(0);
})->with([
    ['products', 'cut in half'],
    ['products', 'magic only'],
    ['prices', 'not zstd'],
    ['prices', 'no results'],
    ['prices', 'empty file'],
    ['products', 'totalItems mismatch'],
]);

test('a group whose files say in full that it has no products is built as empty', function (): void {
    $root = t39CopySnapshots();
    // The real day writes empty groups without totalItems.
    t39Write($root, 'products', 99001, ['success' => true, 'errors' => [], 'results' => []]);
    t39Write($root, 'prices', 99001, ['success' => true, 'errors' => [], 'results' => []]);

    [$code, $output] = t39Build($root);

    expect($code)->toBe(0, $output);
    $counts = json_decode((string) DB::table('map_builds')->value('counts'), true);
    expect($counts['empty_groups'])->toBe([99001]);
    expect(DB::table('unmapped_source_items')->where('source_product_id', 999001)->count())->toBe(0);
    expect(t39Mappings())->toBe(T39_EXPECTED_MAP);
});

test('the build refuses when a group in groups.json has no products or prices file', function (): void {
    $root = t39CopySnapshots();
    unlink($root.'/'.T39_DAY.'/prices/1398.json.zst');

    [$code, $output] = t39Build($root);

    expect($code)->toBe(1);
    expect($output)->toContain('1398');
    expect(DB::table('map_builds')->count())->toBe(0);
});

test('the build refuses when set_aliases is empty', function (): void {
    DB::table('set_aliases')->delete();

    [$code, $output] = t39Build();

    expect($code)->toBe(1);
    expect($output)->toContain('set_aliases');
    expect(DB::table('map_builds')->count())->toBe(0);
});

test('without --snapshots the command reads the configured snapshots root', function (): void {
    config(['services.tcgcsv.snapshots' => t39Fixture('snapshots')]);

    $code = Artisan::call('pokeprices:map:build', ['--day' => T39_DAY, '--dataset' => t39Fixture('dataset')]);

    expect($code)->toBe(0, Artisan::output());
    expect(t39Mappings())->toBe(T39_EXPECTED_MAP);
});

test('--connection selects the database connection and an unknown name is refused', function (): void {
    [$ok] = t39Build(null, ['--connection' => 'pgsql']);
    [$bad, $output] = t39Build(null, ['--connection' => 'no-such-connection']);

    expect($ok)->toBe(0);
    expect($bad)->toBe(1);
    expect($output)->toContain('no-such-connection');
    expect(DB::table('map_builds')->count())->toBe(1);
});

test('a rebuild with no change writes no history and changes no row', function (): void {
    t39Build();
    $map = t39Rows('source_product_map', 'id');
    $variants = t39Rows('card_variants', 'variant_key');

    [$code] = t39Build();

    expect($code)->toBe(0);
    expect(DB::table('source_product_map_history')->count())->toBe(0);
    expect(t39Rows('source_product_map', 'id'))->toBe($map);
    expect(t39Rows('card_variants', 'variant_key'))->toBe($variants);
    expect(DB::table('map_builds')->count())->toBe(2);
});

test('a rebuild that changes the product of a key moves the old row to history and starts epoch 2', function (): void {
    t39Build();
    $first = (int) DB::table('map_builds')->max('id');
    $before = t39Rows('source_product_map', 'id');
    $old = DB::table('source_product_map')->where('variant_key', 'svp-46:holo:-:-')->sole();

    $root = t39CopySnapshots();
    t39RenumberProduct($root, 22872, 517271, 599001);
    [$code] = t39Build($root);
    $second = (int) DB::table('map_builds')->max('id');

    expect($code)->toBe(0);
    $history = DB::table('source_product_map_history')->sole();
    expect([$history->variant_key, (int) $history->source_product_id, $history->source_subtype, (int) $history->series_epoch, (int) $history->map_build_id])
        ->toBe(['svp-46:holo:-:-', 517271, 'Holofoil', 1, $first]);
    expect($history->ended_at)->not->toBeNull();
    expect($history->end_reason)->toBe('remapped');
    expect([$history->method, $history->source_product_name, $history->source_number, (int) $history->source_group_id])
        ->toBe([$old->method, $old->source_product_name, $old->source_number, (int) $old->source_group_id]);

    $new = DB::table('source_product_map')->where('variant_key', 'svp-46:holo:-:-')->sole();
    expect([(int) $new->source_product_id, (int) $new->series_epoch, (int) $new->map_build_id])->toBe([599001, 2, $second]);

    // No other row changed.
    $others = fn (array $rows): array => array_values(array_filter($rows, fn (array $r): bool => $r['variant_key'] !== 'svp-46:holo:-:-'));
    expect($others(t39Rows('source_product_map', 'id')))->toBe($others($before));

    // The same input again writes no further history.
    $after = t39Rows('source_product_map', 'id');
    t39Build($root);
    expect(DB::table('source_product_map_history')->count())->toBe(1);
    expect(t39Rows('source_product_map', 'id'))->toBe($after);
});

test('a product that moves to another key frees its old key without a unique clash', function (): void {
    t39Build();

    // 610380 becomes a Poke Ball pattern product: it now maps to sv8pt5-7:reverse:-:pokeball, and its old key
    // sv8pt5-7:normal:-:- passes to 610381, which was the duplicate before.
    $root = t39CopySnapshots();
    t39RenumberProduct($root, 23821, 610380, 610380, 'Eevee - 007/131 (Poke Ball Pattern)');
    [$code, $output] = t39Build($root);

    expect($code)->toBe(0, $output);
    expect(DB::table('source_product_map')->where('variant_key', 'like', 'sv8pt5-7:%')->orderBy('variant_key')->get()
        ->map(fn (object $r): array => [$r->variant_key, (int) $r->source_product_id, (int) $r->series_epoch])->all())
        ->toBe([['sv8pt5-7:normal:-:-', 610381, 2], ['sv8pt5-7:reverse:-:pokeball', 610380, 1]]);
    expect(DB::table('source_product_map_history')->get()
        ->map(fn (object $r): array => [$r->variant_key, (int) $r->source_product_id, (int) $r->series_epoch, $r->end_reason])->all())
        ->toBe([['sv8pt5-7:normal:-:-', 610380, 1, 'remapped']]);
    expect(t39Unmapped())->not->toContain([610381, 'Normal', 'duplicate_target']);
});
