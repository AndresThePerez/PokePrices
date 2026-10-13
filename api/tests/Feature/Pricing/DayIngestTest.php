<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PriceDigest;
use App\Domain\Pricing\PriceNormalizer;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// pokeprices:prices:ingest --sync over the synthetic days in tests/Fixtures/tcgcsv-days (plan P2-i1 T04). The map
// is built first from the P1 fixture day (tests/Fixtures/tcgcsv), whose (productId, subTypeName) pairs the five
// synthetic days reuse. The fixture tree is only ever read: tests that break a day copy it into their own scratch
// root under storage/framework/testing first.

const T04_MARKER_11 = '2026-10-11T20:05:45Z';

const T04_UNMAPPED_PRODUCTS = [88337, 125073, 610370, 610381, 610390, 610399, 610900, 999001];

function t04Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Builds the product map from the P1 fixture day, as the map tests do. */
function t04BuildMap(): void
{
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    if ($code !== 0) {
        throw new RuntimeException('the fixture map build failed: '.Artisan::output());
    }
}

/**
 * Runs the ingest command and returns [exit code, output, the decoded JSON line or null].
 *
 * @return array{0: int, 1: string, 2: array<string, mixed>|null}
 */
function t04Ingest(string $day, ?string $snapshots = null, array $extra = []): array
{
    $code = Artisan::call('pokeprices:prices:ingest', [
        '--day' => $day,
        '--sync' => true,
        '--snapshots' => $snapshots ?? t04Fixture('snapshots'),
        ...$extra,
    ]);
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

/** A writable copy of the fixture days named in $days under a scratch root that afterEach removes. */
function t04CopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t04-'.bin2hex(random_bytes(6)));
    foreach ($days as $day) {
        $from = t04Fixture('snapshots/'.$day);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir("{$root}/{$day}", 0775, true);
        foreach ($it as $file) {
            $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
            $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
        }
    }
    t04TempRoots($root);

    return $root;
}

/** Remembers a scratch root when given one; without an argument, returns the remembered roots and forgets them. */
function t04TempRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Decodes one prices file of a scratch day. */
function t04ReadPrices(string $root, string $day, int $group): array
{
    return json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/{$day}/prices/{$group}.json.zst")), true, 512, JSON_THROW_ON_ERROR);
}

/** Writes raw JSON text as one prices file of a scratch day. */
function t04WritePricesJson(string $root, string $day, int $group, string $json): void
{
    file_put_contents("{$root}/{$day}/prices/{$group}.json.zst", zstd_compress($json));
}

/** Sets $field of product $productId in a scratch prices file to the raw JSON token $token. */
function t04SetRawToken(string $root, string $day, int $group, int $productId, string $field, string $token): void
{
    $document = t04ReadPrices($root, $day, $group);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId) {
            $document['results'][$i][$field] = '__T04_TOKEN__';
        }
    }
    t04WritePricesJson($root, $day, $group, str_replace('"__T04_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Every file under $dir as relative path => sha1. */
function t04Tree(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** The price_points rows of $variant as text, ordered by observed_at; ingest_run_id is left out. */
function t04Points(?string $variant = null): array
{
    return array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
        SELECT variant_key, source, to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS observed_at,
               day::text AS day, series_epoch, source_product_id, source_subtype,
               market::text AS market, low::text AS low, mid::text AS mid, high::text AS high, direct_low::text AS direct_low
        FROM price_points WHERE (?::text IS NULL OR variant_key = ?) ORDER BY variant_key, observed_at
        SQL, [$variant, $variant]));
}

/** The price_daily rows of $variant as text, ordered by day. */
function t04Daily(?string $variant = null): array
{
    return array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
        SELECT variant_key, source, day::text AS day, series_epoch, open::text AS open, high::text AS high,
               low::text AS low, close::text AS close, n_obs, is_filled, filled_from::text AS filled_from,
               to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
        FROM price_daily WHERE (?::text IS NULL OR variant_key = ?) ORDER BY variant_key, day
        SQL, [$variant, $variant]));
}

