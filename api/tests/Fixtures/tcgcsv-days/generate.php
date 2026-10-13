<?php

declare(strict_types=1);

/*
 * Writes the five synthetic TCGCSV days the PokéPrices price-ingest tests read (plan P2-i1 T04 item 7):
 * 2026-10-09, -10, -11, -12 and -14 (no -13: a gap day). Each day reuses the P1 fixture's groups.json and products
 * files byte for byte, and prices the same (productId, subTypeName) pairs, so a map built from the P1 fixture day
 * (tests/Fixtures/tcgcsv) maps them. Every market price is invented and distinctive:
 *
 * - basep-8 (85000) is +12 % and +$3.00 on 10-11 vs 10-10 (25.00 -> 28.00);
 * - svp-46 (517271) is -6 % but only -$0.30 on 10-11 vs 10-10 (5.00 -> 4.70);
 * - sv8pt5-7 (610380) has a null market on 10-12;
 * - basep-13 (85001) is absent on 10-12 and back on 10-14;
 * - ex10-? (90167) is present on 10-09 only;
 * - 10-11's prices/604 file repeats 42382 Holofoil with another price (the first occurrence, 70.81, wins);
 * - base1-4 (42382) closes at 70.81 and tk1a-1 (83694) at 0.07 on 10-11.
 *
 * Markers are 20:05:NN+0000 and each day's manifest.json agrees with its marker, folder and group count.
 * Run inside the api container: php tests/Fixtures/tcgcsv-days/generate.php
 */

$here = __DIR__;
$p1 = dirname(__DIR__).'/tcgcsv/snapshots/2026-10-11';

$markers = [
    '2026-10-09' => '2026-10-09T20:05:41+0000',
    '2026-10-10' => '2026-10-10T20:05:42+0000',
    '2026-10-11' => '2026-10-11T20:05:45+0000',
    '2026-10-12' => '2026-10-12T20:05:44+0000',
    '2026-10-14' => '2026-10-14T20:05:46+0000',
];

// [groupId, productId, subTypeName] => market in cents for 09, 10, 11, 12, 14. null: a null market; false: the row
// is absent that day. Unmapped products of the P1 fixture keep a price so the ingest counts them.
$markets = [
    [604, 42382, 'Holofoil', [6950, 7010, 7081, 7120, 7200]],
    [604, 657516, 'Holofoil', [null, null, null, null, null]],
    [1663, 106999, 'Unlimited Holofoil', [31000, 31250, 31525, 30999, 32000]],
    [1663, 106999, '1st Edition Holofoil', [null, null, null, null, null]],
    [1840, 42425, '1st Edition Holofoil', [4500, 4500, 4500, 4500, 4500]],
    [1840, 107004, '1st Edition Holofoil', [12040, 12100, 11980, 11800, 12500]],
    [1840, 125073, 'Holofoil', [333, 333, 333, 333, 333]],
    [1418, 85000, 'Holofoil', [2450, 2500, 2800, 2800, 2750]],
    [1418, 85001, 'Holofoil', [1200, 1220, 1240, false, 1290]],
    [1398, 90166, 'Holofoil', [880, 890, 900, 910, 920]],
    [1398, 90167, 'Holofoil', [1555, false, false, false, false]],
    [23821, 610360, 'Holofoil', [210, 215, 220, 225, 230]],
    [23821, 610360, 'Reverse Holofoil', [110, 110, 115, 120, 125]],
    [23821, 610540, 'Holofoil', [3000, 3100, 3200, 3300, 3400]],
    [23821, 610641, 'Holofoil', [15000, 14900, 14800, 14700, 14600]],
    [23821, 610370, 'Holofoil', [440, 440, 440, 440, 440]],
    [23821, 610380, 'Normal', [25, 26, 27, null, 29]],
    [23821, 610381, 'Normal', [30, 30, 30, 30, 30]],
    [23821, 610390, 'Holofoil', [5000, 5000, 5000, 5000, 5000]],
    [23821, 610399, 'Normal', [50, 50, 50, 50, 50]],
    [23821, 610900, 'Normal', [null, null, null, null, null]],
    [22872, 487751, 'Holofoil', [300, 305, 310, 315, 320]],
    [22872, 522650, 'Holofoil', [4000, 4100, 4200, 4300, 4400]],
    [22872, 517271, 'Holofoil', [510, 500, 470, 475, 480]],
    [1543, 83694, 'Normal', [7, 7, 7, 8, 7]],
    [1543, 85131, 'Normal', [15, 15, 15, 16, 17]],
    [1543, 88335, 'Normal', [20, 21, 20, 22, 23]],
    [1543, 88336, 'Normal', [22, 22, 23, 24, 25]],
    [1543, 88337, 'Normal', [18, 18, 18, 18, 18]],
    [99001, 999001, 'Normal', [100, 100, 100, 100, 100]],
];

