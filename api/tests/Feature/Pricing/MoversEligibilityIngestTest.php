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

// The movers rule through the real day ingest: the synthetic days of tests/Fixtures/tcgcsv-days are ingested with
// --sync, so the closes, their observations in price_points and the recompute window are the pipeline's own. Edited
// days are copies under a scratch root that afterEach removes; the fixture tree is only read.
//
// In the fixture one variant is a mover under the rule: base1-8:holo:1st:shadowless (product 107004, 1st Edition
// Holofoil) closes at 120.40, 121.00, 119.80, 118.00 and, after the gap day, 125.00 on 2026-10-14 (+7.00, +5.93 %),
// with listings at 90 % and 105 % of each close.

const ELIGIBILITY_INGEST_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const ELIGIBILITY_INGEST_MOVER = '2026-10-14 up 1 base1-8:holo:1st:shadowless 118.00>125.00 7.00 5.93 2026-10-12 2026-10-14T20:05:46Z';

function eligibilityIngestFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync from $snapshots (default: the fixture tree). */
function eligibilityIngest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? eligibilityIngestFixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Empties the price tables and the runs, so another ingest order starts from nothing. */
function eligibilityIngestReset(): void
{
    foreach (['price_movers', 'price_latest', 'price_daily', 'price_points', 'ingest_run_chunks', 'ingest_runs'] as $table) {
        DB::table($table)->delete();
    }
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function eligibilityIngestRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Copies every fixture day under a fresh scratch root that afterEach removes. */
function eligibilityIngestCopy(): string
{
    $root = storage_path('framework/testing/pp-movers-rule-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    eligibilityIngestRoots($root);
    foreach (ELIGIBILITY_INGEST_DAYS as $day) {
        $src = eligibilityIngestFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/**
 * Sets fields of the 1st Edition Holofoil row of product 107004 (group 1840) on a scratch day; $fields maps a TCGCSV
 * field (marketPrice, lowPrice, midPrice) to its new number.
 *
 * @param  array<string, float>  $fields
 */
function eligibilityIngestSet(string $root, string $day, array $fields): void
{
    $path = "{$root}/{$day}/prices/1840.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    $found = 0;
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === 107004 && $row['subTypeName'] === '1st Edition Holofoil') {
            $document['results'][$i] = array_merge($row, $fields);
            $found++;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException("expected one 107004 1st Edition Holofoil row on {$day}, found {$found}");
    }
    file_put_contents($path, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));
}

/** @return list<string> every price_movers row as "day direction rank variant previous>close change pct previous_day as_of" */
function eligibilityIngestMovers(): array
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

/** Every file under $dir as relative path => sha1. */
function eligibilityIngestTree(string $dir): array
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
    $this->fixtureTree = eligibilityIngestTree(eligibilityIngestFixture('snapshots'));
});

afterEach(function (): void {
    expect(eligibilityIngestTree(eligibilityIngestFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (eligibilityIngestRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('the ingested fixture days rank the one variant that kept moving, on the one day its move is material', function (): void {
    eligibilityIngest(ELIGIBILITY_INGEST_DAYS);

    expect(eligibilityIngestMovers())->toBe([ELIGIBILITY_INGEST_MOVER]);

    $up = $this->getJson('/v1/movers?direction=up&limit=50');
    $down = $this->getJson('/v1/movers?direction=down&limit=50');

    $up->assertOk();
    $down->assertOk();
    expect($up->json('data'))->toHaveCount(1)
        ->and($up->json('data.0'))->toMatchArray(['rank' => 1, 'variant_key' => 'base1-8:holo:1st:shadowless', 'close' => '125.00',
            'previous_close' => '118.00', 'previous_day' => '2026-10-12', 'change' => '7.00', 'change_pct' => '5.93'])
        ->and($up->json('meta'))->toMatchArray(['day' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z', 'min_previous_close' => '2.00'])
        ->and($down->json('data'))->toBe([])
        ->and($down->json('meta.day'))->toBe('2026-10-14');
});

test('a day ingested after later days gives the later days the movers an ordered ingest gives them', function (): void {
    // The series closes at 120.40, 125.00, 119.80, 112.00 and 125.00: a fall on 10-12 (four distinct closes with 10-09)
    // and a rise on 10-14 that shows four values only with the close of 10-09, since 10-14 repeats 10-10.
    $root = eligibilityIngestCopy();
    eligibilityIngestSet($root, '2026-10-10', ['marketPrice' => 125.00]);
    eligibilityIngestSet($root, '2026-10-12', ['marketPrice' => 112.00]);

    eligibilityIngest(ELIGIBILITY_INGEST_DAYS, $root);
    $ordered = [eligibilityIngestMovers(), PriceDigest::movers(DB::connection())];

    eligibilityIngestReset();
    eligibilityIngest(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'], $root);
    $before = eligibilityIngestMovers();
    eligibilityIngest(['2026-10-09'], $root);
    $late = [eligibilityIngestMovers(), PriceDigest::movers(DB::connection())];

    eligibilityIngestReset();
    eligibilityIngest(array_reverse(ELIGIBILITY_INGEST_DAYS), $root);
    $descending = [eligibilityIngestMovers(), PriceDigest::movers(DB::connection())];

    expect($ordered[0])->toBe([
        '2026-10-12 down 1 base1-8:holo:1st:shadowless 119.80>112.00 -7.80 -6.51 2026-10-11 2026-10-12T20:05:44Z',
        '2026-10-14 up 1 base1-8:holo:1st:shadowless 112.00>125.00 13.00 11.61 2026-10-12 2026-10-14T20:05:46Z',
    ])
        // Without 10-09 the series shows three values on both days (125.00, 119.80, 112.00): nothing is ranked.
        ->and($before)->toBe([])
        ->and($late)->toBe($ordered)
        ->and($descending)->toBe($ordered);
});

test('a close far outside the listings its own observation stored is not ranked, and the same close in line with them is', function (): void {
    // 300.00 on 10-14 while the observation lists 112.50 low and 131.25 mid: over twice the mid.
    $odd = eligibilityIngestCopy();
    eligibilityIngestSet($odd, '2026-10-14', ['marketPrice' => 300.00]);
    eligibilityIngest(ELIGIBILITY_INGEST_DAYS, $odd);
    $oddMovers = eligibilityIngestMovers();
    $oddPoint = DB::selectOne("SELECT market::text AS market, low::text AS low, mid::text AS mid FROM price_points WHERE variant_key = 'base1-8:holo:1st:shadowless' AND day = '2026-10-14'");

    // The same close with listings that moved with it.
    eligibilityIngestReset();
    $listed = eligibilityIngestCopy();
    eligibilityIngestSet($listed, '2026-10-14', ['marketPrice' => 300.00, 'lowPrice' => 280.00, 'midPrice' => 310.00]);
    eligibilityIngest(ELIGIBILITY_INGEST_DAYS, $listed);

    expect([$oddPoint->market, $oddPoint->low, $oddPoint->mid])->toBe(['300.00', '112.50', '131.25'])
        ->and($oddMovers)->toBe([])
        ->and(eligibilityIngestMovers())->toBe(['2026-10-14 up 1 base1-8:holo:1st:shadowless 118.00>300.00 182.00 154.24 2026-10-12 2026-10-14T20:05:46Z']);
});