/** Row counts of the price tables and the run tables. */
function t04Counts(): array
{
    return [
        'price_points' => DB::table('price_points')->count(),
        'price_daily' => DB::table('price_daily')->count(),
        'ingest_run_chunks' => DB::table('ingest_run_chunks')->count(),
    ];
}

/** The newest ingest_runs row as an array. */
function t04LastRun(): array
{
    $run = DB::table('ingest_runs')->orderByDesc('id')->first();

    return $run === null ? [] : (array) $run;
}

beforeEach(function (): void {
    // A map build queues VerifyCatalog (T45); the sync test queue would run it against the real PokéSearch.
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    t04BuildMap();
    $this->fixtureTree = t04Tree(t04Fixture('snapshots'));
});

afterEach(function (): void {
    // The fixture days are only ever read (raw snapshot files are never written).
    expect(t04Tree(t04Fixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t04TempRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('(a) one day stores the exact price points and daily candles of three named variants', function (): void {
    [$code, $output, $line] = t04Ingest('2026-10-11');

    expect($code)->toBe(0, $output)
        ->and(array_keys($line))->toBe(['run_id', 'day', 'marker', 'rows', 'mapped', 'unmapped', 'null_market', 'upserted', 'unchanged', 'daily_rows'])
        // 30 raw rows, one duplicate: 29 rows; 21 of them map (2 with a null market), 8 do not.
        ->and($line)->toMatchArray(['day' => '2026-10-11', 'marker' => T04_MARKER_11, 'rows' => 29, 'mapped' => 21,
            'unmapped' => 8, 'null_market' => 2, 'upserted' => 21, 'unchanged' => 0, 'daily_rows' => 19]);

    $point = fn (string $variant, int $product, string $subtype, string $market, string $low, string $mid, string $high, ?string $directLow): array => [
        'variant_key' => $variant, 'source' => 'tcgplayer', 'observed_at' => T04_MARKER_11, 'day' => '2026-10-11',
        'series_epoch' => 1, 'source_product_id' => $product, 'source_subtype' => $subtype,
        'market' => $market, 'low' => $low, 'mid' => $mid, 'high' => $high, 'direct_low' => $directLow,
    ];
    $candle = fn (string $variant, string $close): array => [
        'variant_key' => $variant, 'source' => 'tcgplayer', 'day' => '2026-10-11', 'series_epoch' => 1,
        'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'n_obs' => 1, 'is_filled' => false,
        'filled_from' => null, 'as_of' => T04_MARKER_11,
    ];

    // base1-4's file repeats its row with 99.99: the first occurrence (70.81) wins.
    expect(t04Points('base1-4:holo:unlimited:-'))->toBe([$point('base1-4:holo:unlimited:-', 42382, 'Holofoil', '70.81', '63.72', '74.35', '141.62', '67.26')])
        ->and(t04Points('basep-8:holo:-:-'))->toBe([$point('basep-8:holo:-:-', 85000, 'Holofoil', '28.00', '25.20', '29.40', '56.00', '26.60')])
        ->and(t04Points('tk1a-1:normal:-:-'))->toBe([$point('tk1a-1:normal:-:-', 83694, 'Normal', '0.07', '0.06', '0.07', '0.14', '0.06')])
        ->and(t04Daily('base1-4:holo:unlimited:-'))->toBe([$candle('base1-4:holo:unlimited:-', '70.81')])
        ->and(t04Daily('basep-8:holo:-:-'))->toBe([$candle('basep-8:holo:-:-', '28.00')])
        ->and(t04Daily('tk1a-1:normal:-:-'))->toBe([$candle('tk1a-1:normal:-:-', '0.07')])
        ->and(t04Counts())->toBe(['price_points' => 21, 'price_daily' => 19, 'ingest_run_chunks' => 9]);

    $run = t04LastRun();
    expect($run['id'])->toBe($line['run_id'])
        ->and([$run['source'], $run['feed'], $run['day'], $run['mode'], $run['status'], $run['error']])->toBe(['tcgplayer', 'tcgcsv', '2026-10-11', 'sync', 'ok', null])
        ->and($run['finished_at'])->not->toBeNull()
        ->and(json_decode($run['counts'], true))->toMatchArray(['rows' => 29, 'duplicate_rows' => 1, 'deleted_older_marker' => 0,
            'mapped' => 21, 'unmapped' => 8, 'null_market' => 2, 'upserted' => 21, 'unchanged' => 0, 'daily_rows' => 19,
            'map_build_id' => (int) DB::table('map_builds')->max('id')])
        ->and(DB::table('ingest_run_chunks')->where('status', '<>', 'done')->count())->toBe(0);
});

test('(b) the same day twice with --force upserts nothing new and leaves the digest unchanged', function (): void {
    [$first] = t04Ingest('2026-10-11');
    $before = PriceDigest::compute(DB::connection());
    [$code, $output, $line] = t04Ingest('2026-10-11', null, ['--force' => true]);

    expect($first)->toBe(0)
        ->and($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['mapped' => 21, 'upserted' => 0, 'unchanged' => 21, 'daily_rows' => 19])
        ->and(PriceDigest::compute(DB::connection()))->toBe($before)
        ->and($before['tables']['price_points']['rows'])->toBe(21)
        ->and($before['tables']['price_daily']['rows'])->toBe(19)
        ->and(DB::table('ingest_runs')->where('status', 'ok')->count())->toBe(2);
});

test('the same marker without --force records skipped_unchanged and exits 0 without writing', function (): void {
    t04Ingest('2026-10-11');
    $before = [t04Counts(), PriceDigest::compute(DB::connection())];
    [$code, $output, $line] = t04Ingest('2026-10-11');

    expect($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['day' => '2026-10-11', 'marker' => T04_MARKER_11, 'status' => 'skipped_unchanged'])
        ->and(t04LastRun()['status'])->toBe('skipped_unchanged')
        ->and([t04Counts(), PriceDigest::compute(DB::connection())])->toBe($before);
});

test('(c) every refusal reason writes a refused run naming the file and no price row', function (callable $break, string $error, bool $markerRead): void {
    $root = t04CopyDays('2026-10-11');
    $break("{$root}/2026-10-11");

    [$code, $output] = t04Ingest('2026-10-11', $root);

    $run = t04LastRun();
    expect($code)->toBe(1)
        ->and($output)->toContain($error)
        ->and([$run['status'], $run['error'], $run['mode'], $run['day']])->toBe(['refused', $error, 'sync', '2026-10-11'])
        ->and($run['marker'] !== null)->toBe($markerRead)
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0])
        ->and(DB::table('ingest_runs')->count())->toBe(1);
})->with([
    'SUCCESS absent' => [fn (string $d) => unlink("{$d}/SUCCESS"), 'missing_file:SUCCESS', false],
    'marker unreadable' => [fn (string $d) => file_put_contents("{$d}/marker.txt", "yesterday evening\n"), 'bad_marker:marker.txt', false],
    'marker absent' => [fn (string $d) => unlink("{$d}/marker.txt"), 'bad_marker:marker.txt', false],
    'marker on another UTC date' => [fn (string $d) => file_put_contents("{$d}/marker.txt", "2026-10-11T23:30:00-0200\n"), 'marker_day_mismatch:marker.txt', true],
    'a group without its prices file' => [fn (string $d) => unlink("{$d}/prices/1418.json.zst"), 'missing_file:prices/1418.json.zst', true],
    'a group without its products file' => [fn (string $d) => unlink("{$d}/products/1418.json.zst"), 'missing_file:products/1418.json.zst', true],
    'a prices file cut mid-frame' => [function (string $d): void {
        $bytes = (string) file_get_contents("{$d}/prices/23821.json.zst");
        file_put_contents("{$d}/prices/23821.json.zst", substr($bytes, 0, intdiv(strlen($bytes), 2)));
    }, 'bad_document:prices/23821.json.zst', true],
    'a prices file that is not JSON' => [fn (string $d) => file_put_contents("{$d}/prices/604.json.zst", zstd_compress('{"success": true, "results": [')), 'bad_document:prices/604.json.zst', true],
    'a prices file with success false' => [fn (string $d) => file_put_contents("{$d}/prices/604.json.zst", zstd_compress('{"success": false, "errors": ["x"], "results": []}')), 'bad_document:prices/604.json.zst', true],
    'a price row without an integer productId' => [fn (string $d) => file_put_contents("{$d}/prices/604.json.zst", zstd_compress('{"success": true, "errors": [], "results": [{"productId": "42382", "subTypeName": "Holofoil", "lowPrice": null, "midPrice": null, "highPrice": null, "marketPrice": null, "directLowPrice": null}]}')), 'bad_document:prices/604.json.zst', true],
    'a products file cut mid-frame' => [function (string $d): void {
        $bytes = (string) file_get_contents("{$d}/products/23821.json.zst");
        file_put_contents("{$d}/products/23821.json.zst", substr($bytes, 0, intdiv(strlen($bytes), 2)));
    }, 'bad_document:products/23821.json.zst', true],
    'groups.json not JSON' => [fn (string $d) => file_put_contents("{$d}/groups.json", '{"results": '), 'bad_document:groups.json', true],
    'manifest.json absent' => [fn (string $d) => unlink("{$d}/manifest.json"), 'missing_file:manifest.json', true],
    'manifest names another day' => [function (string $d): void {
        $m = json_decode((string) file_get_contents("{$d}/manifest.json"), true);
        file_put_contents("{$d}/manifest.json", json_encode(['day' => '2026-10-10'] + $m));
    }, 'manifest_mismatch:manifest.json:day', true],
    'manifest counts another number of groups' => [function (string $d): void {
        $m = json_decode((string) file_get_contents("{$d}/manifest.json"), true);
        file_put_contents("{$d}/manifest.json", json_encode(['groups' => 220] + $m));
    }, 'manifest_mismatch:manifest.json:groups', true],
    'a bad price value' => [fn (string $d) => t04SetRawToken(dirname($d), '2026-10-11', 1543, 83694, 'marketPrice', '-0.01'), 'bad_value:prices/1543.json.zst:83694:marketPrice', true],
]);

test('a day folder that does not exist is refused', function (): void {
    $root = t04CopyDays('2026-10-11');
    [$code, $output] = t04Ingest('2026-10-12', $root);

    expect($code)->toBe(1, $output)
        ->and([t04LastRun()['status'], t04LastRun()['error']])->toBe(['refused', 'missing_day:2026-10-12'])
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0]);
});

