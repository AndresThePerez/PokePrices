<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The windowed price_latest rebuild (plan Q1-i3 T04, finding B10): a finalize recomputes only the set S of variants
// whose newest real close or previous close the ingest's D3 window [from, to] can change, the variants with a real
// price_daily row in [from, to + cap] after the rollup plus the variants whose price_latest day or previous_day is at
// least from, and writes no other row. The whole-table SELECT LatestPrices ran before T04 is kept verbatim below as
// the oracle: after every ingest of every scenario price_latest equals it row by row as text, and every row outside S
// keeps its xmin. The fixture tree is only read; edited days are copies under a scratch root that afterEach removes.

/** LatestPrices::rebuild()'s SELECT before T04, verbatim (bindings: source, fill cap). */
const Q1T04L_ORACLE = <<<'SQL'
    SELECT l.variant_key, l.source, l.series_epoch, l.close, l.day, l.as_of,
           p.close, p.day, l.close - p.close,
           CASE WHEN p.close IS NULL OR p.close = 0 THEN NULL ELSE round((l.close - p.close) / p.close * 100, 2) END
    FROM (
        SELECT DISTINCT ON (d.variant_key, d.source) d.variant_key, d.source, d.series_epoch, d.close, d.day, d.as_of
        FROM price_daily d
        WHERE d.source = ? AND NOT d.is_filled
        ORDER BY d.variant_key, d.source, d.day DESC
    ) l
    LEFT JOIN LATERAL (
        SELECT q.close, q.day
        FROM price_daily q
        WHERE q.variant_key = l.variant_key AND q.source = l.source AND NOT q.is_filled
          AND q.series_epoch = l.series_epoch AND q.day < l.day AND q.day >= l.day - ?::integer
        ORDER BY q.day DESC
        LIMIT 1
    ) p ON true
    SQL;

const Q1T04L_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function q1t04lFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** The oracle's rows as text, variant_key => row. */
function q1t04lOracle(): array
{
    $rows = DB::select(
        'SELECT o.variant_key, ROW(o.variant_key, o.source, o.series_epoch, o.close, o.day, o.as_of, o.previous_close, o.previous_day, o.change, o.change_pct)::text AS r
         FROM ('.Q1T04L_ORACLE.') AS o(variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)
         ORDER BY o.variant_key COLLATE "C"',
        ['tcgplayer', (int) config('pricing.fill_cap_days')],
    );

    return array_column(array_map(fn (object $r): array => (array) $r, $rows), 'r', 'variant_key');
}

/** price_latest as variant_key => [r (the row as text), xmin, day, previous_day]. */
function q1t04lLatest(): array
{
    $out = [];
    foreach (DB::select(<<<'SQL'
        SELECT variant_key,
               ROW(variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)::text AS r,
               xmin::text AS xmin, day::text AS day, previous_day::text AS previous_day
        FROM price_latest ORDER BY variant_key COLLATE "C"
        SQL) as $row) {
        $out[$row->variant_key] = ['r' => $row->r, 'xmin' => $row->xmin, 'day' => $row->day, 'previous_day' => $row->previous_day];
    }

    return $out;
}

/** Asserts price_latest equals the oracle row by row as text. */
function q1t04lMatchesOracle(): void
{
    expect(array_map(fn (array $row): string => $row['r'], q1t04lLatest()))->toBe(q1t04lOracle());
}

/**
 * Ingests $day with --sync from $root (default: the fixture tree), then asserts that price_latest equals the oracle
 * and that every row outside S kept its xmin. Returns the number of rows outside S it checked.
 */
