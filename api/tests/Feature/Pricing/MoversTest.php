<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\PriceDigest;
use App\Domain\Pricing\Replay;
use App\Http\Problems\ProblemDetails;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogCache;
use Tests\Support\ForbiddenWords;

// price_movers and GET /v1/movers (plan P2-i1 T12, D7, R177(10), R178, R180b, R181(1)). The map is built from the
// P1 fixture day and the synthetic days of tests/Fixtures/tcgcsv-days are ingested with --sync; edited days are
// copies under a scratch root that afterEach removes, and the fixture tree itself is only ever read. Every /v1
// response is also validated against openapi/api.json by Tests\TestCase (C3).
//
// The movers rank a move only when it is at least 5 % and 1.00, its series shows four distinct values among its five
// most recent real closes and both closes are in line with their listings (MoversEligibilityTest pins each edge). The
// fixture days alone hold one such move, base1-8 1st shadowless on 10-14, so the cases that need a board with several
// rows ingest t12MoversRoot(), a scratch copy with five movers on 10-12.

const T12_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function t12Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync from $snapshots (default: the fixture tree). */
function t12Ingest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t12Fixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Empties the price tables and the runs, so another ingest order starts from nothing. */
function t12Reset(): void
{
    foreach (['price_movers', 'price_latest', 'price_daily', 'price_points', 'ingest_run_chunks', 'ingest_runs'] as $table) {
        DB::table($table)->delete();
    }
}

/** The movers of $day in $direction as "rank variant_key close previous_close previous_day change change_pct epoch card". */
function t12Movers(string $day, string $direction): array
{
    return array_map(
        fn (object $r): string => "{$r->rank} {$r->variant_key} {$r->close} {$r->previous_close} {$r->previous_day} {$r->change} {$r->change_pct} {$r->series_epoch} {$r->card_id}",
        DB::select(<<<'SQL'
            SELECT rank, variant_key, close::text AS close, previous_close::text AS previous_close,
                   previous_day::text AS previous_day, change::text AS change, change_pct::text AS change_pct, series_epoch, card_id
            FROM price_movers WHERE day = ?::date AND direction = ? ORDER BY rank
            SQL, [$day, $direction]),
    );
}

/** The variant_keys of the movers of $day in $direction, by rank. */
function t12Keys(string $day, string $direction): array
{
    return DB::table('price_movers')->where('day', $day)->where('direction', $direction)->orderBy('rank')->pluck('variant_key')->all();
}

/** Copies the named fixture days under a fresh scratch root that afterEach removes. */
function t12CopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t12-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t12Roots($root);
    foreach ($days as $day) {
        $src = t12Fixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t12Roots(?string $add = null): array
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
function t12SetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T12_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T12_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/**
 * A scratch copy of 10-09 to 10-12 in which five variants are movers on 10-12 under the eligibility rule: each shows
 * four distinct closes over the four days, moves at least 5 % and 1.00 on 10-12, and stays in line with the listings
 * the fixture stores for it. The fixture's own 10-12 steps are all under 5 %, so only these closes are set:
 *
 * - basep-8 (85000): 24.50, 25.00, 28.00, then 31.36 (+3.36, +12.00 %);
 * - svp-5 stamp-prerelease-staff (522650): 40.00, 41.00, 42.00, then 46.20 (+4.20, +10.00 %);
 * - sv8pt5-5 reverse pokeball (610540): 30.00, 31.00, 32.00, then 35.20 (+3.20, +10.00 %);
 * - svp-46 (517271): 5.10, 5.00, 4.70, then 3.70 (-1.00, -21.28 %);
 * - base1-8 1st shadowless (107004): 120.40, 121.00, 119.80, then 112.00 (-7.80, -6.51 %).
 */
function t12MoversRoot(): string
{
    $root = t12CopyDays('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    t12SetMarket($root, '2026-10-12', 1418, 85000, 'Holofoil', '31.36');
    t12SetMarket($root, '2026-10-12', 22872, 522650, 'Holofoil', '46.20');
    t12SetMarket($root, '2026-10-12', 23821, 610540, 'Holofoil', '35.20');
    t12SetMarket($root, '2026-10-12', 22872, 517271, 'Holofoil', '3.70');
    t12SetMarket($root, '2026-10-12', 1840, 107004, '1st Edition Holofoil', '112.00');

    return $root;
}

/** The four days of t12MoversRoot(), which t12Ingest() reads in order. */
const T12_MOVERS_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'];

/** Every file under $dir as relative path => sha1. */
function t12Tree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** The SQLSTATE and constraint a statement fails with inside a savepoint, or null when it succeeds. */
function t12Violation(Closure $statement): ?array
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
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
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
    // P3-i1 T08: the movers name their cards through the catalog; PokéSearch is faked for every fixture card id and
    // the catalog keys live under a prefix of this test's own, flushed when the application is torn down.
    $catalogPrefix = CatalogCache::isolate();
    $this->beforeApplicationDestroyed(fn () => CatalogCache::flush($catalogPrefix));
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::fake(function (Request $request) {
        if (parse_url($request->url(), PHP_URL_PATH) === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 1,
                'seed' => ['ref' => '0123456789abcdef0123456789abcdef01234567', 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'fake-meta']);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'name' => "Name of {$id}",
            'number' => substr((string) strrchr($id, '-'), 1), 'set_name' => 'Fixture set'], explode(',', (string) $query['ids'])), 'missing' => []]);
    });
    $this->fixtureTree = t12Tree(t12Fixture('snapshots'));
});

