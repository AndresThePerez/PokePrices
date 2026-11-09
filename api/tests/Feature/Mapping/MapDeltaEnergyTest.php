<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A price-feed product named `Delta <name> Energy` is the dataset card `δ <name> Energy`. The temp day and dataset
// below copy the three real products and cards (EX Holon Phantoms, EX Dragon Frontiers, POP Series 5) plus one plain
// Rainbow Energy, so the shared fixture tree stays untouched.

/** @param array<string, string> $files relative path => bytes */
function deltaEnergyTree(array $files): string
{
    $root = sys_get_temp_dir().'/pp-delta-energy-'.bin2hex(random_bytes(6));
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

/** @return array{string, string} the snapshots root and the dataset directory */
function deltaEnergyInputs(): array
{
    $json = fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $zst = fn (mixed $v): string => (string) zstd_compress($json($v), 3);
    $products = [
        1379 => [[84754, 'Delta Rainbow Energy', '098/110'], [990001, 'Rainbow Energy', '099/110']],
        1411 => [[84755, 'Delta Rainbow Energy', '088/101']],
        1439 => [[84756, 'Delta Rainbow Energy', '009/017']],
    ];
    $names = [1379 => 'EX Holon Phantoms', 1411 => 'EX Dragon Frontiers', 1439 => 'POP Series 5'];

    $day = ['2026-10-11/SUCCESS' => '', '2026-10-11/marker.txt' => "2026-10-11T20:05:45+0000\n"];
    $groups = [];
    foreach ($products as $groupId => $rows) {
        $groups[] = ['groupId' => $groupId, 'name' => $names[$groupId], 'abbreviation' => 'FX', 'isSupplemental' => false,
            'publishedOn' => '2026-01-01T00:00:00', 'modifiedOn' => '2026-10-01T00:00:00', 'categoryId' => 3];
        $productRows = [];
        $priceRows = [];
        foreach ($rows as [$id, $name, $number]) {
            $productRows[] = ['productId' => $id, 'name' => $name, 'cleanName' => $name, 'categoryId' => 3, 'groupId' => $groupId,
                'extendedData' => [['name' => 'Number', 'displayName' => 'Card Number', 'value' => $number]]];
            $priceRows[] = ['productId' => $id, 'lowPrice' => 1.11, 'midPrice' => 2.22, 'highPrice' => 3.33, 'marketPrice' => 2.5,
                'directLowPrice' => null, 'subTypeName' => 'Normal'];
        }
        $day["2026-10-11/products/{$groupId}.json.zst"] = $zst(['totalItems' => count($productRows), 'success' => true, 'errors' => [], 'results' => $productRows]);
        $day["2026-10-11/prices/{$groupId}.json.zst"] = $zst(['success' => true, 'errors' => [], 'results' => $priceRows]);
    }
    $day['2026-10-11/groups.json'] = $json(['totalItems' => count($groups), 'success' => true, 'errors' => [], 'results' => $groups]);

    $card = fn (string $id, string $name, string $number): array => ['id' => $id, 'name' => $name, 'supertype' => 'Energy', 'number' => $number];
    $dataset = deltaEnergyTree([
        'sets/en.json' => $json([['id' => 'ex13', 'name' => 'Holon Phantoms'], ['id' => 'ex15', 'name' => 'Dragon Frontiers'], ['id' => 'pop5', 'name' => 'POP Series 5']]),
        'cards/en/ex13.json' => $json([$card('ex13-98', 'δ Rainbow Energy', '98'), $card('ex13-99', 'Rainbow Energy', '99')]),
        'cards/en/ex15.json' => $json([$card('ex15-88', 'δ Rainbow Energy', '88')]),
        'cards/en/pop5.json' => $json([$card('pop5-9', 'δ Rainbow Energy', '9')]),
        'SOURCE.json' => $json(['sha' => str_repeat('0', 40)]),
    ]);

    return [deltaEnergyTree($day), $dataset];
}

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
});

test('a build maps each Delta Rainbow Energy product to its δ Rainbow Energy card and the audit finds no name disagreement', function (): void {
    [$snapshots, $dataset] = deltaEnergyInputs();

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    $map = DB::table('source_product_map')->pluck('source_product_id', 'variant_key')->map(fn (mixed $id): int => (int) $id)->all();
    $unmapped = DB::table('unmapped_source_items')->pluck('reason', 'source_product_id')->all();

    expect($map['ex13-98:normal:-:-'] ?? null)->toBe(84754)
        ->and($map['ex15-88:normal:-:-'] ?? null)->toBe(84755)
        ->and($map['pop5-9:normal:-:-'] ?? null)->toBe(84756)
        ->and($map['ex13-99:normal:-:-'] ?? null)->toBe(990001)
        ->and(array_keys($map, 84754, true))->toBe(['ex13-98:normal:-:-'])
        ->and(array_keys($map, 84755, true))->toBe(['ex15-88:normal:-:-'])
        ->and(array_keys($map, 990001, true))->toBe(['ex13-99:normal:-:-'])
        ->and($unmapped)->toBe([]);

    $out = $dataset.'/names.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'names', '--json' => true, '--out' => $out, '--dataset' => $dataset]);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(4);
});