function q1t04lIngest(string $day, ?string $root = null): int
{
    $before = q1t04lLatest();
    $newest = DB::selectOne("SELECT max(day)::text AS day FROM ingest_runs WHERE source = 'tcgplayer' AND status = 'ok'")->day;

    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $root ?? q1t04lFixture('snapshots')]);
    expect($code)->toBe(0, Artisan::output());

    // The D3 window of DailyRollup: LEAST(D, P + 1) .. LEAST(D + cap, N).
    $cap = (int) config('pricing.fill_cap_days');
    $d = CarbonImmutable::parse($day, 'UTC');
    $p = is_string($newest) ? CarbonImmutable::parse($newest, 'UTC') : null;
    $from = ($p === null ? $d : $d->min($p->addDay()))->format('Y-m-d');
    $to = $d->addDays($cap)->min($p === null || $d->greaterThan($p) ? $d : $p);
    $s = DB::table('price_daily')->where('source', 'tcgplayer')->where('is_filled', false)
        ->whereBetween('day', [$from, $to->addDays($cap)->format('Y-m-d')])->distinct()->pluck('variant_key')->all();
    foreach ($before as $key => $row) {
        if ($row['day'] >= $from || ($row['previous_day'] !== null && $row['previous_day'] >= $from)) {
            $s[] = $key;
        }
    }
    $outside = array_diff_key($before, array_flip($s));

    q1t04lMatchesOracle();
    $after = q1t04lLatest();
    expect(array_map(fn (string $key): ?string => $after[$key]['xmin'] ?? null, array_keys($outside)))
        ->toBe(array_column($outside, 'xmin'));

    return count($outside);
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function q1t04lRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Copies the named fixture days under a fresh scratch root that afterEach removes. */
function q1t04lCopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-q1t04l-'.bin2hex(random_bytes(6)));
    q1t04lRoots($root);
    foreach ($days as $day) {
        $src = q1t04lFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/** Gives a scratch day a later marker on the same UTC date, so it ingests as a re-collection. */
function q1t04lRemark(string $root, string $day, string $marker): void
{
    file_put_contents("{$root}/{$day}/marker.txt", $marker."\n");
    $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$root}/{$day}/manifest.json", json_encode(['marker' => $marker] + $manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Rewrites the prices file of group $group of a scratch day through $edit. */
function q1t04lEditPrices(string $root, string $day, int $group, Closure $edit): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    $document['results'] = $edit($document['results']);
    file_put_contents($path, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** Every file under $dir as relative path => sha1. */
function q1t04lTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

beforeEach(function (): void {
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
    $this->fixtureTree = q1t04lTree(q1t04lFixture('snapshots'));
});

afterEach(function (): void {
    expect(q1t04lTree(q1t04lFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (q1t04lRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('in order: every ingest leaves price_latest equal to the whole-table oracle and rewrites no row outside S', function (): void {
    $outside = 0;
    foreach (Q1T04L_DAYS as $day) {
        $outside += q1t04lIngest($day);
    }

    // 10-13 was never collected: its candles are filled, and price_latest never reads them.
    expect(DB::table('price_daily')->where('is_filled', true)->count())->toBeGreaterThan(0)
        ->and(DB::table('price_latest')->count())->toBeGreaterThan(0)
        ->and($outside)->toBeGreaterThan(0);
});

test('out of order: 10-11 after 10-14 leaves price_latest equal to the oracle and rewrites no row outside S', function (): void {
    $outside = 0;
    foreach (['2026-10-09', '2026-10-10', '2026-10-12', '2026-10-14', '2026-10-11'] as $day) {
        $outside += q1t04lIngest($day);
    }

    expect($outside)->toBeGreaterThan(0);
});

test('descending: each day older than the last leaves price_latest equal to the oracle', function (): void {
    foreach (array_reverse(Q1T04L_DAYS) as $day) {
        q1t04lIngest($day);
    }

    expect(DB::table('price_latest')->count())->toBeGreaterThan(0);
});

test('a replay leaves price_latest equal to the oracle', function (): void {
    q1t04lIngest('2026-10-10');
    $code = Artisan::call('pokeprices:prices:replay', ['--snapshots' => q1t04lFixture('snapshots'), '--fresh' => true, '--order' => 'desc']);

    expect($code)->toBe(0, Artisan::output());
    q1t04lMatchesOracle();
    expect(DB::table('price_latest')->count())->toBeGreaterThan(0);
});

test('a variant whose window candles vanish from a re-collected folder gets the oracle row back', function (string $day): void {
    foreach (Q1T04L_DAYS as $d) {
        q1t04lIngest($d);
    }
    $before = q1t04lLatest()['ex10-!:holo:-:-'];
    $root = q1t04lCopyDays($day);
    q1t04lRemark($root, $day, "{$day}T22:10:05+0000");
    // ex10-! (product 90166, group 1398) drops out of the later copy: its candle of that day vanishes.
    q1t04lEditPrices($root, $day, 1398, fn (array $rows): array => array_values(array_filter($rows, fn (array $r): bool => $r['productId'] !== 90166)));

    q1t04lIngest($day, $root);

    expect(q1t04lLatest()['ex10-!:holo:-:-']['r'] ?? null)->not->toBe($before['r']);
})->with([
    // ex10-!'s newest close is on 10-14: it falls back to 10-12.
    'the newest day' => ['2026-10-14'],
    // ex10-!'s previous close is on 10-12: it falls back to 10-11.
    'an older day' => ['2026-10-12'],
]);

test('an epoch break leaves price_latest equal to the oracle', function (): void {
    foreach (['2026-10-09', '2026-10-10', '2026-10-11'] as $day) {
        q1t04lIngest($day);
    }
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    q1t04lIngest('2026-10-12');
    q1t04lIngest('2026-10-14');

    expect(DB::table('price_latest')->where('variant_key', 'basep-8:holo:-:-')->value('series_epoch'))->toBe(2);
});

test('a previous close of 0.00 leaves price_latest equal to the oracle', function (): void {
    $root = q1t04lCopyDays('2026-10-10', '2026-10-11');
    q1t04lEditPrices($root, '2026-10-10', 1543, fn (array $rows): array => array_map(
        fn (array $r): array => $r['productId'] === 83694 && $r['subTypeName'] === 'Normal' ? ['marketPrice' => 0] + $r : $r, $rows,
    ));
    q1t04lIngest('2026-10-10', $root);
    q1t04lIngest('2026-10-11', $root);

    expect(DB::table('price_latest')->where('variant_key', 'tk1a-1:normal:-:-')->selectRaw('previous_close::text AS p, change_pct')->first())
        ->toEqual((object) ['p' => '0.00', 'change_pct' => null]);
});
