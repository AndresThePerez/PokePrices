<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for T40. R167(a): coverage must account for EVERY dataset card, as mapped or as recorded
// in the build. The builder only walks price rows of products, so a card no priced product points at leaves no
// trace in unmapped_source_items, and the audit then counts it as uncovered.

function t40eFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv'.($path === '' ? '' : '/'.$path));
}

/** A writable copy of the fixture dataset with one extra base1 card no product of the fixture day carries. */
function t40eDatasetWithOrphanCard(string $id, string $number): string
{
    $root = sys_get_temp_dir().'/pp-t40e-'.bin2hex(random_bytes(6));
    mkdir($root.'/cards/en', 0775, true);
    mkdir($root.'/sets', 0775, true);
    copy(t40eFixture('dataset/sets/en.json'), $root.'/sets/en.json');
    foreach (glob(t40eFixture('dataset/cards/en/*.json')) ?: [] as $file) {
        copy($file, $root.'/cards/en/'.basename($file));
    }
    $cards = json_decode((string) file_get_contents($root.'/cards/en/base1.json'), true, 512, JSON_THROW_ON_ERROR);
    $cards[] = ['id' => $id, 'name' => 'Orphan Card', 'number' => $number];
    file_put_contents($root.'/cards/en/base1.json', json_encode($cards, JSON_THROW_ON_ERROR));
    register_shutdown_function(function () use ($root): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir((string) $f->getPathname()) : unlink((string) $f->getPathname());
        }
        rmdir($root);
    });

    return $root;
}

beforeEach(function (): void {
    // A build queues VerifyCatalog (T45); the sync test queue would run it against the real PokéSearch.
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
});

test('after a real build every dataset card is either mapped or recorded, including a card of a mapped set that no product carries', function (): void {
    $dataset = t40eDatasetWithOrphanCard('base1-77', '77');
    $built = Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => t40eFixture('snapshots')]);
    expect($built)->toBe(0);

    $out = sys_get_temp_dir().'/pp-t40e-cov-'.bin2hex(random_bytes(4)).'.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'coverage', '--json' => true, '--out' => $out, '--dataset' => $dataset]);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    unlink($out);

    $uncovered = array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'uncovered_card'), 'card_id');
    expect($code)->toBe(0)
        ->and($uncovered)->not->toContain('base1-77')
        ->and($artefact['value'])->toBe(0);
});

test('coverage never counts a mapped card as uncovered when the same card is also a candidate of an unmapped item', function (): void {
    $build = (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
    DB::table('card_variants')->insert(['variant_key' => 'c1-1:holo:-:-', 'card_id' => 'c1-1', 'finish' => 'holo', 'edition' => '-', 'treatment' => '-']);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => 1, 'source_product_id' => 1, 'source_subtype' => 'Holofoil',
        'source_product_name' => 'One', 'source_number' => '1', 'variant_key' => 'c1-1:holo:-:-', 'method' => 'number+name',
        'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
    ]);
    DB::table('unmapped_source_items')->insert([
        'map_build_id' => $build, 'source' => 'tcgplayer', 'source_group_id' => 1, 'source_product_id' => 2, 'source_subtype' => 'Holofoil',
        'product_name' => 'One (Stamp)', 'number' => '1', 'reason' => 'duplicate_target', 'candidates' => json_encode(['c1-1'], JSON_THROW_ON_ERROR),
    ]);
    $dir = sys_get_temp_dir().'/pp-t40e-'.bin2hex(random_bytes(6));
    mkdir($dir.'/sets', 0775, true);
    mkdir($dir.'/cards/en', 0775, true);
    file_put_contents($dir.'/sets/en.json', json_encode([['id' => 'c1']], JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/cards/en/c1.json', json_encode([['id' => 'c1-1', 'name' => 'One', 'number' => '1']], JSON_THROW_ON_ERROR));

    $out = $dir.'/out.json';
    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'coverage', '--json' => true, '--out' => $out, '--dataset' => $dir]);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    unlink($out);
    unlink($dir.'/cards/en/c1.json');
    unlink($dir.'/sets/en.json');
    rmdir($dir.'/cards/en');
    rmdir($dir.'/cards');
    rmdir($dir.'/sets');
    rmdir($dir);

    expect($code)->toBe(0)
        ->and($artefact['value'])->toBe(0)
        ->and($artefact['denominator'])->toBe(1)
        ->and($artefact['coverage_pct'])->toEqual(100.0);
});

