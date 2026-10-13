<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\LatestPrices;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// price_latest and its rebuild (plan P2-i1 T05 items 1 and 2, D4, D5, D11). The synthetic days of
// tests/Fixtures/tcgcsv-days are ingested with pokeprices:prices:ingest --sync over the map built from the P1 fixture
// day. Days the fixture does not hold (a 14- or 15-day gap, a 0.00 close) are relabelled or edited copies under a
// scratch root; the fixture tree itself is only ever read.

const T05_MIGRATION = 'migrations/2026_10_14_000002_create_price_latest_table.php';

function t05Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Runs the sync ingest of $day and returns its exit code and output. */
function t05Ingest(string $day, ?string $snapshots = null): array
{
    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t05Fixture('snapshots')]);

    return [$code, Artisan::output()];
}

/**
 * Copies fixture day $from to $root/$to. A relabelled copy gets the marker of $to at the fixture's time of day in
 * marker.txt and manifest.json, so the day validates under its new name.
 */
function t05CopyDay(string $root, string $from, ?string $to = null): void
{
    $to ??= $from;
    $src = t05Fixture('snapshots/'.$from);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir("{$root}/{$to}", 0775, true);
    foreach ($it as $file) {
        $dest = "{$root}/{$to}/".substr((string) $file->getPathname(), strlen($src) + 1);
        $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
    }
    if ($to !== $from) {
        $marker = str_replace($from, $to, trim((string) file_get_contents("{$root}/{$to}/marker.txt")));
        file_put_contents("{$root}/{$to}/marker.txt", $marker."\n");
        $manifest = json_decode((string) file_get_contents("{$root}/{$to}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        $manifest['marker'] = $marker;
        $manifest['day'] = $to;
        file_put_contents("{$root}/{$to}/manifest.json", json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}

/** A fresh scratch root that afterEach removes. */
function t05Scratch(): string
{
    $root = storage_path('framework/testing/pp-t05-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t05Roots($root);

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t05Roots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Sets the marketPrice of ($productId, $subtype) in group $group of a scratch day to the raw JSON token $token. */
function t05SetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T05_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T05_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** Every file under $dir as relative path => sha1. */
function t05Tree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** The price_latest rows as text, keyed by variant_key. */
function t05Latest(): array
{
    $out = [];
    foreach (DB::select(<<<'SQL'
        SELECT variant_key, source, series_epoch, close::text AS close, day::text AS day,
               to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of,
               previous_close::text AS previous_close, previous_day::text AS previous_day,
               change::text AS change, change_pct::text AS change_pct
        FROM price_latest ORDER BY variant_key COLLATE "C"
        SQL) as $row) {
        $out[$row->variant_key] = (array) $row;
    }

    return $out;
}

/** conname => "type:col1,col2" for the constraints of price_latest, sorted by name. */
function t05Constraints(): array
{
    $out = [];
    foreach (DB::select(
        "SELECT c.conname, c.contype, string_agg(a.attname, ',' ORDER BY k.ord) AS cols
         FROM pg_constraint c
         CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
         JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
         WHERE c.conrelid = 'price_latest'::regclass
         GROUP BY c.conname, c.contype",
    ) as $r) {
        $out[$r->conname] = "{$r->contype}:{$r->cols}";
    }
    ksort($out);

    return $out;
}

/** The SQLSTATE and constraint a statement fails with inside a savepoint, or null when it succeeds. */
function t05Violation(Closure $statement): ?array
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        preg_match('/constraint "([^"]+)"/', $e->getMessage(), $m);

        return [$e->getCode(), $m[1] ?? null];
    }

    return null;
}

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
    $this->fixtureTree = t05Tree(t05Fixture('snapshots'));
});

afterEach(function (): void {
    expect(t05Tree(t05Fixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t05Roots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('price_latest has exactly the planned columns, types and nullability', function (): void {
    $columns = array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}"
            .($c->data_type === 'numeric' ? "({$c->numeric_precision},{$c->numeric_scale})" : '')
            .':'.($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL'),
        DB::select("SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = 'price_latest' ORDER BY ordinal_position"),
    );

    expect($columns)->toBe([
        'variant_key:text:NOT NULL',
        'source:text:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'close:numeric(12,2):NOT NULL',
        'day:date:NOT NULL',
        'as_of:timestamp with time zone:NOT NULL',
        'previous_close:numeric(12,2):NULL',
        'previous_day:date:NULL',
        'change:numeric(12,2):NULL',
        'change_pct:numeric(12,2):NULL',
    ]);
});

test('price_latest has its primary key, foreign key and named CHECK constraints', function (): void {
    expect(t05Constraints())->toBe([
        'price_latest_close_check' => 'c:close',
        'price_latest_pkey' => 'p:variant_key,source',
        'price_latest_previous_check' => 'c:previous_close,previous_day,change,change_pct',
        'price_latest_previous_close_check' => 'c:previous_close',
        'price_latest_previous_day_check' => 'c:previous_day,day',
        'price_latest_series_epoch_check' => 'c:series_epoch',
        'price_latest_source_check' => 'c:source',
        'price_latest_variant_key_foreign' => 'f:variant_key',
    ]);
});

test('each price_latest CHECK and key rejects a violating row', function (array $overrides, string $state, string $constraint): void {
    $row = array_merge([
        'variant_key' => 'base1-4:holo:unlimited:-', 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => '70.81',
        'day' => '2026-10-11', 'as_of' => '2026-10-11 20:05:45+00', 'previous_close' => '70.10',
        'previous_day' => '2026-10-10', 'change' => '0.71', 'change_pct' => '1.01',
    ], $overrides);

    expect(t05Violation(fn () => DB::table('price_latest')->insert($row)))->toBe([$state, $constraint]);
})->with([
    'another source' => [['source' => 'tcgcsv'], '23514', 'price_latest_source_check'],
    'previous day not before day' => [['previous_day' => '2026-10-11'], '23514', 'price_latest_previous_day_check'],
    'previous close without previous day' => [['previous_day' => null], '23514', 'price_latest_previous_check'],
    'change without a previous close' => [['previous_close' => null, 'previous_day' => null], '23514', 'price_latest_previous_check'],
    'change_pct alone' => [['previous_close' => null, 'previous_day' => null, 'change' => null], '23514', 'price_latest_previous_check'],
    'negative close' => [['close' => '-0.01'], '23514', 'price_latest_close_check'],
    'epoch 0' => [['series_epoch' => 0], '23514', 'price_latest_series_epoch_check'],
    'unknown variant' => [['variant_key' => 'base1-4:holo:unlimited:nope'], '23503', 'price_latest_variant_key_foreign'],
]);

test('price_latest accepts a row without a previous close and a previous close of 0.00 with a null change_pct', function (): void {
    $base = ['source' => 'tcgplayer', 'series_epoch' => 1, 'close' => '0.07', 'day' => '2026-10-11', 'as_of' => '2026-10-11 20:05:45+00'];
    DB::table('price_latest')->insert($base + ['variant_key' => 'tk1a-1:normal:-:-']);
    DB::table('price_latest')->insert($base + ['variant_key' => 'base1-4:holo:unlimited:-', 'previous_close' => '0.00',
        'previous_day' => '2026-10-10', 'change' => '0.07', 'change_pct' => null]);

    expect(t05Violation(fn () => DB::table('price_latest')->insert($base + ['variant_key' => 'tk1a-1:normal:-:-'])))
        ->toBe(['23505', 'price_latest_pkey'])
        ->and(DB::table('price_latest')->count())->toBe(2);
});

test('ingest finalize maintains price_latest: close, previous close, change and change_pct of the newest real day', function (): void {
    expect(t05Ingest('2026-10-10')[0])->toBe(0)
        ->and(t05Ingest('2026-10-11')[0])->toBe(0);
    $latest = t05Latest();

    expect($latest['basep-8:holo:-:-'])->toBe([
        'variant_key' => 'basep-8:holo:-:-', 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => '28.00',
        'day' => '2026-10-11', 'as_of' => '2026-10-11T20:05:45Z', 'previous_close' => '25.00',
        'previous_day' => '2026-10-10', 'change' => '3.00', 'change_pct' => '12.00',
    ])
        ->and(array_intersect_key($latest['svp-46:holo:-:-'], array_flip(['close', 'previous_close', 'change', 'change_pct'])))
        ->toBe(['close' => '4.70', 'previous_close' => '5.00', 'change' => '-0.30', 'change_pct' => '-6.00'])
        // 70.81 vs 70.10: 0.71 / 70.10 = 1.0128 % rounds to 1.01.
        ->and(array_intersect_key($latest['base1-4:holo:unlimited:-'], array_flip(['close', 'change', 'change_pct'])))
        ->toBe(['close' => '70.81', 'change' => '0.71', 'change_pct' => '1.01'])
        // One latest row per variant with a real close; the null-market-only variant has none.
        ->and(count($latest))->toBe((int) DB::table('price_daily')->distinct()->count('variant_key'))
        ->and($latest)->not->toHaveKey('base1-4:holo:unlimited:error-black-dot');
});

test('a variant with one real day has a null previous close, change and change_pct', function (): void {
    expect(t05Ingest('2026-10-11')[0])->toBe(0);

    expect(t05Latest()['basep-8:holo:-:-'])->toMatchArray([
        'close' => '28.00', 'day' => '2026-10-11', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null,
    ]);
});

test('a day without a real close leaves the latest close on the newest real day', function (): void {
    foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        expect(t05Ingest($day)[0])->toBe(0);
    }
    $latest = t05Latest();

    // sv8pt5-7 has a null market on 10-12 and basep-13 is absent that day.
    expect(array_intersect_key($latest['sv8pt5-7:normal:-:-'], array_flip(['close', 'day', 'previous_close', 'previous_day'])))
        ->toBe(['close' => '0.27', 'day' => '2026-10-11', 'previous_close' => '0.26', 'previous_day' => '2026-10-10'])
        ->and($latest['basep-13:holo:-:-']['day'])->toBe('2026-10-11')
        ->and($latest['basep-8:holo:-:-']['day'])->toBe('2026-10-12');
});

test('a new series_epoch on the latest day gives a null previous close', function (): void {
    expect(t05Ingest('2026-10-10')[0])->toBe(0);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    expect(t05Ingest('2026-10-11')[0])->toBe(0);

    expect(t05Latest()['basep-8:holo:-:-'])->toMatchArray([
        'series_epoch' => 2, 'close' => '28.00', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null,
    ])
        ->and(t05Latest()['svp-46:holo:-:-']['previous_close'])->toBe('5.00');
});

test('the previous close reaches back 14 days and no further', function (string $later, ?string $previous): void {
    $root = t05Scratch();
    t05CopyDay($root, '2026-10-09');
    t05CopyDay($root, '2026-10-10', $later);
    expect(t05Ingest('2026-10-09', $root)[0])->toBe(0)
        ->and(t05Ingest($later, $root)[0])->toBe(0);

    expect(array_intersect_key(t05Latest()['base1-4:holo:unlimited:-'], array_flip(['close', 'day', 'previous_close', 'previous_day', 'change', 'change_pct'])))
        ->toBe($previous === null
            ? ['close' => '70.10', 'day' => $later, 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null]
            // 0.60 / 69.50 = 0.8633 % rounds to 0.86.
            : ['close' => '70.10', 'day' => $later, 'previous_close' => $previous, 'previous_day' => '2026-10-09', 'change' => '0.60', 'change_pct' => '0.86']);
})->with([
    '14 days' => ['2026-10-23', '69.50'],
    '15 days' => ['2026-10-24', null],
]);

test('a previous close of 0.00 gives the change and a null change_pct', function (): void {
    $root = t05Scratch();
    t05CopyDay($root, '2026-10-10');
    t05CopyDay($root, '2026-10-11');
    t05SetMarket($root, '2026-10-10', 1543, 83694, 'Normal', '0');
    expect(t05Ingest('2026-10-10', $root)[0])->toBe(0)
        ->and(t05Ingest('2026-10-11', $root)[0])->toBe(0);

    expect(array_intersect_key(t05Latest()['tk1a-1:normal:-:-'], array_flip(['close', 'previous_close', 'previous_day', 'change', 'change_pct'])))
        ->toBe(['close' => '0.07', 'previous_close' => '0.00', 'previous_day' => '2026-10-10', 'change' => '0.07', 'change_pct' => null]);
});

test('price_latest does not depend on the order the days were ingested in', function (): void {
    foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        t05Ingest($day);
    }
    $ascending = t05Latest();
    DB::table('price_latest')->delete();
    DB::table('price_daily')->delete();
    DB::table('price_points')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
    foreach (['2026-10-12', '2026-10-10', '2026-10-11'] as $day) {
        t05Ingest($day);
    }

    expect(t05Latest())->toBe($ascending)
        ->and($ascending)->not->toBe([]);
});

test('rebuild() recomputes the whole table from price_daily and drops vanished variants', function (): void {
    t05Ingest('2026-10-10');
    t05Ingest('2026-10-11');
    $before = t05Latest();

    LatestPrices::rebuild(DB::connection());
    expect(t05Latest())->toBe($before);

    DB::table('price_latest')->where('variant_key', 'basep-8:holo:-:-')->update(['close' => '1.00', 'change' => '-24.00', 'change_pct' => '-96.00']);
    DB::table('price_daily')->where('variant_key', 'svp-46:holo:-:-')->delete();
    LatestPrices::rebuild(DB::connection());
    $after = t05Latest();

    expect($after['basep-8:holo:-:-'])->toBe($before['basep-8:holo:-:-'])
        ->and($after)->not->toHaveKey('svp-46:holo:-:-')
        ->and(count($after))->toBe(count($before) - 1);
});

test('PriceDigest covers price_points, price_daily and price_latest', function (): void {
    t05Ingest('2026-10-10');
    t05Ingest('2026-10-11');
    $digest = PriceDigest::compute(DB::connection());

    expect(array_keys($digest['tables']))->toBe(['price_points', 'price_daily', 'price_latest'])
        ->and($digest['tables']['price_latest']['rows'])->toBe(DB::table('price_latest')->count())
        ->and($digest['tables']['price_latest']['sha256'])->toMatch('/\A[0-9a-f]{64}\z/');

    LatestPrices::rebuild(DB::connection());
    expect(PriceDigest::compute(DB::connection()))->toBe($digest);

    DB::table('price_latest')->where('variant_key', 'basep-8:holo:-:-')->update(['change_pct' => '12.01']);
    $changed = PriceDigest::compute(DB::connection());
    expect($changed['tables']['price_latest']['sha256'])->not->toBe($digest['tables']['price_latest']['sha256'])
        ->and($changed['tables']['price_daily'])->toBe($digest['tables']['price_daily']);
});

test('the migration rolls back and re-applies cleanly', function (): void {
    $migration = require database_path(T05_MIGRATION);
    $migration->down();
    $gone = DB::selectOne("SELECT to_regclass('price_latest') AS t")->t;
    $migration->up();

    expect($gone)->toBeNull()
        ->and(DB::selectOne("SELECT to_regclass('price_latest') AS t")->t)->toBe('price_latest')
        ->and(t05Constraints())->toHaveCount(8);
});
