<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Edge cases for the day ingest (plan P2-i1 T04). Reviewer-authored; self-contained helpers (a test file must not
// depend on another file's functions). Everything reads the generated fixture days or a scratch copy of them.

function t04eFixture(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

/** @return array{0: int, 1: string, 2: array<string, mixed>|null} */
function t04eIngest(string $day, ?string $snapshots = null, array $extra = []): array
{
    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t04eFixture(), ...$extra]);
    $output = Artisan::output();
    $json = null;
    foreach (array_reverse(explode("\n", trim($output))) as $line) {
        if (str_starts_with(trim($line), '{')) {
            $json = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            break;
        }
    }

    return [$code, $output, $json];
}

/** A writable copy of the fixture days under a scratch root that afterEach removes. */
function t04eCopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t04e-'.bin2hex(random_bytes(6)));
    foreach ($days as $day) {
        $from = t04eFixture().'/'.$day;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir("{$root}/{$day}", 0775, true);
        foreach ($it as $file) {
            $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
            $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
        }
    }
    t04eRoots($root);

    return $root;
}

function t04eRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

function t04eReadPrices(string $root, string $day, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/{$day}/prices/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

function t04eWritePrices(string $root, string $day, int $group, array $document): void
{
    file_put_contents("{$root}/{$day}/prices/{$group}.json.zst", zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Sets $field of product $productId to the raw JSON token $token. */
function t04eSetRawToken(string $root, string $day, int $group, int $productId, string $field, string $token): void
{
    $document = t04eReadPrices($root, $day, $group);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId) {
            $document['results'][$i][$field] = '__T04E_TOKEN__';
        }
    }
    file_put_contents("{$root}/{$day}/prices/{$group}.json.zst", zstd_compress(str_replace('"__T04E_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** Gives a scratch day a later marker on the same UTC date (marker.txt and manifest.json agree). */
function t04eLaterMarker(string $root, string $day, string $marker): void
{
    file_put_contents("{$root}/{$day}/marker.txt", $marker."\n");
    $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true);
    file_put_contents("{$root}/{$day}/manifest.json", json_encode(['marker' => $marker] + $manifest));
}

function t04eState(): array
{
    return [
        'points' => array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
            SELECT variant_key, to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS observed_at, day::text AS day,
                   series_epoch, source_product_id, source_subtype, market::text AS market, low::text AS low, mid::text AS mid,
                   high::text AS high, direct_low::text AS direct_low
            FROM price_points ORDER BY variant_key, observed_at
            SQL)),
        'daily' => array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
            SELECT variant_key, day::text AS day, series_epoch, open::text AS open, high::text AS high, low::text AS low,
                   close::text AS close, n_obs, is_filled, filled_from::text AS filled_from,
                   to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM price_daily ORDER BY variant_key, day
            SQL)),
        'digest' => PriceDigest::compute(DB::connection()),
    ];
}

function t04eWipe(): void
{
    DB::table('price_daily')->delete();
    DB::table('price_points')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

function t04eRaw(): array
{
    return ['price_points' => DB::table('price_points')->count(), 'price_daily' => DB::table('price_daily')->count(), 'runs' => DB::table('ingest_runs')->count()];
}

function t04eAnotherSessionCanLock(): bool
{
    config(['database.connections.t04e_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t04e_holder');
    $taken = $holder->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [IngestLock::KEY])->ok === true;
    $holder->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t04e_holder');

    return $taken;
}

function t04eFaultOnDailyInsert(): void
{
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION t04e_fault() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 't04e finalize fault'; END $$;
        CREATE TRIGGER t04e_fault BEFORE INSERT ON price_daily FOR EACH ROW EXECUTE FUNCTION t04e_fault();
        SQL);
}

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    expect($code)->toBe(0);
});

