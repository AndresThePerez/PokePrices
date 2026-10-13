<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The 14-day carry-forward and the order-independent rollup (plan P2-i1 T07 items 1, 2 and 4, D3, D4, review F3).
// The five synthetic days of tests/Fixtures/tcgcsv-days (09, 10, 11, 12, 14; no 13) are ingested with
// pokeprices:prices:ingest --sync over the map built from the P1 fixture day. Longer series are relabelled copies of
// a fixture day under a scratch root; the fixture tree itself is only ever read.

const T07C_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const T07C_MARKER_09 = '2026-10-09T20:05:41Z';

const T07C_MARKER_11 = '2026-10-11T20:05:45Z';

const T07C_MARKER_12 = '2026-10-12T20:05:44Z';

function t07cFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Runs the sync ingest of each day in the given order and returns the exit codes with their output. */
function t07cIngest(array $days, ?string $snapshots = null): array
{
    $out = [];
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t07cFixture('snapshots')]);
        $out[$day] = [$code, Artisan::output()];
    }

    return $out;
}

/** The exit codes of t07cIngest(), keyed by day. */
function t07cCodes(array $results): array
{
    return array_map(fn (array $r): int => $r[0], $results);
}

/** Empties the price tables and the run history, so the next order starts from nothing. */
function t07cWipe(): void
{
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

/** The price_daily rows as text in primary-key order, optionally for one variant or one day. */
function t07cDaily(?string $variant = null, ?string $day = null): array
{
    return array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
        SELECT variant_key, day::text AS day, series_epoch, open::text AS open, high::text AS high, low::text AS low,
               close::text AS close, n_obs, is_filled, filled_from::text AS filled_from,
               to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
        FROM price_daily
        WHERE (?::text IS NULL OR variant_key = ?) AND (?::date IS NULL OR day = ?::date)
        ORDER BY variant_key COLLATE "C", day
        SQL, [$variant, $variant, $day, $day]));
}

/** One expected price_daily row as t07cDaily() returns it. */
function t07cRow(string $variant, string $day, string $close, ?string $filledFrom, string $asOf): array
{
    return [
        'variant_key' => $variant, 'day' => $day, 'series_epoch' => 1, 'open' => $close, 'high' => $close,
        'low' => $close, 'close' => $close, 'n_obs' => $filledFrom === null ? 1 : 0, 'is_filled' => $filledFrom !== null,
        'filled_from' => $filledFrom, 'as_of' => $asOf,
    ];
}

/** The variant key the map gives (productId, subTypeName). */
function t07cVariant(int $productId, string $subtype): string
{
    return (string) DB::table('source_product_map')->where('source', 'tcgplayer')
        ->where('source_product_id', $productId)->where('source_subtype', $subtype)->value('variant_key');
}

