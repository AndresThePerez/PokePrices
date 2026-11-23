<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogCache;

// The movers board's as-of (plan Q1-i3 T04, finding B10, A6): Movers::rebuild() stores, per movers day, the snapshot
// marker of that day's real closes, and GET /v1/movers reads it back from price_movers without touching price_daily.
// The query the controller ran before T04 is kept verbatim below as the oracle: the stored as_of of every movers day
// must equal it, whatever the ingest order, after a replay and after a re-collected day. The fixture tree is only read;
// edited days are copies under a scratch root that afterEach removes.

const Q1T04M_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

/** The controller's query 1 before T04, verbatim: the newest movers day and the snapshot marker of its real closes. */
const Q1T04M_OLD_QUERY = <<<'SQL'
    SELECT to_char(m.day, 'YYYY-MM-DD') AS day,
           (SELECT to_char(max(d.as_of) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
            FROM price_daily d WHERE d.source = ? AND d.day = m.day AND NOT d.is_filled) AS as_of
    FROM (SELECT max(day) AS day FROM price_movers WHERE source = ?) m
    SQL;

/** The same subquery, verbatim, applied to every movers day instead of the newest one only. */
const Q1T04M_OLD_PER_DAY = <<<'SQL'
    SELECT to_char(m.day, 'YYYY-MM-DD') AS day,
           (SELECT to_char(max(d.as_of) AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
            FROM price_daily d WHERE d.source = ? AND d.day = m.day AND NOT d.is_filled) AS as_of
    FROM (SELECT DISTINCT day FROM price_movers WHERE source = ?) m
    ORDER BY m.day
    SQL;

function q1t04mFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync from $snapshots (default: the fixture tree). */
function q1t04mIngest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? q1t04mFixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function q1t04mRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/**
 * Copies every fixture day under a fresh scratch root and sets the 10-12 close of base1-8:holo:1st:shadowless (product
 * 107004, 1st Edition Holofoil) to 112.00. The fixture alone ranks one day, 10-14; with this close 10-12 has a mover too
 * (119.80 > 112.00, -6.51 %, the fourth distinct close of the series) and 10-14 keeps one (112.00 > 125.00, +11.61 %).
 */
function q1t04mTwoMoverDays(): string
{
    $root = storage_path('framework/testing/pp-q1t04m-'.bin2hex(random_bytes(6)));
    q1t04mRoots($root);
    foreach (Q1T04M_DAYS as $day) {
        $src = q1t04mFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }
    $path = "{$root}/2026-10-12/prices/1840.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === 107004 && $row['subTypeName'] === '1st Edition Holofoil') {
            $document['results'][$i]['marketPrice'] = 112.00;
        }
    }
    file_put_contents($path, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));

    return $root;
}

/**
 * Copies day $day of $snapshots (default: the fixture tree) under a fresh scratch root as a later collection of the
 * same UTC date (marker $marker).
 */