afterEach(function (): void {
    expect(t12Tree(t12Fixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t12Roots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('price_movers has exactly the planned columns, all NOT NULL, money and change_pct numeric(12,2)', function (): void {
    $columns = array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}"
            .($c->data_type === 'numeric' ? "({$c->numeric_precision},{$c->numeric_scale})" : '')
            .':'.($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL'),
        DB::select("SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = 'price_movers' ORDER BY ordinal_position"),
    );

    expect($columns)->toBe([
        'day:date:NOT NULL',
        'source:text:NOT NULL',
        'direction:text:NOT NULL',
        'rank:integer:NOT NULL',
        'variant_key:text:NOT NULL',
        'card_id:text:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'close:numeric(12,2):NOT NULL',
        'previous_close:numeric(12,2):NOT NULL',
        'previous_day:date:NOT NULL',
        'change:numeric(12,2):NOT NULL',
        'change_pct:numeric(12,2):NOT NULL',
        'as_of:timestamp with time zone:NOT NULL',
    ]);
});

test('each price_movers key and CHECK rejects a violating row', function (array $overrides, string $state, string $constraint): void {
    $row = ['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1, 'variant_key' => 'basep-8:holo:-:-',
        'card_id' => 'basep-8', 'series_epoch' => 1, 'close' => '28.00', 'previous_close' => '25.00', 'previous_day' => '2026-10-10',
        'change' => '3.00', 'change_pct' => '12.00', 'as_of' => '2026-10-11 20:05:45+00'];
    DB::table('price_movers')->insert($row);

    expect(t12Violation(fn () => DB::table('price_movers')->insert(array_merge($row, $overrides))))->toBe([$state, $constraint]);
})->with([
    'the same rank twice' => [['variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46'], '23505', 'price_movers_pkey'],
    'the same variant twice in a direction' => [['rank' => 2], '23505', 'price_movers_day_source_direction_variant_key_unique'],
    'another source' => [['source' => 'tcgcsv', 'rank' => 2], '23514', 'price_movers_source_check'],
    'another direction' => [['direction' => 'flat', 'rank' => 2, 'change' => '-0.30', 'change_pct' => '-1.20'], '23514', 'price_movers_direction_check'],
    'rank 0' => [['rank' => 0, 'variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46'], '23514', 'price_movers_rank_check'],
    'up with a fall' => [['rank' => 2, 'variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46', 'change' => '-0.30', 'change_pct' => '-6.00'], '23514', 'price_movers_change_direction_check'],
    'down with a rise' => [['direction' => 'down'], '23514', 'price_movers_change_direction_check'],
    'down with no change' => [['direction' => 'down', 'change' => '0.00', 'change_pct' => '0.00'], '23514', 'price_movers_change_check'],
    'unknown variant' => [['rank' => 2, 'variant_key' => 'basep-8:holo:-:nope'], '23503', 'price_movers_variant_key_foreign'],
]);

test('finalize ranks the movers of 10-12 vs 10-11 per D7: +12 % basep-8 first up, abs(change) breaking a percent tie', function (): void {
    t12Ingest(T12_MOVERS_DAYS, t12MoversRoot());

    expect(t12Movers('2026-10-12', 'up'))->toBe([
        '1 basep-8:holo:-:- 31.36 28.00 2026-10-11 3.36 12.00 1 basep-8',
        // Both +10.00 %: +4.20 ranks before +3.20.
        '2 svp-5:holo:-:stamp-prerelease-staff 46.20 42.00 2026-10-11 4.20 10.00 1 svp-5',
        '3 sv8pt5-5:reverse:-:pokeball 35.20 32.00 2026-10-11 3.20 10.00 1 sv8pt5-5',
    ])
        ->and(t12Movers('2026-10-12', 'down'))->toBe([
            '1 svp-46:holo:-:- 3.70 4.70 2026-10-11 -1.00 -21.28 1 svp-46',
            '2 base1-8:holo:1st:shadowless 112.00 119.80 2026-10-11 -7.80 -6.51 1 base1-8',
        ])
        // The steps the fixture leaves alone on 10-12 are all under 5 %, and 10-09 to 10-11 cannot show four closes
        // yet (basep-8's +12 % on 10-11 included): no movers on them.
        ->and(DB::table('price_movers')->where('day', '<>', '2026-10-12')->count())->toBe(0);
});

test('a previous close of 1.99 is excluded and 2.00 is included', function (string $previous, bool $included): void {
    // sv8pt5-5 holo closes at 1.80, 1.90, the previous close under test, then 3.10: four distinct closes and a move of
    // more than 1.00 and 5 % either way, so only the floor decides.
    $root = t12CopyDays('2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    t12SetMarket($root, '2026-10-09', 23821, 610360, 'Holofoil', '1.80');
    t12SetMarket($root, '2026-10-10', 23821, 610360, 'Holofoil', '1.90');
    t12SetMarket($root, '2026-10-11', 23821, 610360, 'Holofoil', $previous);
    t12SetMarket($root, '2026-10-12', 23821, 610360, 'Holofoil', '3.10');
    t12Ingest(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'], $root);

    expect(in_array('sv8pt5-5:holo:-:-', t12Keys('2026-10-12', 'up'), true))->toBe($included);
})->with([
    '1.99' => ['1.99', false],
    '2.00' => ['2.00', true],
]);

test('variants equal in abs(change_pct) and abs(change) rank by variant_key', function (): void {
    $root = t12CopyDays(...T12_DAYS);
    // On 10-14 basep-13 and ex10-! both go 10.00 -> 11.00 (+10 %, +$1.00) and basep-8 goes 20.00 -> 22.00 (+10 %,
    // +$2.00), each with four or five distinct closes behind it; base1-8 1st shadowless is the fixture's own +5.93 %.
    t12SetMarket($root, '2026-10-11', 1418, 85001, 'Holofoil', '10.00');
    t12SetMarket($root, '2026-10-14', 1418, 85001, 'Holofoil', '11.00');
    t12SetMarket($root, '2026-10-12', 1398, 90166, 'Holofoil', '10.00');
    t12SetMarket($root, '2026-10-14', 1398, 90166, 'Holofoil', '11.00');
    t12SetMarket($root, '2026-10-12', 1418, 85000, 'Holofoil', '20.00');
    t12SetMarket($root, '2026-10-14', 1418, 85000, 'Holofoil', '22.00');
    t12Ingest(T12_DAYS, $root);

    expect(t12Keys('2026-10-14', 'up'))->toBe(['basep-8:holo:-:-', 'basep-13:holo:-:-', 'ex10-!:holo:-:-', 'base1-8:holo:1st:shadowless']);
});

test('an epoch change on the day excludes the variant', function (): void {
    $root = t12MoversRoot();
    t12Ingest(['2026-10-09', '2026-10-10', '2026-10-11'], $root);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    t12Ingest(['2026-10-12'], $root);

    // basep-8 would lead at +12 %; the next variant leads instead.
    expect(t12Keys('2026-10-12', 'up'))->not->toContain('basep-8:holo:-:-')
        ->and(t12Keys('2026-10-12', 'up')[0])->toBe('svp-5:holo:-:stamp-prerelease-staff');
});

test('a filled day never appears, and a real close after fills compares with the last real close', function (): void {
    // basep-13 is absent on 10-12 and the gap day 10-13; its 10-14 close is set to 13.64 so the step from 10-11 is one
    // the movers rank (+1.24, +10.00 %; 12.00, 12.20, 12.40 and 13.64 are four distinct closes).
    $root = t12CopyDays(...T12_DAYS);
    t12SetMarket($root, '2026-10-14', 1418, 85001, 'Holofoil', '13.64');
    t12Ingest(T12_DAYS, $root);

    expect(DB::table('price_movers')->where('day', '2026-10-13')->count())->toBe(0)
        ->and(DB::table('price_movers')->where('variant_key', 'basep-13:holo:-:-')->where('day', '2026-10-12')->count())->toBe(0)
        ->and(DB::table('price_movers')->whereNotIn('day', ['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'])->count())->toBe(0)
        ->and(collect(t12Movers('2026-10-14', 'up'))->first(fn (string $m): bool => str_contains($m, ' basep-13:holo:-:- ')))
        ->toEndWith('basep-13:holo:-:- 13.64 12.40 2026-10-11 1.24 10.00 1 basep-13')
        // No mover is ever a filled row of its day.
        ->and(DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM price_movers m
            JOIN price_daily d ON d.variant_key = m.variant_key AND d.source = m.source AND d.day = m.day
            WHERE d.is_filled OR d.close <> m.close
            SQL)->n)->toBe(0);
});

test('ingest order never changes price_movers, the gap-day orders included', function (): void {
    $orders = [
        'ascending' => T12_DAYS,
        'descending' => array_reverse(T12_DAYS),
        'scrambled' => ['2026-10-12', '2026-10-09', '2026-10-14', '2026-10-10', '2026-10-11'],
        'gap day 14 then 12' => ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-14', '2026-10-12'],
    ];
    $checks = [];
    foreach ($orders as $name => $days) {
        t12Reset();
        t12Ingest($days);
        $checks[$name] = PriceDigest::movers(DB::connection());
    }

    expect($checks['ascending']['rows'])->toBeGreaterThan(0)
        ->and($checks['ascending']['sha256'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($checks['descending'])->toBe($checks['ascending'])
        ->and($checks['scrambled'])->toBe($checks['ascending'])
        ->and($checks['gap day 14 then 12'])->toBe($checks['ascending']);
});

test('price_movers stays outside PriceDigest and has its own movers check', function (): void {
    t12Ingest(T12_MOVERS_DAYS, t12MoversRoot());
    $digest = PriceDigest::compute(DB::connection());
    $movers = PriceDigest::movers(DB::connection());

    DB::table('price_movers')->where('day', '2026-10-12')->where('direction', 'down')->where('rank', 2)->delete();

    expect(array_keys($digest['tables']))->toBe(['price_points', 'price_daily', 'price_latest'])
        ->and($movers['rows'])->toBe(5)
        ->and(PriceDigest::compute(DB::connection()))->toBe($digest)
        ->and(PriceDigest::movers(DB::connection())['rows'])->toBe(4)
        ->and(PriceDigest::movers(DB::connection())['sha256'])->not->toBe($movers['sha256']);
});

test('replay truncates price_movers, rebuilds it and prints an equal movers_check in every pass', function (): void {
    t12Ingest(T12_DAYS);
    $live = PriceDigest::movers(DB::connection());
    // A stray movers row a replay must wipe.
    DB::table('price_movers')->insert(['day' => '2026-09-01', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'basep-8:holo:-:-', 'card_id' => 'basep-8', 'series_epoch' => 1, 'close' => '28.00',
        'previous_close' => '25.00', 'previous_day' => '2026-08-31', 'change' => '3.00', 'change_pct' => '12.00',
        'as_of' => '2026-09-01 20:05:45+00']);

    $code = Artisan::call('pokeprices:prices:replay', ['--fresh' => true, '--order' => 'desc', '--snapshots' => t12Fixture('snapshots')]);
    $output = Artisan::output();
    $doc = json_decode(substr($output, (int) strpos($output, '{')), true);

    expect($code)->toBe(0, $output)
        ->and(Replay::WIPED_TABLES)->toBe(['price_points', 'price_daily', 'price_latest', 'price_movers', 'movement_volume'])
        ->and(array_column($doc['passes'], 'movers_check'))->toBe([$live, $live, $live])
        ->and($doc['identical'])->toBeTrue()
        ->and(PriceDigest::movers(DB::connection()))->toBe($live);
});

test('a movers_check that differs between passes makes the replay not identical', function (): void {
    $wipes = 0;
    DB::listen(function (QueryExecuted $query) use (&$wipes): void {
        if (str_starts_with($query->sql, 'TRUNCATE') && ++$wipes === 2) {
            DB::table('price_movers')->insert(['day' => '2030-01-01', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
                'variant_key' => 'basep-8:holo:-:-', 'card_id' => 'basep-8', 'series_epoch' => 1, 'close' => '28.00',
                'previous_close' => '25.00', 'previous_day' => '2029-12-31', 'change' => '3.00', 'change_pct' => '12.00',
                'as_of' => '2030-01-01 20:05:45+00']);
        }
    });

    $code = Artisan::call('pokeprices:prices:replay', ['--fresh' => true, '--snapshots' => t12Fixture('snapshots')]);
    $output = Artisan::output();
    $doc = json_decode(substr($output, (int) strpos($output, '{')), true);

    expect($code)->toBe(1)
        ->and($doc['identical'])->toBeFalse()
        ->and($doc['passes'][1]['digests'])->toBe($doc['passes'][0]['digests'])
        ->and($doc['passes'][1]['movers_check']['rows'])->toBe($doc['passes'][0]['movers_check']['rows'] + 1);
});

it('serves the newest movers day: exact rows, provenance and the previous_close window', function (): void {
    t12Ingest(T12_MOVERS_DAYS, t12MoversRoot());

    $response = $this->getJson('/v1/movers?direction=up&limit=2');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [
            ['rank' => 1, 'card_id' => 'basep-8', 'card' => ['name' => 'Name of basep-8', 'set_name' => 'Fixture set', 'number' => '8'], 'variant_key' => 'basep-8:holo:-:-', 'finish' => 'holo', 'edition' => '-', 'treatment' => '-',
                'close' => '31.36', 'previous_close' => '28.00', 'previous_day' => '2026-10-11', 'change' => '3.36', 'change_pct' => '12.00', 'series_epoch' => 1],
            ['rank' => 2, 'card_id' => 'svp-5', 'card' => ['name' => 'Name of svp-5', 'set_name' => 'Fixture set', 'number' => '5'], 'variant_key' => 'svp-5:holo:-:stamp-prerelease-staff', 'finish' => 'holo', 'edition' => '-', 'treatment' => 'stamp-prerelease-staff',
                'close' => '46.20', 'previous_close' => '42.00', 'previous_day' => '2026-10-11', 'change' => '4.20', 'change_pct' => '10.00', 'series_epoch' => 1],
        ],
        'meta' => [
            'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:44Z', 'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market',
            'currency' => 'USD', 'window' => 'previous_close', 'min_previous_close' => '2.00', 'direction' => 'up', 'limit' => 2,
            'degraded' => false,
        ],
    ]);
});

it('defaults to up and 20, and serves down movers with signed strings', function (): void {
    t12Ingest(T12_MOVERS_DAYS, t12MoversRoot());

    $up = $this->getJson('/v1/movers');
    $down = $this->getJson('/v1/movers?direction=down');

    expect($up->json('meta'))->toMatchArray(['direction' => 'up', 'limit' => 20])
        ->and(array_column($up->json('data'), 'rank'))->toBe(range(1, 3))
        ->and(array_column($down->json('data'), 'variant_key'))->toBe(['svp-46:holo:-:-', 'base1-8:holo:1st:shadowless'])
        ->and($down->json('data.0'))->toMatchArray(['change' => '-1.00', 'change_pct' => '-21.28'])
        ->and($down->json('meta'))->toMatchArray(['day' => '2026-10-12', 'direction' => 'down']);
});

it('uses the newest movers day once later days land', function (): void {
    t12Ingest(T12_DAYS);

    $response = $this->getJson('/v1/movers?direction=up&limit=50');

    expect($response->json('meta'))->toMatchArray(['day' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z'])
        ->and(array_column($response->json('data'), 'previous_day'))->each->toBeIn(['2026-10-12', '2026-10-11']);
});

it('answers data [] with a null day and as_of before any movers exist', function (): void {
    $response = $this->getJson('/v1/movers');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta'))->toMatchArray(['day' => null, 'as_of' => null, 'direction' => 'up', 'limit' => 20]);
});

it('answers 422 for a bad direction or limit', function (string $query, string $field): void {
    $response = $this->getJson("/v1/movers?{$query}");

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toHaveKey($field);
})->with([
    'limit 51' => ['limit=51', 'limit'],
    'limit 0' => ['limit=0', 'limit'],
    'limit not a number' => ['limit=ten', 'limit'],
    'direction sideways' => ['direction=sideways', 'direction'],
    'direction UP' => ['direction=UP', 'direction'],
    'empty direction' => ['direction=', 'direction'],
]);

it('reads the movers with exactly 2 queries', function (): void {
    t12Ingest(T12_MOVERS_DAYS, t12MoversRoot());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/v1/movers?direction=up&limit=50');
    DB::disableQueryLog();

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3)
        ->and(DB::getQueryLog())->toHaveCount(2);
});

it('caches through the history policy: ETag, Cache-Control and a 304 round trip', function (): void {
    t12Ingest(['2026-10-10', '2026-10-11']);

    $first = $this->getJson('/v1/movers?direction=down');
    $etag = (string) $first->headers->get('ETag');
    $matched = $this->getJson('/v1/movers?direction=down', ['If-None-Match' => $etag]);
    $directives = array_map('trim', explode(',', (string) $first->headers->get('Cache-Control')));
    sort($directives);

    expect($first->getStatusCode())->toBe(200)
        ->and($etag)->toBe('"'.hash('xxh128', (string) $first->getContent()).'"')
        ->and($directives)->toBe(['max-age=300', 'public', 'stale-while-revalidate=600'])
        ->and($matched->getStatusCode())->toBe(304)
        ->and($matched->headers->get('ETag'))->toBe($etag);
});

it('routes the movers outside the PokéSearch group and documents no upstream errors', function (): void {
    $route = Route::getRoutes()->getByName('movers');
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/movers']['get'];
    $parameters = collect($operation['parameters'])->keyBy('name');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('v1/movers')
        ->and($route->gatherMiddleware())->toContain('throttle:public-read')
        ->and($route->gatherMiddleware())->toContain('conditional-get:history')
        ->and($route->getAction('pokesearch'))->toBeNull()
        ->and(array_keys($operation['responses']))->toBe([200, 304, 422, 429, 500, 503])
        ->and($parameters['direction']['schema']['enum'])->toBe(['up', 'down'])
        ->and([$parameters['limit']['schema']['minimum'], $parameters['limit']['schema']['maximum']])->toBe([1, 50])
        ->and(ForbiddenWords::payloadMatches((string) $operation['description']))->toBe([]);
});

it('caps limit at pricing.movers.limit: with 10, limit=10 answers 200 and limit=11 answers 422 errors.limit (Q1-i4 T03)', function (): void {
    config(['pricing.movers.limit' => 10]);

    $ten = $this->getJson('/v1/movers?limit=10');
    $eleven = $this->getJson('/v1/movers?limit=11');

    expect($ten->getStatusCode())->toBe(200)
        ->and($ten->json('meta.limit'))->toBe(10)
        ->and($eleven->getStatusCode())->toBe(422)
        ->and($eleven->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($eleven->json('errors')))->toBe(['limit']);
});

it('characterises every GET /v1/movers refusal: the exact errors object (Q1-i4 T05)', function (string $query, array $errors): void {
    $response = $this->getJson("/v1/movers?{$query}");

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toBe($errors);
})->with([
    'direction sideways' => ['direction=sideways', ['direction' => ['The selected direction is invalid.']]],
    'direction empty' => ['direction=', ['direction' => ['The direction field is required.']]],
    'direction a list' => ['direction[]=up', ['direction' => ['The direction field must be a string.', 'The selected direction is invalid.']]],
    'limit empty' => ['limit=', ['limit' => ['The limit field is required.']]],
    'limit ten' => ['limit=ten', ['limit' => ['The limit field must be an integer.']]],
    'limit 1.5' => ['limit=1.5', ['limit' => ['The limit field must be an integer.']]],
    'limit 0' => ['limit=0', ['limit' => ['The limit field must be at least 1.']]],
    'limit 51' => ['limit=51', ['limit' => ['The limit field must not be greater than 50.']]],
    'both bad' => ['direction=UP&limit=51', [
        'direction' => ['The selected direction is invalid.'],
        'limit' => ['The limit field must not be greater than 50.'],
    ]],
]);