/** Copies fixture day $from to $root/$to, relabelling marker.txt and manifest.json when the name changes. */
function t07cCopyDay(string $root, string $from, ?string $to = null): void
{
    $to ??= $from;
    $src = t07cFixture('snapshots/'.$from);
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
function t07cScratch(): string
{
    $root = storage_path('framework/testing/pp-t07c-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t07cRoots($root);

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t07cRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** The 16-calendar-day copy: the five fixture days plus 2026-10-15 .. 2026-10-24, each a relabelled 10-14. */
function t07cSixteenDays(): array
{
    $root = t07cScratch();
    foreach (T07C_DAYS as $day) {
        t07cCopyDay($root, $day);
    }
    $days = T07C_DAYS;
    for ($d = 15; $d <= 24; $d++) {
        t07cCopyDay($root, '2026-10-14', "2026-10-{$d}");
        $days[] = "2026-10-{$d}";
    }

    return [$root, $days];
}

/** Every file under $dir as relative path => sha1. */
function t07cTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
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
    $this->fixtureTree = t07cTree(t07cFixture('snapshots'));
});

afterEach(function (): void {
    expect(t07cTree(t07cFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t07cRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('the absent-then-back variant is filled on 10-12 and the gap day 10-13 from 10-11, and real again on 10-14', function (): void {
    expect(t07cCodes(t07cIngest(T07C_DAYS)))->toBe(array_fill_keys(T07C_DAYS, 0));

    // basep-13 (85001) closes 12.00, 12.20 and 12.40, is absent on 10-12 and back at 12.90 on 10-14.
    expect(t07cDaily('basep-13:holo:-:-'))->toBe([
        t07cRow('basep-13:holo:-:-', '2026-10-09', '12.00', null, T07C_MARKER_09),
        t07cRow('basep-13:holo:-:-', '2026-10-10', '12.20', null, '2026-10-10T20:05:42Z'),
        t07cRow('basep-13:holo:-:-', '2026-10-11', '12.40', null, T07C_MARKER_11),
        t07cRow('basep-13:holo:-:-', '2026-10-12', '12.40', '2026-10-11', T07C_MARKER_11),
        t07cRow('basep-13:holo:-:-', '2026-10-13', '12.40', '2026-10-11', T07C_MARKER_11),
        t07cRow('basep-13:holo:-:-', '2026-10-14', '12.90', null, '2026-10-14T20:05:46Z'),
    ])
        // sv8pt5-7's null market on 10-12 is no observation: it is filled from 10-11 like an absent row.
        ->and(t07cDaily('sv8pt5-7:normal:-:-', '2026-10-12'))->toBe([t07cRow('sv8pt5-7:normal:-:-', '2026-10-12', '0.27', '2026-10-11', T07C_MARKER_11)]);
});

test('ascending and the order 14, 12 after 09-11 give byte-equal price_daily with the 10-13 filled rows in both (review F3)', function (): void {
    $ex10 = t07cVariant(90167, 'Holofoil');
    $expected13 = [
        // filled from 10-12's real closes (17 variants have one) ...
        t07cRow('base1-4:holo:unlimited:-', '2026-10-13', '71.20', '2026-10-12', T07C_MARKER_12),
        // ... from 10-11 for the variant absent on 10-12 and the one with a null market there ...
        t07cRow('basep-13:holo:-:-', '2026-10-13', '12.40', '2026-10-11', T07C_MARKER_11),
        t07cRow('sv8pt5-7:normal:-:-', '2026-10-13', '0.27', '2026-10-11', T07C_MARKER_11),
        // ... and from 10-09 for the variant seen only that day.
        t07cRow($ex10, '2026-10-13', '15.55', '2026-10-09', T07C_MARKER_09),
    ];

    expect(t07cCodes(t07cIngest(T07C_DAYS)))->toBe(array_fill_keys(T07C_DAYS, 0));
    $ascending = t07cDaily();
    $ascendingDigest = PriceDigest::compute(DB::connection())['tables'];
    $ascending13 = t07cDaily(null, '2026-10-13');

    t07cWipe();
    $order = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-14', '2026-10-12'];
    expect(t07cCodes(t07cIngest($order)))->toBe(array_fill_keys($order, 0));
    $other = t07cDaily();
    $other13 = t07cDaily(null, '2026-10-13');

    usort($expected13, fn (array $a, array $b): int => strcmp($a['variant_key'], $b['variant_key']));
    $named = fn (array $rows): array => array_values(array_filter($rows, fn (array $r): bool => in_array($r['variant_key'], array_column($expected13, 'variant_key'), true)));

    // 17 variants with a real 10-12 close, plus basep-13, sv8pt5-7 and the 10-09-only variant: 20 filled rows.
    expect($ascending13)->toHaveCount(20)
        ->and($other13)->toHaveCount(20)
        ->and($named($ascending13))->toBe($expected13)
        ->and($named($other13))->toBe($expected13)
        ->and(array_unique(array_column($ascending13, 'is_filled')))->toBe([true])
        ->and(count(array_filter($ascending13, fn (array $r): bool => $r['filled_from'] === '2026-10-12')))->toBe(17)
        ->and($other13)->toBe($ascending13)
        ->and($other)->toBe($ascending)
        ->and(PriceDigest::compute(DB::connection())['tables']['price_daily'])->toBe($ascendingDigest['price_daily']);
});

test('ascending, descending and 12, 09, 14, 10, 11 give equal digests of price_points, price_daily and price_latest', function (): void {
    $digests = [];
    foreach (['asc' => T07C_DAYS, 'desc' => array_reverse(T07C_DAYS), 'shuffled' => ['2026-10-12', '2026-10-09', '2026-10-14', '2026-10-10', '2026-10-11']] as $name => $order) {
        t07cWipe();
        expect(t07cCodes(t07cIngest($order)))->toBe(array_fill_keys($order, 0));
        $digests[$name] = PriceDigest::compute(DB::connection())['tables'];
    }

    // 20 variants a day from 10-09 to 10-14: 94 real candles and 26 filled ones (10-09-only variant on 10 to 14,
    // basep-13 and sv8pt5-7 on 12 and 13, the 17 real 10-12 closes on 13), so the orders are compared with fills.
    expect(array_keys($digests['asc']))->toBe(['price_points', 'price_daily', 'price_latest'])
        ->and($digests['asc']['price_daily']['rows'])->toBe(120)
        ->and(DB::table('price_daily')->where('is_filled', true)->count())->toBe(26)
        ->and($digests['desc'])->toBe($digests['asc'])
        ->and($digests['shuffled'])->toBe($digests['asc']);
});

test('a variant seen only on 10-09 is filled through 10-23 and has no row on 10-24, in either order (the 14-day cap)', function (): void {
    [$root, $days] = t07cSixteenDays();
    $ex10 = t07cVariant(90167, 'Holofoil');
    $expected = [t07cRow($ex10, '2026-10-09', '15.55', null, T07C_MARKER_09)];
    for ($d = 10; $d <= 23; $d++) {
        $expected[] = t07cRow($ex10, "2026-10-{$d}", '15.55', '2026-10-09', T07C_MARKER_09);
    }

    expect(t07cCodes(t07cIngest($days, $root)))->toBe(array_fill_keys($days, 0));
    $ascending = PriceDigest::compute(DB::connection())['tables'];
    $ascendingRows = t07cDaily($ex10);

    t07cWipe();
    expect(t07cCodes(t07cIngest(array_reverse($days), $root)))->toBe(array_fill_keys(array_reverse($days), 0));

    expect($ascendingRows)->toBe($expected)
        ->and(t07cDaily($ex10))->toBe($expected)
        ->and(t07cDaily($ex10, '2026-10-24'))->toBe([])
        ->and(DB::table('price_daily')->where('day', '2026-10-24')->where('variant_key', '<>', $ex10)->count())->toBeGreaterThan(0)
        ->and(PriceDigest::compute(DB::connection())['tables'])->toBe($ascending);
});

test('a real close after a 15-day gap has no previous close although filled rows lie between', function (): void {
    $root = t07cScratch();
    t07cCopyDay($root, '2026-10-09');
    t07cCopyDay($root, '2026-10-09', '2026-10-24');

    expect(t07cCodes(t07cIngest(['2026-10-09', '2026-10-24'], $root)))->toBe(['2026-10-09' => 0, '2026-10-24' => 0]);

    $latest = (array) DB::selectOne(<<<'SQL'
        SELECT close::text AS close, day::text AS day, previous_close, previous_day, change, change_pct
        FROM price_latest WHERE variant_key = 'base1-4:holo:unlimited:-'
        SQL);
    expect(t07cDaily('base1-4:holo:unlimited:-', '2026-10-23'))->toBe([t07cRow('base1-4:holo:unlimited:-', '2026-10-23', '69.50', '2026-10-09', T07C_MARKER_09)])
        ->and($latest)->toBe(['close' => '69.50', 'day' => '2026-10-24', 'previous_close' => null, 'previous_day' => null, 'change' => null, 'change_pct' => null]);
});

test('a filled row is never the close of price_latest', function (): void {
    // Through 10-12 only: basep-13's newest row is the 10-12 fill, so its latest close is the 10-11 real one.
    $days = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'];
    expect(t07cCodes(t07cIngest($days)))->toBe(array_fill_keys($days, 0));
    $ex10 = t07cVariant(90167, 'Holofoil');

    $latest = fn (string $variant): array => (array) DB::selectOne(
        'SELECT close::text AS close, day::text AS day, previous_close::text AS previous_close, previous_day::text AS previous_day FROM price_latest WHERE variant_key = ?',
        [$variant],
    );
    $filledLatest = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n FROM price_latest l
        JOIN price_daily d ON d.variant_key = l.variant_key AND d.source = l.source AND d.day = l.day
        WHERE d.is_filled
        SQL)->n;

    expect(t07cDaily('basep-13:holo:-:-', '2026-10-12'))->toBe([t07cRow('basep-13:holo:-:-', '2026-10-12', '12.40', '2026-10-11', T07C_MARKER_11)])
        ->and($latest('basep-13:holo:-:-'))->toBe(['close' => '12.40', 'day' => '2026-10-11', 'previous_close' => '12.20', 'previous_day' => '2026-10-10'])
        ->and($latest($ex10))->toBe(['close' => '15.55', 'day' => '2026-10-09', 'previous_close' => null, 'previous_day' => null])
        ->and(DB::table('price_daily')->where('variant_key', $ex10)->where('is_filled', true)->count())->toBe(3)
        ->and($filledLatest)->toBe(0);
});