test('(d) an unmapped product is counted and never stored', function (): void {
    [$code, $output, $line] = t04Ingest('2026-10-11');

    expect($code)->toBe(0, $output)
        ->and($line['unmapped'])->toBe(count(T04_UNMAPPED_PRODUCTS))
        ->and(DB::table('price_points')->whereIn('source_product_id', T04_UNMAPPED_PRODUCTS)->count())->toBe(0)
        ->and(DB::table('price_points')->distinct()->pluck('source_product_id')->map(fn ($p): int => (int) $p)->sort()->values()->all())
        ->toBe([42382, 42425, 83694, 85000, 85001, 85131, 88335, 88336, 90166, 106999, 107004, 487751, 517271, 522650, 610360, 610380, 610540, 610641, 657516]);
});

test('(e) a null market is stored raw in price_points and gets no daily candle', function (): void {
    [$code, $output, $line] = t04Ingest('2026-10-12');

    // 10-12: basep-13 and ex10-? are absent (20 mapped); sv8pt5-7 joins the two always-null variants.
    expect($code)->toBe(0, $output)
        ->and($line)->toMatchArray(['mapped' => 20, 'null_market' => 3, 'daily_rows' => 17])
        ->and(t04Points('sv8pt5-7:normal:-:-'))->toBe([[
            'variant_key' => 'sv8pt5-7:normal:-:-', 'source' => 'tcgplayer', 'observed_at' => '2026-10-12T20:05:44Z',
            'day' => '2026-10-12', 'series_epoch' => 1, 'source_product_id' => 610380, 'source_subtype' => 'Normal',
            'market' => null, 'low' => '1.00', 'mid' => '2.00', 'high' => '3.00', 'direct_low' => null,
        ]])
        ->and(t04Daily('sv8pt5-7:normal:-:-'))->toBe([])
        ->and(t04Daily('base1-4:holo:unlimited:error-black-dot'))->toBe([])
        ->and(count(t04Points('base1-4:holo:unlimited:error-black-dot')))->toBe(1);
});

