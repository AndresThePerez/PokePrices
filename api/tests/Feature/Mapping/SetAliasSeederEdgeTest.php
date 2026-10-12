<?php

declare(strict_types=1);

use App\Domain\Mapping\Models\SetAlias;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\DB;

// Reviewer edge cases for the T37 seeder: malformed CSV input fails loudly, and a stale-row sweep removes every
// stale row (not only the first).

/** @param list<string> $lines */
function t37rCsvFile(array $lines): string
{
    $dir = base_path('../.gates/tmp/T37r');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/case-'.bin2hex(random_bytes(4)).'.csv';
    file_put_contents($path, implode("\n", array_merge([implode(',', SetAliasSeeder::HEADER)], $lines))."\n");

    return $path;
}

test('storableRows rejects every malformed row loudly instead of skipping it', function (string $line, string $message): void {
    $path = t37rCsvFile([$line]);
    try {
        expect(fn () => SetAliasSeeder::storableRows($path))->toThrow(RuntimeException::class, $message);
    } finally {
        @unlink($path);
    }
})->with([
    'six cells' => ['xy9,1701,primary,,name,', 'seven cells'],
    'eight cells' => ['xy9,1701,primary,,name,,note,extra', 'seven cells'],
    'non-numeric group' => ['xy9,17O1,primary,,name,,note', 'not a number'],
    'negative group' => ['xy9,-1701,primary,,name,,note', 'not a number'],
    'padded group' => ['xy9, 1701,primary,,name,,note', 'not a number'],
    'group with role none' => ['xy9,1701,none,,manual,,note', 'go together'],
    'empty group with real role' => ['xy9,,primary,,manual,,note', 'go together'],
]);

test('storableRows rejects a foreign header and a missing file', function (): void {
    $path = base_path('../.gates/tmp/T37r/bad-header.csv');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, "pokesearch_set_id,group\nxy9,1701\n");
    try {
        expect(fn () => SetAliasSeeder::storableRows($path))->toThrow(RuntimeException::class, 'unexpected header');
    } finally {
        @unlink($path);
    }
    expect(fn () => SetAliasSeeder::storableRows(dirname($path).'/does-not-exist.csv'))->toThrow(RuntimeException::class, 'not readable');
});

test('the seeder removes every stale row, however many, and leaves untouched rows with their ids', function (): void {
    $this->seed(SetAliasSeeder::class);
    $before = DB::table('set_aliases')->orderBy('id')->pluck('id', 'pokesearch_set_id')->all();
    foreach ([2175, 2176, 2177, 2178] as $group) {
        DB::table('set_aliases')->insert([
            'pokesearch_set_id' => 'xy9', 'source' => 'tcgplayer', 'source_group_id' => $group, 'role' => 'secondary',
            'number_rule' => null, 'method' => 'ptcgo', 'unlimited_means' => null, 'note' => 'stale',
        ]);
    }

    $this->seed(SetAliasSeeder::class);

    expect(SetAlias::query()->where('pokesearch_set_id', 'xy9')->pluck('source_group_id')->all())->toBe([1701]);
    expect(SetAlias::query()->count())->toBe(179);
    expect(DB::table('set_aliases')->orderBy('id')->pluck('id', 'pokesearch_set_id')->all())->toBe($before);
});
