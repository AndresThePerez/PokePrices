<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan P5-i1 T01 criterion 9 (R177-R194, R180a): a dispatch writes deliveries and dispatch_days only, so
// price_events.dispatched_at stays null and replay --fresh still runs after a dispatch.

beforeEach(function (): void {
    // A map build queues VerifyCatalog (T45); the sync test queue would run it against the real PokéSearch.
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    if ($code !== 0) {
        throw new RuntimeException('the fixture map build failed: '.Artisan::output());
    }
    $this->catalogPrefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/**
 * The tables a list of SQL statements inserts into, updates, deletes from or truncates, sorted and unique.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function p5WrittenTables(array $statements): array
{
    $tables = [];
    foreach ($statements as $sql) {
        preg_match_all('/\b(?:insert\s+into|delete\s+from|truncate(?:\s+table)?|merge\s+into)\s+"?([a-z_]+)"?|\bupdate\s+"?([a-z_]+)"?\s+set\b/i', $sql, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $tables[] = strtolower($match[1] !== '' ? $match[1] : $match[2]);
        }
    }
    $tables = array_values(array_unique($tables));
    sort($tables);

    return $tables;
}

it('writes only deliveries and dispatch_days, leaves dispatched_at null, and replay --fresh still runs', function (): void {
    $snapshots = base_path('tests/Fixtures/tcgcsv-days/snapshots');
    foreach (['2026-10-10', '2026-10-11'] as $day) {
        expect(Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots]))->toBe(0);
    }
    $events = DB::select('SELECT id, variant_key FROM price_events ORDER BY id');
    expect($events)->not->toBe([]);
    $user = AlertRows::user('watcher@example.com');
    foreach ($events as $event) {
        AccountRows::watch($user, $event->variant_key);
    }
    AlertRows::endpoint($user);
    $before = DB::select('SELECT * FROM price_events ORDER BY id');

    $statements = [];
    DB::listen(function (QueryExecuted $q) use (&$statements): void {
        $statements[] = $q->sql;
    });
    [$code, $line] = AlertRows::dispatch();
    $listened = $statements;
    $statements = [];

    $replay = Artisan::call('pokeprices:prices:replay', ['--snapshots' => $snapshots, '--fresh' => true, '--days' => '2026-10-11']);
    $replayOutput = Artisan::output();

    expect($code)->toBe(0)
        ->and($line['created']['price.moved'])->toBe(count($events))
        ->and(p5WrittenTables($listened))->toBe(['deliveries', 'dispatch_days'])
        ->and(DB::select('SELECT * FROM price_events ORDER BY id'))->toEqual($before)
        ->and(DB::table('price_events')->whereNotNull('dispatched_at')->count())->toBe(0)
        ->and($replay)->toBe(0, $replayOutput)
        ->and($replayOutput)->not->toContain('R180a');
});

it('recognises every kind of write statement it is meant to catch', function (): void {
    expect(p5WrittenTables([
        'insert into "deliveries" ("a") values (1)',
        'UPDATE price_events SET dispatched_at = now()',
        'select * from x for update skip locked',
        'DELETE FROM dispatch_days WHERE day > ?',
        'TRUNCATE price_points, price_daily',
        'WITH params AS (SELECT 1) INSERT INTO price_events (id) SELECT 1',
    ]))->toBe(['deliveries', 'dispatch_days', 'price_events', 'price_points']);
});