test('(h) a bad price value refuses the whole day with its named error and writes nothing', function (string $field, string $token): void {
    $root = t04CopyDays('2026-10-11');
    t04SetRawToken($root, '2026-10-11', 1543, 83694, $field, $token);

    [$code, $output] = t04Ingest('2026-10-11', $root);

    expect($code)->toBe(1, $output)
        ->and([t04LastRun()['status'], t04LastRun()['error']])->toBe(['refused', "bad_value:prices/1543.json.zst:83694:{$field}"])
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0]);
})->with([
    'three decimals' => ['marketPrice', '1.005'],
    'negative' => ['marketPrice', '-0.01'],
    'negative zero' => ['marketPrice', '-0.0'],
    'an exponent' => ['marketPrice', '1e2'],
    'an upper-case exponent with a fraction' => ['lowPrice', '1.5E1'],
    'ten billion' => ['marketPrice', '10000000000.00'],
    'ten billion as an integer' => ['highPrice', '10000000000'],
    'a JSON string' => ['marketPrice', '"1.00"'],
    'a JSON boolean' => ['midPrice', 'true'],
    'a JSON object' => ['directLowPrice', '{"amount": 1}'],
]);

test('(h) a price row missing one of the five price fields refuses the day', function (): void {
    $root = t04CopyDays('2026-10-11');
    $document = t04ReadPrices($root, '2026-10-11', 1543);
    unset($document['results'][0]['directLowPrice']);
    t04WritePricesJson($root, '2026-10-11', 1543, json_encode($document, JSON_THROW_ON_ERROR));

    [$code, $output] = t04Ingest('2026-10-11', $root);

    expect($code)->toBe(1, $output)
        ->and(t04LastRun()['error'])->toBe('bad_value:prices/1543.json.zst:83694:directLowPrice')
        ->and(t04Counts()['price_points'])->toBe(0);
});

