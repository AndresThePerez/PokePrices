<?php

declare(strict_types=1);

/*
 * Writes the synthetic TCGCSV day and dataset the map-builder tests read (plan T39 item 7). Every record is made
 * up in the shape of a collected day: products and prices per group as zstd JSON, groups.json, marker.txt and
 * SUCCESS, plus a dataset with sets/en.json and cards/en/<set>.json. Group and product ids follow the real
 * 2026-10-11 day where a rule names them (604, 1663, 1840, 1418, 1398, 23821, 22872, 1543); the prices are
 * invented and distinctive, so a test can prove none of them is ever stored.
 *
 * Run inside the api container: php tests/Fixtures/tcgcsv/generate.php
 */

$root = __DIR__;
$day = $root.'/snapshots/2026-10-11';

$groups = [
    604 => 'Base Set',
    1663 => 'Base Set (Shadowless)',
    1840 => 'Deck Exclusives',
    1418 => 'WoTC Promo',
    1398 => 'EX Unseen Forces',
    23821 => 'SV: Prismatic Evolutions',
    22872 => 'SV: Scarlet & Violet Promo Cards',
    1543 => 'EX Trainer Kit 1: Latias & Latios',
    99001 => 'Fixture Group Without Alias',
];

// [productId, name, Number or null, subTypeNames priced on the day (a null market price is written for the ones
// marked with a trailing '?')]
$products = [
    604 => [
        [42382, 'Charizard', '004/102', ['Holofoil']],
        [657516, 'Charizard (Black Dot Error)', '004/102', ['Holofoil?']],
    ],
    1663 => [
        [106999, 'Charizard', '004/102', ['Unlimited Holofoil', '1st Edition Holofoil?']],
    ],
    1840 => [
        [42425, 'Machamp - 8/102', '008/102', ['1st Edition Holofoil']],
        [107004, 'Machamp - 8/102 (Base Set Shadowless)', '008/102', ['1st Edition Holofoil']],
        [125073, 'Venusaur (DPPt Supreme Victors)', '013/147', ['Holofoil']],
    ],
    1418 => [
        [85000, 'Mew', '8', ['Holofoil']],
        [85001, 'Venusaur', '13', ['Holofoil']],
    ],
    1398 => [
        [90166, 'Unown (!)', '!/28', ['Holofoil']],
        [90167, 'Unown (?)', '?/28', ['Holofoil']],
        [90168, 'Unown (A)', 'A/28', []],
    ],
    23821 => [
        [610360, 'Leafeon - 005/131', '005/131', ['Holofoil', 'Reverse Holofoil']],
        [610540, 'Leafeon - 005/131 (Poke Ball Pattern)', '005/131', ['Holofoil']],
        [610641, 'Leafeon - 005/131 (Master Ball Pattern)', '005/131', ['Holofoil']],
        [610370, 'Glaceon ex - 006/131', '006/131', ['Holofoil']],
        [610380, 'Eevee - 007/131', '007/131', ['Normal']],
        [610381, 'Eevee - 007/131', '007/131', ['Normal']],
        [610390, 'Vaporeon - 008/131 (Jumbo)', '008/131', ['Holofoil']],
        [610399, 'Mystery - 999/131', '999/131', ['Normal']],
        [610900, 'Prismatic Evolutions Booster Bundle', null, ['Normal?']],
    ],
    22872 => [
        [487751, 'Quaquaval - 005 (Prerelease)', '005', ['Holofoil']],
        [522650, 'Quaquaval - 005 (Prerelease) [Staff]', '005', ['Holofoil']],
        [517271, 'Bulbasaur - 046 (Cosmos Holo)', '046', ['Holofoil']],
    ],
    1543 => [
        [83694, 'Bagon', '1/10', ['Normal']],
        [85131, 'Electrike', '1/10', ['Normal']],
        [88335, 'Potion (Latias)', '8/10', ['Normal']],
        [88336, 'Potion (Latios)', '8/10', ['Normal']],
        [88337, 'Potion', '8/10', ['Normal']],
    ],
    99001 => [
        [999001, 'Fixture Card', '1/10', ['Normal']],
    ],
];