// The repeated row of 10-11's prices/604 file: same key as the first row, another price.
$duplicate = ['2026-10-11', 604, 42382, 'Holofoil', 9999];

$write = function (string $path, string $bytes): void {
    if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException("cannot create the directory of {$path}");
    }
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException("cannot write {$path}");
    }
};
$json = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

// low, mid, high and directLow derive from the market (cents) so every field is a distinct two-decimal number;
// directLow is null for odd product ids. A null market keeps fixed other fields.
$row = function (int $productId, string $subtype, ?int $m): array {
    $cents = fn (?int $c): ?float => $c === null ? null : $c / 100;

    return [
        'productId' => $productId,
        'lowPrice' => $cents($m === null ? 100 : intdiv($m * 90, 100)),
        'midPrice' => $cents($m === null ? 200 : intdiv($m * 105, 100)),
        'highPrice' => $cents($m === null ? 300 : $m * 2),
        'marketPrice' => $cents($m),
        'directLowPrice' => $cents($m === null || $productId % 2 === 1 ? null : intdiv($m * 95, 100)),
        'subTypeName' => $subtype,
    ];
};

$groupsJson = file_get_contents($p1.'/groups.json');
if ($groupsJson === false) {
    throw new RuntimeException("cannot read {$p1}/groups.json");
}
$groupIds = array_map(fn (array $g): int => $g['groupId'], json_decode($groupsJson, true, 512, JSON_THROW_ON_ERROR)['results']);

foreach (array_keys($markers) as $i => $day) {
    $dir = "{$here}/snapshots/{$day}";
    $write($dir.'/SUCCESS', '');
    $write($dir.'/marker.txt', $markers[$day]."\n");
    $write($dir.'/groups.json', $groupsJson);
    $write($dir.'/manifest.json', $json([
        'marker' => $markers[$day],
        'day' => $day,
        'groups' => count($groupIds),
        'collected_at' => $day.'T21:08:06Z',
        'layout' => 'prices/<groupId>.json.zst products/<groupId>.json.zst groups.json',
    ])."\n");
    foreach ($groupIds as $groupId) {
        $products = file_get_contents("{$p1}/products/{$groupId}.json.zst");
        if ($products === false) {
            throw new RuntimeException("cannot read the P1 products file of group {$groupId}");
        }
        $write("{$dir}/products/{$groupId}.json.zst", $products);

        $rows = [];
        foreach ($markets as [$g, $productId, $subtype, $byDay]) {
            if ($g === $groupId && $byDay[$i] !== false) {
                $rows[] = $row($productId, $subtype, $byDay[$i]);
            }
        }
        if ($duplicate[0] === $day && $duplicate[1] === $groupId) {
            $rows[] = $row($duplicate[2], $duplicate[3], $duplicate[4]);
        }
        $zst = zstd_compress($json(['success' => true, 'errors' => [], 'results' => $rows]), 19);
        if ($zst === false) {
            throw new RuntimeException('zstd_compress failed');
        }
        $write("{$dir}/prices/{$groupId}.json.zst", $zst);
    }
}

echo "wrote the synthetic TCGCSV price days under {$here}/snapshots\n";