test('(h) a bad value in a later duplicate row still refuses the day', function (): void {
    $root = t04CopyDays('2026-10-11');
    // prices/604's third row repeats 42382 Holofoil; the first occurrence wins but the repeat is still validated.
    $document = t04ReadPrices($root, '2026-10-11', 604);
    $document['results'][2]['marketPrice'] = '__T04_TOKEN__';
    t04WritePricesJson($root, '2026-10-11', 604, str_replace('"__T04_TOKEN__"', '-5.00', json_encode($document, JSON_THROW_ON_ERROR)));

    [$code] = t04Ingest('2026-10-11', $root);

    expect($code)->toBe(1)
        ->and(t04LastRun()['error'])->toBe('bad_value:prices/604.json.zst:42382:marketPrice')
        ->and(t04Counts()['price_points'])->toBe(0);
});

test('(h) a good price value is stored and read back as the exact two-decimal string', function (string $token, string $stored): void {
    $root = t04CopyDays('2026-10-11');
    t04SetRawToken($root, '2026-10-11', 1543, 83694, 'marketPrice', $token);

    [$code, $output] = t04Ingest('2026-10-11', $root);

    expect($code)->toBe(0, $output)
        ->and(t04Points('tk1a-1:normal:-:-')[0]['market'])->toBe($stored)
        ->and(t04Daily('tk1a-1:normal:-:-')[0]['close'])->toBe($stored);
})->with([
    'an integer token' => ['5', '5.00'],
    'seven cents' => ['0.07', '0.07'],
    'the largest real value' => ['214398.51', '214398.51'],
    'one decimal' => ['0.1', '0.10'],
    'a trailing zero' => ['1.10', '1.10'],
    'zero' => ['0', '0.00'],
    'the largest numeric(12,2)' => ['9999999999.99', '9999999999.99'],
]);