test('coverage on an empty dataset has a zero denominator and a zero percentage, not a division error', function (): void {
    $build = (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
    $dir = sys_get_temp_dir().'/pp-t40e-'.bin2hex(random_bytes(6));
    mkdir($dir.'/sets', 0775, true);
    file_put_contents($dir.'/sets/en.json', '[]');
    $out = $dir.'/out.json';

    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'coverage', '--json' => true, '--out' => $out, '--dataset' => $dir, '--build' => (string) $build]);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    unlink($out);
    unlink($dir.'/sets/en.json');
    rmdir($dir.'/sets');
    rmdir($dir);

    expect($code)->toBe(0)
        ->and($artefact['denominator'])->toBe(0)
        ->and($artefact['coverage_pct'])->toEqual(0.0)
        ->and($artefact['value'])->toBe(0);
});

test('coverage is not satisfied by unmapped_cards rows of another build', function (): void {
    $stamp = ['source' => 'tcgplayer', 'reason' => 'no_priced_product'];
    $mk = fn (string $day): int => (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day, 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);
    $old = $mk('2026-10-10');
    $new = $mk('2026-10-11');
    DB::table('unmapped_cards')->insert($stamp + ['map_build_id' => $old, 'card_id' => 'c9-1']);
    DB::table('unmapped_cards')->insert($stamp + ['map_build_id' => $new, 'card_id' => 'c9-2']);
    $dir = sys_get_temp_dir().'/pp-t40e-'.bin2hex(random_bytes(6));
    mkdir($dir.'/sets', 0775, true);
    mkdir($dir.'/cards/en', 0775, true);
    file_put_contents($dir.'/sets/en.json', json_encode([['id' => 'c9']], JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/cards/en/c9.json', json_encode([['id' => 'c9-1', 'name' => 'A', 'number' => '1'], ['id' => 'c9-2', 'name' => 'B', 'number' => '2']], JSON_THROW_ON_ERROR));
    $out = $dir.'/out.json';

    $code = Artisan::call('pokeprices:map:audit', ['--check' => 'coverage', '--json' => true, '--out' => $out, '--dataset' => $dir, '--build' => (string) $new]);
    $artefact = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    unlink($out);
    unlink($dir.'/cards/en/c9.json');
    unlink($dir.'/sets/en.json');
    rmdir($dir.'/cards/en');
    rmdir($dir.'/cards');
    rmdir($dir.'/sets');
    rmdir($dir);

    expect($code)->toBe(0)
        ->and(array_column(array_filter($artefact['details'], fn (array $d): bool => $d['kind'] === 'uncovered_card'), 'card_id'))->toBe(['c9-1'])
        ->and($artefact['value'])->toBe(1);
});

test('a real build lists no card both as mapped and as unmapped_cards, and lists the orphan with a reason', function (): void {
    $dataset = t40eDatasetWithOrphanCard('base1-77', '77');
    Artisan::call('pokeprices:map:build', ['--day' => '2026-10-11', '--dataset' => $dataset, '--snapshots' => t40eFixture('snapshots')]);

    $listed = DB::table('unmapped_cards')->pluck('reason', 'card_id')->all();
    $mapped = DB::table('source_product_map as m')->join('card_variants as v', 'v.variant_key', '=', 'm.variant_key')->pluck('v.card_id')->all();

    expect($listed['base1-77'] ?? null)->toBe('no_priced_product')
        ->and(array_intersect(array_keys($listed), $mapped))->toBe([])
        ->and(DB::table('unmapped_cards')->count())->toBe((int) json_decode((string) DB::table('map_builds')->value('counts'), true)['unmapped_cards']);
});
