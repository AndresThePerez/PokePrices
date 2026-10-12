<?php

declare(strict_types=1);

// The total, explicit PokéSearch set → TCGplayer group table (plan T37, adversary rec 1). These tests read the
// committed CSV only. Totality against the dataset at run time is DC5's `unlisted_sets`, because .data is not in CI.

const T37_CSV = __DIR__.'/../../../database/data/set_aliases.csv';

const T37_HEADER = ['pokesearch_set_id', 'source_group_id', 'role', 'number_rule', 'method', 'unlimited_means', 'note'];

/**
 * The CSV as associative rows, read with PHP's own CSV parser (independent of the seeder's reader).
 *
 * @return array{header: list<string|null>, rows: list<array<string, string>>}
 */
function t37Csv(): array
{
    $lines = file(T37_CSV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('set_aliases.csv is not readable');
    }
    $header = str_getcsv((string) array_shift($lines), ',', '"', '');
    $rows = [];
    foreach ($lines as $line) {
        $cells = str_getcsv($line, ',', '"', '');
        $rows[] = array_combine(array_map('strval', $header), array_map('strval', $cells));
    }

    return ['header' => $header, 'rows' => $rows];
}

/**
 * The rows for one set, keyed by group id, each reduced to [role, number_rule, unlimited_means].
 *
 * @return array<int|string, list<string>>
 */
function t37RowsFor(string $setId): array
{
    $out = [];
    foreach (t37Csv()['rows'] as $row) {
        if ($row['pokesearch_set_id'] === $setId) {
            $out[$row['source_group_id']] = [$row['role'], $row['number_rule'], $row['unlimited_means']];
        }
    }
    ksort($out);

    return $out;
}

test('the header is exactly the seven planned columns, so there is no heuristic column', function (): void {
    expect(t37Csv()['header'])->toBe(T37_HEADER);
});