function q1t04mRecollect(string $day, string $marker, ?string $snapshots = null): string
{
    $root = storage_path('framework/testing/pp-q1t04m-'.bin2hex(random_bytes(6)));
    q1t04mRoots($root);
    $src = ($snapshots ?? q1t04mFixture('snapshots')).'/'.$day;
    mkdir("{$root}/{$day}", 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
        $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
    }
    file_put_contents("{$root}/{$day}/marker.txt", $marker."\n");
    $manifest = json_decode((string) file_get_contents("{$root}/{$day}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("{$root}/{$day}/manifest.json", json_encode(['marker' => $marker] + $manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $root;
}

/** Every file under $dir as relative path => sha1. */
function q1t04mTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** The stored as_of of every movers day, day => as_of, and the days whose rows disagree on it. */
function q1t04mStored(): array
{
    expect(Schema::hasColumn('price_movers', 'as_of'))->toBeTrue();
    $days = [];
    foreach (DB::select(<<<'SQL'
        SELECT to_char(day, 'YYYY-MM-DD') AS day, to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
        FROM price_movers WHERE source = 'tcgplayer' GROUP BY day, as_of ORDER BY day, as_of
        SQL) as $row) {
        $days[$row->day][] = $row->as_of;
    }

    return $days;
}

/** The oracle: the old per-day subquery, day => [as_of]. */
function q1t04mOracle(): array
{
    $days = [];
    foreach (DB::select(Q1T04M_OLD_PER_DAY, ['tcgplayer', 'tcgplayer']) as $row) {
        $days[$row->day][] = $row->as_of;
    }

    return $days;
}

/** Rows whose stored as_of differs from max(as_of) of their day's real closes at full precision. */
function q1t04mFullPrecisionMismatches(): int
{
    return (int) DB::selectOne(<<<'SQL'
        SELECT count(*) AS n FROM price_movers m
        WHERE m.as_of IS DISTINCT FROM (SELECT max(d.as_of) FROM price_daily d WHERE d.source = m.source AND d.day = m.day AND NOT d.is_filled)
        SQL)->n;
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
    $this->fixtureTree = q1t04mTree(q1t04mFixture('snapshots'));
});

afterEach(function (): void {
    expect(q1t04mTree(q1t04mFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (q1t04mRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('GET /v1/movers runs no statement on price_daily and serves the meta the old query gave', function (): void {
    q1t04mIngest(Q1T04M_DAYS);
    $old = DB::selectOne(Q1T04M_OLD_QUERY, ['tcgplayer', 'tcgplayer']);

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $response = $this->getJson('/v1/movers?direction=up&limit=50');

    $response->assertOk();
    expect($statements)->not->toBe([])
        ->and(array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'price_daily'))))->toBe([])
        ->and($response->json('data'))->not->toBe([])
        ->and(['day' => $response->json('meta.day'), 'as_of' => $response->json('meta.as_of')])
        ->toBe(['day' => $old->day, 'as_of' => $old->as_of])
        ->and($response->json('meta.as_of'))->toBe('2026-10-14T20:05:46Z');
});

test('the stored as_of of every movers day equals the old subquery', function (Closure $scenario): void {
    $scenario();

    $stored = q1t04mStored();
    $oracle = q1t04mOracle();
    $old = DB::selectOne(Q1T04M_OLD_QUERY, ['tcgplayer', 'tcgplayer']);
    $meta = $this->getJson('/v1/movers')->json('meta');

    expect($stored)->not->toBe([])
        ->and($stored)->toBe($oracle)
        ->and(q1t04mFullPrecisionMismatches())->toBe(0)
        ->and(['day' => $meta['day'], 'as_of' => $meta['as_of']])->toBe(['day' => $old->day, 'as_of' => $old->as_of]);
})->with([
    'in order' => [fn () => q1t04mIngest(Q1T04M_DAYS)],
    'out of order: 10-11 after 10-14' => [fn () => q1t04mIngest(['2026-10-09', '2026-10-10', '2026-10-12', '2026-10-14', '2026-10-11'])],
    'descending' => [fn () => q1t04mIngest(array_reverse(Q1T04M_DAYS))],
    'a replay, descending' => [function (): void {
        q1t04mIngest(['2026-10-10', '2026-10-11']);
        $code = Artisan::call('pokeprices:prices:replay', ['--snapshots' => q1t04mFixture('snapshots'), '--fresh' => true, '--order' => 'desc']);
        if ($code !== 0) {
            throw new RuntimeException('the replay failed: '.Artisan::output());
        }
    }],
    'a re-collected 10-12 after 10-14' => [function (): void {
        // 10-11 can hold no mover (three closes of history), so the older movers day is 10-12, from the edited copy.
        $root = q1t04mTwoMoverDays();
        q1t04mIngest(Q1T04M_DAYS, $root);
        q1t04mIngest(['2026-10-12'], q1t04mRecollect('2026-10-12', '2026-10-12T22:10:05+0000', $root));
        // The later marker is now the 10-12 snapshot marker, and the board's newest day keeps its own.
        expect(q1t04mStored())->toBe(['2026-10-12' => ['2026-10-12T22:10:05Z'], '2026-10-14' => ['2026-10-14T20:05:46Z']]);
    }],
    'a re-collected 10-14, the newest day' => [function (): void {
        q1t04mIngest(Q1T04M_DAYS);
        q1t04mIngest(['2026-10-14'], q1t04mRecollect('2026-10-14', '2026-10-14T23:59:58+0000'));
    }],
]);
