<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// The movers rule reads the listings of price_points, so a later collection of a day that keeps a close but changes
// its listings must reach the board through the real day ingest: the re-collected day lies in the ingest's recompute
// window, and so does every day whose move it is one end of. The synthetic days of tests/Fixtures/tcgcsv-days are
// ingested with --sync; edited days are copies under a scratch root that afterEach removes, the fixture tree is only
// read. base1-8:holo:1st:shadowless (group 1840, product 107004, 1st Edition Holofoil) closes 120.40, 121.00, 119.80,
// 118.00 and 125.00 on 2026-10-14, the fixture's one mover (+7.00, +5.93 % against 10-12).

const T04Y_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const T04Y_MOVER = '2026-10-14 up 1 base1-8:holo:1st:shadowless 118.00>125.00 7.00 5.93 2026-10-12 2026-10-14T20:05:46Z';

function t04yFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync from $snapshots (default: the fixture tree). */
function t04yIngest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t04yFixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Empties the price tables and the runs, so another ingest order starts from nothing. */
function t04yReset(): void
{
    foreach (['price_movers', 'movement_volume', 'price_latest', 'price_daily', 'price_points', 'ingest_run_chunks', 'ingest_runs'] as $table) {
        DB::table($table)->delete();
    }
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t04yRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Copies $days of the fixture tree under a fresh scratch root; with $marker the one day is a later collection of it. */
function t04yCopy(array $days, ?string $marker = null): string
{
    $root = storage_path('framework/testing/pp-t04y-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t04yRoots($root);
    foreach ($days as $day) {
        $src = t04yFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
        if ($marker !== null) {
            file_put_contents("{$root}/{$day}/marker.txt", $marker."\n");
            $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
            file_put_contents("{$root}/{$day}/manifest.json", json_encode(['marker' => $marker] + $manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    return $root;
}

/**
 * Sets fields of one price row on a scratch day; $fields maps a TCGCSV field (marketPrice, lowPrice, midPrice) to its
 * new value, null included.
 *
 * @param  array<string, float|null>  $fields
 */
function t04ySet(string $root, string $day, int $group, int $productId, string $subType, array $fields): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    $found = 0;
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subType) {
            $document['results'][$i] = array_merge($row, $fields);
            $found++;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException("expected one {$productId} {$subType} row on {$day}, found {$found}");
    }
    file_put_contents($path, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** A later collection of $day of the fixture at $marker, with base1-8 1st shadowless's fields set to $fields. */
function t04yRecollect(string $day, string $marker, array $fields): string
{
    $root = t04yCopy([$day], $marker);
    t04ySet($root, $day, 1840, 107004, '1st Edition Holofoil', $fields);

    return $root;
}

/** @return list<string> every price_movers row as "day direction rank variant previous>close change pct previous_day as_of" */
function t04yMovers(): array
{
    return array_map(
        fn (object $r): string => "{$r->day} {$r->direction} {$r->rank} {$r->variant_key} {$r->previous_close}>{$r->close} {$r->change} {$r->change_pct} {$r->previous_day} {$r->as_of}",
        DB::select(<<<'SQL'
            SELECT day::text AS day, direction, rank, variant_key, close::text AS close, previous_close::text AS previous_close,
                   previous_day::text AS previous_day, change::text AS change, change_pct::text AS change_pct,
                   to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM price_movers ORDER BY day, direction, rank
            SQL),
    );
}

/** The real close of base1-8 1st shadowless on $day and its as_of. */
function t04yCandle(string $day): string
{
    $row = DB::selectOne(<<<'SQL'
        SELECT close::text AS close, to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
        FROM price_daily WHERE variant_key = 'base1-8:holo:1st:shadowless' AND day = ?::date AND NOT is_filled
        SQL, [$day]);

    return "{$row->close} {$row->as_of}";
}

/** Every file under $dir as relative path => sha1. */
function t04yTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
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
    $this->fixtureTree = t04yTree(t04yFixture('snapshots'));
});

afterEach(function (): void {
    expect(t04yTree(t04yFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t04yRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('a later collection of the previous close\'s day that keeps the close but lists it out of line takes the next day\'s mover off the board, and one back in line restores it', function (): void {
    t04yIngest(T04Y_DAYS);
    $before = t04yMovers();

    // 118.00 against a low of 240.00 is under half of it; the close itself does not change.
    t04yIngest(['2026-10-12'], t04yRecollect('2026-10-12', '2026-10-12T22:10:05+0000', ['lowPrice' => 240.00]));
    $outOfLine = t04yMovers();
    $candle = t04yCandle('2026-10-12');

    // A third collection lists it as the fixture did.
    t04yIngest(['2026-10-12'], t04yRecollect('2026-10-12', '2026-10-12T23:10:05+0000', ['lowPrice' => 106.20]));

    expect($before)->toBe([T04Y_MOVER])
        ->and($candle)->toBe('118.00 2026-10-12T22:10:05Z')
        ->and($outOfLine)->toBe([])
        ->and(t04yMovers())->toBe([T04Y_MOVER]);
});

test('a later collection of the day itself that keeps the close but halves its mid takes the mover off, and the in-line one after it carries its marker', function (): void {
    t04yIngest(T04Y_DAYS);

    // 125.00 against a mid of 60.00 is over twice it.
    t04yIngest(['2026-10-14'], t04yRecollect('2026-10-14', '2026-10-14T22:10:05+0000', ['midPrice' => 60.00]));
    $outOfLine = t04yMovers();
    $candle = t04yCandle('2026-10-14');

    t04yIngest(['2026-10-14'], t04yRecollect('2026-10-14', '2026-10-14T23:10:05+0000', ['midPrice' => 131.25]));

    expect($candle)->toBe('125.00 2026-10-14T22:10:05Z')
        ->and($outOfLine)->toBe([])
        ->and(t04yMovers())->toBe(['2026-10-14 up 1 base1-8:holo:1st:shadowless 118.00>125.00 7.00 5.93 2026-10-12 2026-10-14T23:10:05Z']);
});

test('a later collection with no market price replaces the day\'s observation: the day has no real close, its filled row is never ranked and nothing fails', function (): void {
    t04yIngest(T04Y_DAYS);

    t04yIngest(['2026-10-14'], t04yRecollect('2026-10-14', '2026-10-14T22:10:05+0000', ['marketPrice' => null, 'lowPrice' => 900.00, 'midPrice' => 950.00]));
    $filled = DB::selectOne(<<<'SQL'
        SELECT close::text AS close, is_filled, filled_from::text AS filled_from
        FROM price_daily WHERE variant_key = 'base1-8:holo:1st:shadowless' AND day = '2026-10-14'
        SQL);
    $up = $this->getJson('/v1/movers?direction=up');

    $up->assertOk();
    expect([$filled->close, $filled->is_filled, $filled->filled_from])->toBe(['118.00', true, '2026-10-12'])
        ->and(t04yMovers())->toBe([])
        ->and($up->json('data'))->toBe([])
        ->and($up->json('meta.day'))->toBeNull();
});

test('ingest order never changes a board of several movers on several days, the gap-day and late-first-day orders included', function (): void {
    // Five movers on 10-12 (each shows four distinct closes, moves at least 5 % and 1.00 and stays in line with the
    // listings the fixture stores), and base1-8 again on 10-14 against its 112.00.
    $root = t04yCopy(T04Y_DAYS);
    t04ySet($root, '2026-10-12', 1418, 85000, 'Holofoil', ['marketPrice' => 31.36]);
    t04ySet($root, '2026-10-12', 22872, 522650, 'Holofoil', ['marketPrice' => 46.20]);
    t04ySet($root, '2026-10-12', 23821, 610540, 'Holofoil', ['marketPrice' => 35.20]);
    t04ySet($root, '2026-10-12', 22872, 517271, 'Holofoil', ['marketPrice' => 3.70]);
    t04ySet($root, '2026-10-12', 1840, 107004, '1st Edition Holofoil', ['marketPrice' => 112.00]);

    $orders = [
        'ascending' => T04Y_DAYS,
        'descending' => array_reverse(T04Y_DAYS),
        'scrambled' => ['2026-10-12', '2026-10-09', '2026-10-14', '2026-10-10', '2026-10-11'],
        'gap day 14 then 12' => ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-14', '2026-10-12'],
        'the first day last' => ['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14', '2026-10-09'],
    ];
    $boards = [];
    foreach ($orders as $name => $days) {
        t04yReset();
        t04yIngest($days, $root);
        $boards[$name] = [t04yMovers(), PriceDigest::movers(DB::connection())];
    }

    $up = $this->getJson('/v1/movers?direction=up&limit=50');
    $down = $this->getJson('/v1/movers?direction=down&limit=50');

    expect($boards['ascending'][0])->toBe([
        '2026-10-12 down 1 svp-46:holo:-:- 4.70>3.70 -1.00 -21.28 2026-10-11 2026-10-12T20:05:44Z',
        '2026-10-12 down 2 base1-8:holo:1st:shadowless 119.80>112.00 -7.80 -6.51 2026-10-11 2026-10-12T20:05:44Z',
        '2026-10-12 up 1 basep-8:holo:-:- 28.00>31.36 3.36 12.00 2026-10-11 2026-10-12T20:05:44Z',
        '2026-10-12 up 2 svp-5:holo:-:stamp-prerelease-staff 42.00>46.20 4.20 10.00 2026-10-11 2026-10-12T20:05:44Z',
        '2026-10-12 up 3 sv8pt5-5:reverse:-:pokeball 32.00>35.20 3.20 10.00 2026-10-11 2026-10-12T20:05:44Z',
        // On 10-14 the fixture's own closes make two more of these series movers against their edited 10-12.
        '2026-10-14 down 1 basep-8:holo:-:- 31.36>27.50 -3.86 -12.31 2026-10-12 2026-10-14T20:05:46Z',
        '2026-10-14 up 1 svp-46:holo:-:- 3.70>4.80 1.10 29.73 2026-10-12 2026-10-14T20:05:46Z',
        '2026-10-14 up 2 base1-8:holo:1st:shadowless 112.00>125.00 13.00 11.61 2026-10-12 2026-10-14T20:05:46Z',
    ]);
    foreach ($boards as $name => $board) {
        expect($board)->toBe($boards['ascending'], $name);
    }
    $up->assertOk();
    $down->assertOk();
    expect(array_column($up->json('data'), 'variant_key'))->toBe(['svp-46:holo:-:-', 'base1-8:holo:1st:shadowless'])
        ->and($up->json('meta'))->toMatchArray(['day' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z'])
        ->and(array_column($down->json('data'), 'variant_key'))->toBe(['basep-8:holo:-:-'])
        ->and($down->json('data.0'))->toMatchArray(['change' => '-3.86', 'change_pct' => '-12.31', 'previous_day' => '2026-10-12'])
        ->and($down->json('meta.day'))->toBe('2026-10-14');
});