test('(h) PriceNormalizer::money converts once with sprintf and the float round trip', function (mixed $value, ?string $expected): void {
    expect(PriceNormalizer::money($value))->toBe($expected);
})->with([
    'null' => [null, null],
    'an integer' => [5, '5.00'],
    'a float' => [70.81, '70.81'],
    'seven cents' => [0.07, '0.07'],
    'one decimal' => [0.1, '0.10'],
]);

test('(h) PriceNormalizer::money refuses values that fail D5', function (mixed $value): void {
    expect(fn () => PriceNormalizer::money($value))->toThrow(UnexpectedValueException::class);
})->with([
    'three decimals' => [1.005],
    'negative' => [-0.01],
    'negative zero' => [-0.0],
    'ten billion' => [10000000000.0],
    'a string' => ['1.00'],
    'a boolean' => [false],
]);

test('the fixture stores 70.81 and 0.07 as exact text', function (): void {
    t04Ingest('2026-10-11');

    expect(DB::table('price_points')->where('variant_key', 'base1-4:holo:unlimited:-')->value(DB::raw('market::text')))->toBe('70.81')
        ->and(DB::table('price_daily')->where('variant_key', 'tk1a-1:normal:-:-')->value(DB::raw('close::text')))->toBe('0.07');
});

test('(i) a later marker on the same UTC date replaces the older rows and equals a fresh ingest of it', function (): void {
    $root = t04CopyDays('2026-10-11');
    $day = "{$root}/2026-10-11";
    file_put_contents("{$day}/marker.txt", "2026-10-11T22:10:05+0000\n");
    $manifest = json_decode((string) file_get_contents("{$day}/manifest.json"), true);
    file_put_contents("{$day}/manifest.json", json_encode(['marker' => '2026-10-11T22:10:05+0000'] + $manifest));
    t04SetRawToken($root, '2026-10-11', 1418, 85000, 'marketPrice', '29.50');
    // ex10-! (90166) drops out of the later copy: its 10-11 candle must vanish with the older marker.
    $ex10 = t04ReadPrices($root, '2026-10-11', 1398);
    $ex10['results'] = array_values(array_filter($ex10['results'], fn (array $r): bool => $r['productId'] !== 90166));
    t04WritePricesJson($root, '2026-10-11', 1398, json_encode($ex10, JSON_THROW_ON_ERROR));

    [$firstCode, , $first] = t04Ingest('2026-10-11');
    [$code, $output, $second] = t04Ingest('2026-10-11', $root);
    $afterBoth = [t04Points(), t04Daily(), PriceDigest::compute(DB::connection())];

    expect($firstCode)->toBe(0)
        ->and($code)->toBe(0, $output)
        ->and(json_decode((string) DB::table('ingest_runs')->where('id', $second['run_id'])->value('counts'), true)['deleted_older_marker'])->toBe($first['mapped'])
        ->and(DB::table('price_points')->distinct()->pluck('observed_at')->count())->toBe(1)
        ->and(t04Daily('ex10-!:holo:-:-'))->toBe([])
        ->and(t04Daily('basep-8:holo:-:-')[0]['close'])->toBe('29.50');

    DB::table('price_daily')->delete();
    DB::table('price_points')->delete();
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
    [$freshCode] = t04Ingest('2026-10-11', $root);

    expect($freshCode)->toBe(0)
        ->and([t04Points(), t04Daily(), PriceDigest::compute(DB::connection())])->toBe($afterBoth);
});

test('(j) a manifest naming another marker is refused as manifest_mismatch', function (): void {
    $root = t04CopyDays('2026-10-11');
    $manifest = json_decode((string) file_get_contents("{$root}/2026-10-11/manifest.json"), true);
    file_put_contents("{$root}/2026-10-11/manifest.json", json_encode(['marker' => '2026-10-11T19:05:45+0000'] + $manifest));

    [$code, $output] = t04Ingest('2026-10-11', $root);

    expect($code)->toBe(1, $output)
        ->and([t04LastRun()['status'], t04LastRun()['error']])->toBe(['refused', 'manifest_mismatch:manifest.json:marker'])
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0]);
});