$sets = [
    'base1' => ['Base', [['base1-4', 'Charizard', '4'], ['base1-8', 'Machamp', '8']]],
    'basep' => ['Wizards Black Star Promos', [['basep-8', 'Mew', '8'], ['basep-13', 'Venusaur', '13']]],
    'ex10' => ['Unseen Forces', [['ex10-!', 'Unown', '!'], ['ex10-?', 'Unown', '?'], ['ex10-A', 'Unown', 'A']]],
    'sv8pt5' => ['Prismatic Evolutions', [
        ['sv8pt5-5', 'Leafeon', '5'], ['sv8pt5-6', 'Glaceon', '6'], ['sv8pt5-7', 'Eevee', '7'], ['sv8pt5-8', 'Vaporeon', '8'],
    ]],
    'svp' => ['Scarlet & Violet Black Star Promos', [['svp-5', 'Quaquaval', '005'], ['svp-46', 'Bulbasaur', '046']]],
    'tk1a' => ['EX Trainer Kit Latias', [['tk1a-1', 'Bagon', '1'], ['tk1a-8', 'Potion', '8']]],
    'tk1b' => ['EX Trainer Kit Latios', [['tk1b-1', 'Electrike', '1'], ['tk1b-8', 'Potion', '8']]],
    'fut20' => ['Pokémon Futsal Collection', [['fut20-1', 'Futsal Pikachu', '1']]],
];

$write = function (string $path, string $bytes): void {
    if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException("cannot create the directory of {$path}");
    }
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException("cannot write {$path}");
    }
};
$json = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$zst = function (string $plain): string {
    $bytes = zstd_compress($plain, 19);
    if ($bytes === false) {
        throw new RuntimeException('zstd_compress failed');
    }

    return $bytes;
};

$write($day.'/SUCCESS', '');
$write($day.'/marker.txt', "2026-10-11T20:05:45+0000\n");
$groupRows = [];
foreach ($groups as $id => $name) {
    $groupRows[] = ['groupId' => $id, 'name' => $name, 'abbreviation' => 'FX', 'isSupplemental' => false,
        'publishedOn' => '2026-01-01T00:00:00', 'modifiedOn' => '2026-10-01T00:00:00', 'categoryId' => 3];
}
$write($day.'/groups.json', $json(['totalItems' => count($groupRows), 'success' => true, 'errors' => [], 'results' => $groupRows]));

$n = 0;
foreach ($products as $groupId => $rows) {
    $productRows = [];
    $priceRows = [];
    foreach ($rows as [$productId, $name, $number, $subtypes]) {
        $extended = [['name' => 'Rarity', 'displayName' => 'Rarity', 'value' => 'Rare']];
        if ($number !== null) {
            array_unshift($extended, ['name' => 'Number', 'displayName' => 'Card Number', 'value' => $number]);
        }
        $productRows[] = ['productId' => $productId, 'name' => $name, 'cleanName' => $name, 'categoryId' => 3,
            'groupId' => $groupId, 'extendedData' => $extended];
        foreach ($subtypes as $subtype) {
            $n++;
            $nullMarket = str_ends_with($subtype, '?');
            $priceRows[] = [
                'productId' => $productId,
                'lowPrice' => 1000 + $n * 11 + 0.13,
                'midPrice' => 3000 + $n * 13 + 0.17,
                'highPrice' => 5000 + $n * 17 + 0.19,
                'marketPrice' => $nullMarket ? null : 7000 + $n * 19 + 0.23,
                'directLowPrice' => null,
                'subTypeName' => rtrim($subtype, '?'),
            ];
        }
    }
    $write("{$day}/products/{$groupId}.json.zst", $zst($json(['totalItems' => count($productRows), 'success' => true, 'errors' => [], 'results' => $productRows])));
    $write("{$day}/prices/{$groupId}.json.zst", $zst($json(['success' => true, 'errors' => [], 'results' => $priceRows])));
}

$setRows = [];
foreach ($sets as $id => [$name, $cards]) {
    $setRows[] = ['id' => $id, 'name' => $name, 'series' => 'Fixture', 'printedTotal' => count($cards), 'total' => count($cards)];
    $cardRows = array_map(fn (array $c): array => ['id' => $c[0], 'name' => $c[1], 'supertype' => 'Pokémon', 'number' => $c[2]], $cards);
    $write("{$root}/dataset/cards/en/{$id}.json", $json($cardRows));
}
$write($root.'/dataset/sets/en.json', $json($setRows));
$write($root.'/dataset/SOURCE.json', $json(['sha' => str_repeat('0', 40), 'note' => 'synthetic dataset for the T39 tests']));

echo "wrote the synthetic TCGCSV day and dataset under {$root}\n";