test('every row has exactly seven cells', function (): void {
    $lines = file(T37_CSV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toBeArray();
    $widths = array_unique(array_map(fn (string $l): int => count(str_getcsv($l, ',', '"', '')), (array) $lines));
    expect(array_values($widths))->toBe([7]);
});

test('the table lists 176 distinct PokéSearch set ids', function (): void {
    $ids = array_unique(array_column(t37Csv()['rows'], 'pokesearch_set_id'));
    expect($ids)->toHaveCount(176);
    foreach ($ids as $id) {
        expect($id)->toMatch('/^[a-z0-9]+$/');
    }
});

test('no (set, group) pair appears twice', function (): void {
    $pairs = array_map(fn (array $r): string => $r['pokesearch_set_id'].'|'.$r['source_group_id'], t37Csv()['rows']);
    expect(array_values(array_unique($pairs)))->toBe($pairs);
});

test('base1 has 604 primary (unlimited), 1663 edition-split (shadowless) and 1840 secondary for number 8, nothing else', function (): void {
    expect(t37RowsFor('base1'))->toBe([
        604 => ['primary', '', 'unlimited'],
        1663 => ['edition-split', '', 'shadowless'],
        1840 => ['secondary', '8', ''],
    ]);
});

test('the required single-group sets map to exactly their planned group', function (string $set, int $group): void {
    expect(t37RowsFor($set))->toBe([$group => ['primary', '', '']]);
})->with([
    'basep → 1418 WoTC Promo, never 1840' => ['basep', 1418],
    'me55 → 24722' => ['me55', 24722],
    'me55c → 24837' => ['me55c', 24837],
    'xy9 → 1701, never 2175 Burger King' => ['xy9', 1701],
    'tk1a → 1543' => ['tk1a', 1543],
    'tk1b → 1543' => ['tk1b', 1543],
    'tk2a → 1542' => ['tk2a', 1542],
    'tk2b → 1542' => ['tk2b', 1542],
]);

test('g1 and bw11 route RC numbers to their Radiant Collection group', function (): void {
    expect(t37RowsFor('g1'))->toBe([
        1728 => ['primary', '', ''],
        1729 => ['secondary', 'RC*', ''],
    ]);
    expect(t37RowsFor('bw11'))->toBe([
        1409 => ['primary', '', ''],
        1465 => ['secondary', 'RC*', ''],
    ]);
});

test('group 2175 (Burger King Promos, also BKP) appears nowhere', function (): void {
    expect(array_column(t37Csv()['rows'], 'source_group_id'))->not->toContain('2175');
});

test('fut20 is listed with an empty group and role none, and it is the only such row', function (): void {
    $none = array_values(array_filter(t37Csv()['rows'], fn (array $r): bool => $r['role'] === 'none' || $r['source_group_id'] === ''));
    expect($none)->toHaveCount(1);
    expect($none[0]['pokesearch_set_id'])->toBe('fut20');
    expect($none[0]['source_group_id'])->toBe('');
    expect($none[0]['role'])->toBe('none');
});

test('every other set has exactly one primary group', function (): void {
    $primaries = [];
    foreach (t37Csv()['rows'] as $row) {
        $primaries[$row['pokesearch_set_id']] ??= 0;
        if ($row['role'] === 'primary') {
            $primaries[$row['pokesearch_set_id']]++;
        }
    }
    unset($primaries['fut20']);
    expect(array_unique(array_values($primaries)))->toBe([1]);
    expect($primaries)->toHaveCount(175);
});

test('every stored row satisfies the set_aliases CHECKs of T36', function (): void {
    expect(t37Csv()['rows'])->toHaveCount(180);
    foreach (t37Csv()['rows'] as $row) {
        if ($row['pokesearch_set_id'] === 'fut20') {
            continue;
        }
        expect($row['source_group_id'])->toMatch('/^[1-9][0-9]*$/');
        expect($row['role'])->toBeIn(['primary', 'edition-split', 'secondary']);
        expect($row['method'])->toBeIn(['name', 'ptcgo', 'manual']);
        expect($row['unlimited_means'])->toBeIn(['', 'unlimited', 'shadowless']);
        expect($row['note'])->not->toBe('');
    }
});

test('a secondary row always carries a number rule and a primary row never does', function (): void {
    expect(array_column(t37Csv()['rows'], 'role'))->toContain('secondary');
    foreach (t37Csv()['rows'] as $row) {
        if ($row['role'] === 'secondary') {
            expect($row['number_rule'])->not->toBe('');
        } else {
            expect($row['number_rule'])->toBe('');
        }
    }
});

test('every manual row cites its authoring source: groups.json of 2026-10-11 plus the dataset', function (): void {
    $manual = array_filter(t37Csv()['rows'], fn (array $r): bool => $r['method'] === 'manual');
    expect($manual)->not->toBeEmpty();
    foreach ($manual as $row) {
        expect($row['note'])->toContain('groups.json of 2026-10-11')->toContain('dataset');
    }
});

test('the required rows are manual, so no heuristic decides them', function (): void {
    $manualPairs = [];
    foreach (t37Csv()['rows'] as $row) {
        if ($row['method'] === 'manual') {
            $manualPairs[] = $row['pokesearch_set_id'].'|'.$row['source_group_id'];
        }
    }
    foreach (['base1|604', 'base1|1663', 'base1|1840', 'basep|1418', 'g1|1729', 'bw11|1465', 'tk1a|1543', 'tk1b|1543', 'tk2a|1542', 'tk2b|1542', 'fut20|'] as $pair) {
        expect($manualPairs)->toContain($pair);
    }
});

test('the CSV holds no home path, host name or private address', function (): void {
    $text = (string) file_get_contents(T37_CSV);
    expect($text)->not->toMatch('#/home/|/Users/|~/|\.home\b|\b(?:10|127|172|192\.168)\.\d+\.\d+#');
});