test('(k) a sync run that fails in finalize leaves no price row and records a failed run', function (): void {
    // The bound fault: a trigger that fails the rollup's insert into price_daily, i.e. inside finalize, after every
    // chunk has upserted its price_points rows in the same transaction.
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION t04_finalize_fault() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 't04 finalize fault'; END $$;
        CREATE TRIGGER t04_finalize_fault BEFORE INSERT ON price_daily FOR EACH ROW EXECUTE FUNCTION t04_finalize_fault();
        SQL);

    [$code, $output] = t04Ingest('2026-10-11');

    $run = t04LastRun();
    expect($code)->toBe(1)
        ->and($output)->toContain('t04 finalize fault')
        ->and([$run['status'], $run['mode'], $run['day']])->toBe(['failed', 'sync', '2026-10-11'])
        ->and($run['error'])->toContain('t04 finalize fault')
        ->and($run['finished_at'])->not->toBeNull()
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0])
        ->and(DB::table('ingest_runs')->pluck('status')->all())->toBe(['failed']);
});

test('(l) with the pricing:ingest lock held by another connection the command exits 1 and writes nothing', function (): void {
    expect(IngestLock::KEY)->toBe(crc32('pricing:ingest'));

    config(['database.connections.t04_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t04_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        [$code, $output] = t04Ingest('2026-10-11');
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('t04_holder');
    }

    expect($code)->toBe(1)
        ->and($output)->toContain('pricing ingest lock held')
        ->and(DB::table('ingest_runs')->count())->toBe(0)
        ->and(t04Counts())->toBe(['price_points' => 0, 'price_daily' => 0, 'ingest_run_chunks' => 0]);
});

test('the lock is released after a run, so another connection can take it', function (): void {
    t04Ingest('2026-10-11');

    config(['database.connections.t04_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t04_holder');
    $taken = $holder->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [IngestLock::KEY])->ok;
    $holder->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t04_holder');

    expect($taken)->toBeTrue();
});

test('chunks of chunk_rows index the de-duplicated rows, and no statement upserts more than chunk_rows rows', function (): void {
    config(['pricing.chunk_rows' => 1]);
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into price_points')) {
            $statements[] = count($query->bindings);
        }
    });

    [$code, $output, $line] = t04Ingest('2026-10-11');

    $keys = DB::table('ingest_run_chunks')->orderBy('id')->pluck('chunk_key')->all();
    // prices/604 holds 42382, 657516 and the repeat of 42382: two de-duplicated rows, so no chunk 604:2.
    expect($code)->toBe(0, $output)
        ->and(array_values(array_filter($keys, fn (string $k): bool => str_starts_with($k, '604:'))))->toBe(['604:0', '604:1'])
        ->and(count($keys))->toBe(29)
        ->and((int) DB::table('ingest_run_chunks')->sum('rows'))->toBe(29)
        ->and(DB::table('ingest_run_chunks')->where('status', 'done')->count())->toBe(29)
        ->and($line)->toMatchArray(['rows' => 29, 'mapped' => 21, 'unmapped' => 8, 'null_market' => 2, 'upserted' => 21])
        ->and(t04Points('base1-4:holo:unlimited:-')[0]['market'])->toBe('70.81')
        ->and($statements)->toHaveCount(21)
        ->and(max($statements))->toBe(13);
});

