<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Hyphenated -EX/-GX catalogue names and rarity descriptor tags through a whole build. The temp day and dataset copy
// the real 2026-10-11 products (group, product id, name, number, subtype) and catalogue cards (id, name, number,
// series) of Hidden Fates, Flashfire, Evolving Skies and Triumphant, so the shared fixture tree stays untouched.
// The market prices are the day's own; none of them is stored by the build.

/** @param array<string, string> $files relative path => bytes */
function hyphenTagTree(array $files): string
{
    $root = sys_get_temp_dir().'/pp-hyphen-tag-'.bin2hex(random_bytes(6));
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
function hyphenTagInputs(): array
{
    $json = fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $zst = fn (mixed $v): string => (string) zstd_compress($json($v), 3);
    // groupId => [productId, name, Number, subTypeName, marketPrice]
    $products = [
        2480 => [[197651, 'Charizard GX', '9/68', 'Holofoil', 9.57]],
        1464 => [[91245, 'M Charizard EX (Y) (Secret)', '107/106', 'Holofoil', 313.61], [91246, 'M Charizard EX (X) (Secret)', '108/106', 'Holofoil', 1501.23]],
        2848 => [[246720, 'Umbreon VMAX', '095/203', 'Holofoil', 27.68], [246723, 'Umbreon VMAX (Alternate Art Secret)', '215/203', 'Holofoil', 2267.62]],
        1381 => [[83454, 'Absol (Prime)', '91/102', 'Holofoil', 155.04]],
    ];
    $names = [2480 => 'Hidden Fates', 1464 => 'XY - Flashfire', 2848 => 'SWSH07: Evolving Skies', 1381 => 'Triumphant'];

    $day = ['2026-10-11/SUCCESS' => '', '2026-10-11/marker.txt' => "2026-10-11T20:05:45+0000\n"];
    $groups = [];
    foreach ($products as $groupId => $rows) {
        $groups[] = ['groupId' => $groupId, 'name' => $names[$groupId], 'abbreviation' => 'FX', 'isSupplemental' => false,
            'publishedOn' => '2026-01-01T00:00:00', 'modifiedOn' => '2026-10-01T00:00:00', 'categoryId' => 3];
        $productRows = [];
        $priceRows = [];
        foreach ($rows as [$id, $name, $number, $subtype, $market]) {
            $productRows[] = ['productId' => $id, 'name' => $name, 'cleanName' => $name, 'categoryId' => 3, 'groupId' => $groupId,
                'extendedData' => [['name' => 'Number', 'displayName' => 'Card Number', 'value' => $number]]];
            $priceRows[] = ['productId' => $id, 'lowPrice' => null, 'midPrice' => null, 'highPrice' => null, 'marketPrice' => $market,
                'directLowPrice' => null, 'subTypeName' => $subtype];
        }
        $day["2026-10-11/products/{$groupId}.json.zst"] = $zst(['totalItems' => count($productRows), 'success' => true, 'errors' => [], 'results' => $productRows]);
        $day["2026-10-11/prices/{$groupId}.json.zst"] = $zst(['success' => true, 'errors' => [], 'results' => $priceRows]);
    }
    $day['2026-10-11/groups.json'] = $json(['totalItems' => count($groups), 'success' => true, 'errors' => [], 'results' => $groups]);

    $card = fn (string $id, string $name, string $number): array => ['id' => $id, 'name' => $name, 'supertype' => 'Pokémon', 'number' => $number];
    $dataset = hyphenTagTree([
        'sets/en.json' => $json([
            ['id' => 'sm115', 'name' => 'Hidden Fates', 'series' => 'Sun & Moon'],
            ['id' => 'xy2', 'name' => 'Flashfire', 'series' => 'XY'],
            ['id' => 'swsh7', 'name' => 'Evolving Skies', 'series' => 'Sword & Shield'],
            ['id' => 'hgss4', 'name' => 'HS—Triumphant', 'series' => 'HeartGold & SoulSilver'],
        ]),
        'cards/en/sm115.json' => $json([$card('sm115-9', 'Charizard-GX', '9')]),
        'cards/en/xy2.json' => $json([$card('xy2-107', 'M Charizard-EX', '107'), $card('xy2-108', 'M Charizard-EX', '108')]),
        'cards/en/swsh7.json' => $json([$card('swsh7-95', 'Umbreon VMAX', '95'), $card('swsh7-215', 'Umbreon VMAX', '215')]),
        'cards/en/hgss4.json' => $json([$card('hgss4-91', 'Absol', '91')]),
        'SOURCE.json' => $json(['sha' => str_repeat('0', 40)]),
    ]);

    return [hyphenTagTree($day), $dataset];
}

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
});

/** @return array<string, list<int>> card id => the product ids mapped to it */
function hyphenTagProductsByCard(): array
{
    $out = [];
    foreach (DB::table('source_product_map as m')->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')->orderBy('m.source_product_id')->get(['v.card_id', 'm.source_product_id']) as $row) {
        $out[(string) $row->card_id][] = (int) $row->source_product_id;
    }
    ksort($out);

    return $out;
}

test('a build maps each Charizard-GX, -EX, alternate-art secret and Prime card to exactly one product', function (string $card, int $product, string $variant): void {
    [$snapshots, $dataset] = hyphenTagInputs();

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    $byCard = hyphenTagProductsByCard();
    $map = DB::table('source_product_map')->pluck('variant_key', 'source_product_id')->all();

    expect($byCard[$card] ?? [])->toBe([$product])
        ->and($map[$product] ?? null)->toBe($variant);
})->with([
    'a: sm115-9 Charizard-GX' => ['sm115-9', 197651, 'sm115-9:holo:-:-'],
    'b: xy2-108 M Charizard-EX (X) (Secret)' => ['xy2-108', 91246, 'xy2-108:holo:-:-'],
    'b: xy2-107 M Charizard-EX (Y) (Secret)' => ['xy2-107', 91245, 'xy2-107:holo:-:-'],
    'c: swsh7-215 Umbreon VMAX (Alternate Art Secret)' => ['swsh7-215', 246723, 'swsh7-215:holo:-:-'],
    'd: hgss4-91 Absol (Prime)' => ['hgss4-91', 83454, 'hgss4-91:holo:-:-'],
]);

test('the whole temp day maps one product per card, leaves nothing unmapped and the names audit agrees', function (): void {
    [$snapshots, $dataset] = hyphenTagInputs();

    expect(Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => $snapshots]))->toBe(0);

    expect(hyphenTagProductsByCard())->toBe([
        'hgss4-91' => [83454],
        'sm115-9' => [197651],
        'swsh7-215' => [246723],
        'swsh7-95' => [246720],
        'xy2-107' => [91245],
        'xy2-108' => [91246],
    ])
        ->and(DB::table('unmapped_source_items')->count())->toBe(0)
        ->and(DB::table('unmapped_cards')->count())->toBe(0);

    $counts = json_decode((string) DB::table('map_builds')->value('counts'), true, 512, JSON_THROW_ON_ERROR);
    expect($counts['matched'])->toBe(6)
        ->and($counts['unmatched'])->toBe(0)
        ->and($counts['by_reason'])->toBe([]);

    $out = $dataset.'/names.json';
    expect(Artisan::call('pokeprices:map:audit', ['--check' => 'names', '--json' => true, '--out' => $out, '--dataset' => $dataset]))->toBe(0);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    expect($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(6);
});