afterEach(function (): void {
    foreach (t04eRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('D3: the ingest order of all five days, with a refused attempt in between, never changes price_points, price_daily or their digest', function (array $order): void {
    foreach (['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'] as $day) {
        expect(t04eIngest($day)[0])->toBe(0);
    }
    $ascending = t04eState();
    expect($ascending['digest']['tables']['price_daily']['rows'])->toBeGreaterThan(80);

    t04eWipe();
    foreach ($order as $day) {
        [$code, $output] = t04eIngest($day);
        expect($code)->toBe(0, $output);
        // 10-13 has no folder: refused, and a refused run is no P (newest ingested day).
        expect(t04eIngest('2026-10-13')[0])->toBe(1);
    }

    expect(t04eState())->toBe($ascending);
})->with([
    'newest first' => [['2026-10-14', '2026-10-12', '2026-10-11', '2026-10-10', '2026-10-09']],
    'shuffled' => [['2026-10-11', '2026-10-14', '2026-10-09', '2026-10-12', '2026-10-10']],
]);

test('D1/F7: a later marker whose finalize fails rolls the deletion of the older marker back with it', function (): void {
    $root = t04eCopyDays('2026-10-11');
    t04eLaterMarker($root, '2026-10-11', '2026-10-11T22:10:05+0000');
    t04eSetRawToken($root, '2026-10-11', 1418, 85000, 'marketPrice', '29.50');

    expect(t04eIngest('2026-10-11')[0])->toBe(0);
    $before = t04eState();

    t04eFaultOnDailyInsert();
    [$code, $output] = t04eIngest('2026-10-11', $root);

    expect($code)->toBe(1)
        ->and($output)->toContain('t04e finalize fault')
        ->and(t04eState())->toBe($before)
        ->and(DB::table('price_points')->distinct()->pluck('observed_at')->count())->toBe(1)
        ->and(DB::table('ingest_runs')->orderBy('id')->pluck('status')->all())->toBe(['ok', 'failed'])
        ->and(DB::table('ingest_run_chunks')->where('ingest_run_id', DB::table('ingest_runs')->where('status', 'failed')->value('id'))->count())->toBe(0)
        ->and(t04eAnotherSessionCanLock())->toBeTrue();
});

test('D1/F7: a variant whose market turns null under a later marker keeps its raw point but loses its candle', function (): void {
    $root = t04eCopyDays('2026-10-11');
    t04eLaterMarker($root, '2026-10-11', '2026-10-11T22:10:05+0000');
    t04eSetRawToken($root, '2026-10-11', 1418, 85000, 'marketPrice', 'null');

    expect(t04eIngest('2026-10-11')[0])->toBe(0);
    [$code, $output, $line] = t04eIngest('2026-10-11', $root);

    $points = DB::table('price_points')->where('variant_key', 'basep-8:holo:-:-')->get();
    expect($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['mapped' => 21, 'null_market' => 3, 'upserted' => 21, 'unchanged' => 0, 'daily_rows' => 18])
        ->and($points)->toHaveCount(1)
        ->and($points[0]->market)->toBeNull()
        ->and($points[0]->low)->toBe('25.20')
        ->and(DB::table('price_daily')->where('variant_key', 'basep-8:holo:-:-')->count())->toBe(0)
        ->and(DB::table('price_points')->count())->toBe(21);
});

test('D5: values that are not exact two-decimal numbers refuse the whole day with the bad_value grammar and zero rows', function (string $token): void {
    $root = t04eCopyDays('2026-10-11');
    t04eSetRawToken($root, '2026-10-11', 1543, 83694, 'marketPrice', $token);

    [$code, $output] = t04eIngest('2026-10-11', $root);

    $run = (array) DB::table('ingest_runs')->orderByDesc('id')->first();
    expect($code)->toBe(1, $output)
        ->and([$run['status'], $run['error']])->toBe(['refused', 'bad_value:prices/1543.json.zst:83694:marketPrice'])
        ->and(DB::table('price_points')->count())->toBe(0)
        ->and(DB::table('price_daily')->count())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->count())->toBe(0);
})->with([
    'half-cent that rounds up' => ['2.555'],
    'a float-sum artefact' => ['0.30000000000000004'],
    'twenty digits' => ['99999999999999999999'],
    'a negative exponent' => ['1e-2'],
    'a negative integer' => ['-5'],
    'a third decimal that is zero-adjacent' => ['0.001'],
    'a plus-signed exponent' => ['1E+2'],
]);

test('D5: the exponent rewrite is blind to strings, and a string ending in an escaped backslash does not hide a real exponent', function (): void {
    $root = t04eCopyDays('2026-10-11');
    $document = t04eReadPrices($root, '2026-10-11', 1543);
    $document['results'][] = ['productId' => 999111, 'subTypeName' => 'Holo 1e2 "2e3" 9E9', 'lowPrice' => null, 'midPrice' => null, 'highPrice' => null, 'marketPrice' => 1.5, 'directLowPrice' => null];
    t04eWritePrices($root, '2026-10-11', 1543, $document);

    [$code, $output, $line] = t04eIngest('2026-10-11', $root);
    expect($code)->toBe(0, $output)
        ->and($line['rows'])->toBe(30)
        ->and($line['unmapped'])->toBe(9);

    t04eWipe();
    $document['results'][count($document['results']) - 1]['subTypeName'] = 'ends in a backslash\\';
    $document['results'][count($document['results']) - 1]['marketPrice'] = '__T04E_TOKEN__';
    file_put_contents("{$root}/2026-10-11/prices/1543.json.zst", zstd_compress(str_replace('"__T04E_TOKEN__"', '1e2', json_encode($document, JSON_THROW_ON_ERROR))));

    [$refusedCode] = t04eIngest('2026-10-11', $root);
    expect($refusedCode)->toBe(1)
        ->and(DB::table('ingest_runs')->orderByDesc('id')->value('error'))->toBe('bad_value:prices/1543.json.zst:999111:marketPrice')
        ->and(t04eRaw()['price_points'])->toBe(0);
});

test('D1: a day that was ingested ok and then broke on disk is refused, with or without --force, and its stored rows stay', function (array $extra): void {
    $root = t04eCopyDays('2026-10-11');
    expect(t04eIngest('2026-10-11', $root)[0])->toBe(0);
    $before = t04eState();

    $bytes = (string) file_get_contents("{$root}/2026-10-11/prices/23821.json.zst");
    file_put_contents("{$root}/2026-10-11/prices/23821.json.zst", substr($bytes, 0, intdiv(strlen($bytes), 2)));
    [$code, $output] = t04eIngest('2026-10-11', $root, $extra);

    expect($code)->toBe(1, $output)
        ->and(DB::table('ingest_runs')->orderByDesc('id')->value('error'))->toBe('bad_document:prices/23821.json.zst')
        ->and(DB::table('ingest_runs')->orderBy('id')->pluck('status')->all())->toBe(['ok', 'refused'])
        ->and(t04eState())->toBe($before);
})->with([
    'without --force' => [[]],
    'with --force' => [['--force' => true]],
]);

test('D12: the lock is released after a failed run and after a refused run', function (): void {
    $root = t04eCopyDays('2026-10-11');
    unlink("{$root}/2026-10-11/SUCCESS");
    expect(t04eIngest('2026-10-11', $root)[0])->toBe(1)
        ->and(t04eAnotherSessionCanLock())->toBeTrue();

    t04eFaultOnDailyInsert();
    expect(t04eIngest('2026-10-11')[0])->toBe(1)
        ->and(DB::table('ingest_runs')->orderByDesc('id')->value('status'))->toBe('failed')
        ->and(t04eAnotherSessionCanLock())->toBeTrue();
});

test('the command refuses a path-traversing or non-ASCII --day without reading outside the root or writing a run', function (string $day): void {
    $root = t04eCopyDays('2026-10-11');
    rename("{$root}/2026-10-11", "{$root}/oracle");
    mkdir("{$root}/snapshots");

    [$code, $output] = t04eIngest($day, "{$root}/snapshots");

    expect($code)->toBe(1, $output)
        ->and(t04eRaw())->toBe(['price_points' => 0, 'price_daily' => 0, 'runs' => 0]);
})->with([
    'a sibling folder' => ['../oracle'],
    'a dot-dot tail' => ['2026-10-11/../../oracle'],
    'the root itself' => ['.'],
    'a slash tail' => ['2026-10-11/'],
    'compact date' => ['20261011'],
    'single-digit month' => ['2026-1-11'],
    'full-width digits' => ['２０２６-10-11'],
]);

test('a refused day leaks no path outside the day folder into the output or the run row', function (callable $break): void {
    $root = t04eCopyDays('2026-10-11');
    $break($root);

    [$code, $output] = t04eIngest('2026-10-11', $root);

    $error = (string) DB::table('ingest_runs')->orderByDesc('id')->value('error');
    expect($code)->toBe(1)
        ->and($output.$error)->not->toContain($root)
        ->and($output.$error)->not->toContain(base_path())
        ->and($output.$error)->not->toContain(storage_path())
        ->and($error)->not->toBe('');
})->with([
    'SUCCESS absent' => [fn (string $r) => unlink("{$r}/2026-10-11/SUCCESS")],
    'a prices file cut short' => [function (string $r): void {
        $bytes = (string) file_get_contents("{$r}/2026-10-11/prices/604.json.zst");
        file_put_contents("{$r}/2026-10-11/prices/604.json.zst", substr($bytes, 0, 20));
    }],
    'a missing products file' => [fn (string $r) => unlink("{$r}/2026-10-11/products/1840.json.zst")],
    'a bad value' => [fn (string $r) => t04eSetRawToken($r, '2026-10-11', 1543, 83694, 'lowPrice', '1.005')],
    'a manifest that names another marker' => [function (string $r): void {
        $m = json_decode((string) file_get_contents("{$r}/2026-10-11/manifest.json"), true);
        file_put_contents("{$r}/2026-10-11/manifest.json", json_encode(['marker' => '2026-10-11T19:00:00+0000'] + $m));
    }],
]);

test('a marker on a month boundary writes into the right monthly partition on the run connection', function (string $marker, string $day, string $partition): void {
    $root = t04eCopyDays('2026-10-11');
    rename("{$root}/2026-10-11", "{$root}/{$day}");
    t04eLaterMarker($root, $day, $marker);
    $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true);
    file_put_contents("{$root}/{$day}/manifest.json", json_encode(['day' => $day] + $manifest));

    [$code, $output, $line] = t04eIngest($day, $root);

    expect($code)->toBe(0, $output)
        ->and($line['upserted'])->toBe(21)
        ->and(DB::select('SELECT DISTINCT tableoid::regclass::text AS part FROM price_points')[0]->part)->toBe($partition)
        ->and(DB::table('price_points')->count())->toBe(21)
        ->and(DB::table('price_daily')->count())->toBe(19);
})->with([
    'the last second of October' => ['2026-10-31T23:59:59Z', '2026-10-31', 'price_points_y2026m10'],
    'the first instant of November' => ['2026-11-01T00:00:00Z', '2026-11-01', 'price_points_y2026m11'],
]);