test('the default chunk size plans one chunk per non-empty group at offset 0', function (): void {
    [$code] = t04Ingest('2026-10-11');

    expect($code)->toBe(0)
        ->and(DB::table('ingest_run_chunks')->orderBy('id')->get(['chunk_key', 'group_id', 'offset', 'rows', 'status'])->map(fn (object $r): array => (array) $r)->all())
        ->toBe([
            ['chunk_key' => '604:0', 'group_id' => 604, 'offset' => 0, 'rows' => 2, 'status' => 'done'],
            ['chunk_key' => '1663:0', 'group_id' => 1663, 'offset' => 0, 'rows' => 2, 'status' => 'done'],
            ['chunk_key' => '1840:0', 'group_id' => 1840, 'offset' => 0, 'rows' => 3, 'status' => 'done'],
            ['chunk_key' => '1418:0', 'group_id' => 1418, 'offset' => 0, 'rows' => 2, 'status' => 'done'],
            ['chunk_key' => '1398:0', 'group_id' => 1398, 'offset' => 0, 'rows' => 1, 'status' => 'done'],
            ['chunk_key' => '23821:0', 'group_id' => 23821, 'offset' => 0, 'rows' => 10, 'status' => 'done'],
            ['chunk_key' => '22872:0', 'group_id' => 22872, 'offset' => 0, 'rows' => 3, 'status' => 'done'],
            ['chunk_key' => '1543:0', 'group_id' => 1543, 'offset' => 0, 'rows' => 5, 'status' => 'done'],
            ['chunk_key' => '99001:0', 'group_id' => 99001, 'offset' => 0, 'rows' => 1, 'status' => 'done'],
        ]);
});

test('the rollup window recomputes a late day without touching a newer one', function (): void {
    t04Ingest('2026-10-12');
    $newer = t04Daily();
    [$code, , $line] = t04Ingest('2026-10-11');

    // P = 10-12, D = 10-11: the window is 10-11 .. 10-12; 10-12's candles are recomputed unchanged.
    expect($code)->toBe(0)
        ->and($line['daily_rows'])->toBe(19 + 17)
        ->and(array_values(array_filter(t04Daily(), fn (array $r): bool => $r['day'] === '2026-10-12')))->toBe($newer);
});

test('PriceDigest records the map build and epochs and covers price_points and price_daily only', function (): void {
    t04Ingest('2026-10-11');
    $digest = PriceDigest::compute(DB::connection());

    expect(array_keys($digest))->toBe(['map_build_id', 'map_epochs', 'tables'])
        ->and($digest['map_build_id'])->toBe((int) DB::table('map_builds')->max('id'))
        ->and($digest['map_epochs'])->toBe(['1' => 22])
        ->and(array_keys($digest['tables']))->toBe(['price_points', 'price_daily'])
        ->and($digest['tables']['price_points']['sha256'])->toMatch('/\A[0-9a-f]{64}\z/');

    // ingest_run_id is volatile bookkeeping: rewriting it leaves the digest alone, rewriting a price does not.
    $runId = DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-11',
        'mode' => 'sync', 'status' => 'failed', 'started_at' => now()]);
    DB::table('price_points')->update(['ingest_run_id' => $runId]);
    expect(PriceDigest::compute(DB::connection()))->toBe($digest);

    DB::table('price_daily')->where('variant_key', 'tk1a-1:normal:-:-')->update(['close' => '0.08', 'high' => '0.08']);
    expect(PriceDigest::compute(DB::connection())['tables']['price_daily']['sha256'])->not->toBe($digest['tables']['price_daily']['sha256'])
        ->and(PriceDigest::compute(DB::connection())['tables']['price_points'])->toBe($digest['tables']['price_points']);
});

test('--digest adds the PriceDigest to the JSON line', function (): void {
    [$code, $output, $line] = t04Ingest('2026-10-11', null, ['--digest' => true]);

    expect($code)->toBe(0, $output)
        ->and($line['digest'])->toBe(PriceDigest::compute(DB::connection()));
});

test('the command refuses a malformed --day and a missing --sync without writing a run', function (array $options): void {
    $code = Artisan::call('pokeprices:prices:ingest', ['--snapshots' => t04Fixture('snapshots'), ...$options]);

    expect($code)->toBe(1)
        ->and(DB::table('ingest_runs')->count())->toBe(0);
})->with([
    'no --day' => [['--sync' => true]],
    'a malformed --day' => [['--day' => '2026-10-32', '--sync' => true]],
    'no --sync' => [['--day' => '2026-10-11']],
]);
