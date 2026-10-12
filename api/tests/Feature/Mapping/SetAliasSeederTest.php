<?php

declare(strict_types=1);

use App\Domain\Mapping\Models\SetAlias;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\DB;

// SetAliasSeeder loads database/data/set_aliases.csv into set_aliases on the test database (plan T37 item 3).
// The CSV has 180 rows; fut20 (empty group, role none) cannot satisfy T36's CHECKs and is skipped, so 179 are stored.

const T37_STORED = 179;

/** @return list<array<string, mixed>> every set_aliases row without its surrogate id, in a stable order */
function t37Table(): array
{
    return DB::table('set_aliases')
        ->select(['source', 'pokesearch_set_id', 'source_group_id', 'role', 'number_rule', 'method', 'unlimited_means', 'note'])
        ->orderBy('pokesearch_set_id')->orderBy('source_group_id')
        ->get()->map(fn (object $r): array => (array) $r)->all();
}

test('the seeder stores every CSV row with a group, as tcgplayer rows', function (): void {
    $this->seed(SetAliasSeeder::class);

    expect(SetAlias::query()->count())->toBe(T37_STORED);
    expect(SetAlias::query()->distinct()->count('pokesearch_set_id'))->toBe(175);
    expect(SetAlias::query()->where('source', '<>', 'tcgplayer')->count())->toBe(0);
});

test('the seeder skips the fut20 row, which has no group', function (): void {
    $this->seed(SetAliasSeeder::class);

    expect(SetAlias::query()->where('pokesearch_set_id', 'fut20')->count())->toBe(0);
});

test('the seeder stores the required base1 rows with nulls for empty cells', function (): void {
    $this->seed(SetAliasSeeder::class);

    $rows = SetAlias::query()->where('pokesearch_set_id', 'base1')->orderBy('source_group_id')->get();
    expect($rows->map(fn (SetAlias $a): array => [$a->source_group_id, $a->role, $a->number_rule, $a->unlimited_means, $a->method])->all())
        ->toBe([
            [604, 'primary', null, 'unlimited', 'manual'],
            [1663, 'edition-split', null, 'shadowless', 'manual'],
            [1840, 'secondary', '8', null, 'manual'],
        ]);
});

test('running the seeder twice leaves the table unchanged', function (): void {
    $this->seed(SetAliasSeeder::class);
    $first = t37Table();
    $ids = DB::table('set_aliases')->orderBy('id')->pluck('id')->all();

    $this->seed(SetAliasSeeder::class);

    expect(t37Table())->toBe($first);
    expect(DB::table('set_aliases')->orderBy('id')->pluck('id')->all())->toBe($ids);
});

test('the seeder restores an edited row and removes a row the CSV does not list', function (): void {
    $this->seed(SetAliasSeeder::class);
    $expected = t37Table();
    DB::table('set_aliases')->where('pokesearch_set_id', 'basep')->update(['source_group_id' => 1840, 'note' => 'edited']);
    DB::table('set_aliases')->insert([
        'pokesearch_set_id' => 'xy9', 'source' => 'tcgplayer', 'source_group_id' => 2175, 'role' => 'secondary',
        'number_rule' => null, 'method' => 'ptcgo', 'unlimited_means' => null, 'note' => 'stale',
    ]);

    $this->seed(SetAliasSeeder::class);

    expect(t37Table())->toBe($expected);
});
