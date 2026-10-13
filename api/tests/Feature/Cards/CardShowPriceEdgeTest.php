<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\LatestPrices;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Reviewer edge cases for the price block of GET /v1/cards/{id} and the price_latest rebuild (plan P2-i1 T05, D4, D5).
// price_daily rows are inserted directly so the decimal arithmetic, the real-row rule and the lookback can be
// pinned without a snapshot tree; the end-to-end cases ingest the synthetic fixture days.

const T05R_BASE = 'http://192.0.2.10:8081';

const T05R_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

function t05rAnswer(string $id): array
{
    return ['data' => [
        'id' => $id, 'name' => 'Charizard', 'supertype' => 'Pokémon', 'subtypes' => ['Basic'], 'hp' => 60, 'types' => ['Fire'],
        'rarity' => 'Promo', 'number' => '8', 'artist' => 'Ken Sugimori', 'set_id' => explode('-', $id)[0],
        'set_name' => 'Promos', 'set_series' => 'Base', 'set_total' => 53, 'release_date' => '1999-07-01',
        'image_small' => 'https://images.example.com/card.png', 'image_large' => 'https://images.example.com/card_hires.png',
    ], 'seed' => ['ref' => T05R_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']];
}

/** A card_variants row (no map row) for a card the fixture map does not know. */
function t05rVariant(string $cardId, string $finish, string $edition = '-', string $treatment = '-'): string
{
    $key = "{$cardId}:{$finish}:{$edition}:{$treatment}";
    DB::table('card_variants')->insert([
        'variant_key' => $key, 'card_id' => $cardId, 'finish' => $finish, 'edition' => $edition,
        'treatment' => $treatment, 'catalog_status' => 'unverified',
    ]);

    return $key;
}

/** A price_daily row: a real candle (closed at $close) or, with $filledFrom, a filled one carrying that day forward. */
function t05rDaily(string $key, string $day, string $close, int $epoch = 1, ?string $filledFrom = null, string $time = '20:05:45'): void
{
    DB::table('price_daily')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => $epoch,
        'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
        'n_obs' => $filledFrom === null ? 1 : 0, 'is_filled' => $filledFrom !== null, 'filled_from' => $filledFrom,
        'as_of' => "{$day} {$time}+00",
    ]);
}

function t05rPrice(object $test, string $cardId, string $key): ?array
{
    $response = $test->getJson("/v1/cards/{$cardId}");
    $response->assertOk();
    foreach ($response->json('data.variants') as $variant) {
        if ($variant['variant_key'] === $key) {
            return $variant['price'];
        }
    }
    throw new RuntimeException("no variant {$key} in the response");
}

function t05rCopyDay(string $root, string $from, ?string $to = null): void
{
    $to ??= $from;
    $src = base_path('tests/Fixtures/tcgcsv-days/snapshots/'.$from);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir("{$root}/{$to}", 0775, true);
    foreach ($it as $file) {
        $dest = "{$root}/{$to}/".substr((string) $file->getPathname(), strlen($src) + 1);
        $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
    }
}

function t05rRemove(string $root): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
    }
    rmdir($root);
}

function t05rIngest(string $day, string $snapshots, bool $force = false): int
{
    return Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots] + ($force ? ['--force' => true] : []));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T05R_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T05R_BASE, T05R_SEED_REF);
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

    Http::preventStrayRequests();
    Http::fake([T05R_BASE.'/api/v1/cards/*' => fn ($request) => Http::response(t05rAnswer(rawurldecode(basename($request->url()))))]);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

it('computes change and change_pct on exact decimals: no float drift and half away from zero at 0.005', function (): void {
    // Pairs a float implementation gets wrong: 0.29-0.28 = 0.010000000000000009, 2.03-1.01 = 1.02 (1.0199999999999998 in
    // floats), and exact .005 ties that float rounding sends the wrong way.
    $pairs = [
        'normal' => ['0.28', '0.29', '0.01', '3.57'],
        'holo' => ['1.01', '2.03', '1.02', '100.99'],
        'reverse' => ['10000.00', '10000.50', '0.50', '0.01'],
        'normal:1st' => ['10000.00', '9999.50', '-0.50', '-0.01'],
        'holo:1st' => ['0.10', '0.30', '0.20', '200.00'],
    ];
    $keys = [];
    foreach (array_keys($pairs) as $label) {
        [$finish, $edition] = array_pad(explode(':', $label), 2, '-');
        $keys[$label] = t05rVariant('base1-5', $finish, $edition);
    }
    foreach ($pairs as $label => [$previous, $latest]) {
        t05rDaily($keys[$label], '2026-10-10', $previous);
        t05rDaily($keys[$label], '2026-10-11', $latest);
    }
    LatestPrices::rebuild(DB::connection());

    $body = $this->getJson('/v1/cards/base1-5')->json('data.variants');
    $got = [];
    foreach ($body as $variant) {
        $got[$variant['variant_key']] = [$variant['price']['previous_close'], $variant['price']['close'], $variant['price']['change'], $variant['price']['change_pct']];
    }
    $expected = [];
    foreach ($pairs as $label => $row) {
        $expected[$keys[$label]] = $row;
    }
    ksort($expected);
    ksort($got);

    expect($got)->toBe($expected);
});

it('keeps a real 0.00 close as a priced block, distinct from price null, with -100.00 against a positive previous', function (): void {
    $zero = t05rVariant('base1-5', 'normal');
    $dropped = t05rVariant('base1-5', 'holo');
    $absent = t05rVariant('base1-5', 'reverse');
    t05rDaily($zero, '2026-10-11', '0.00');
    t05rDaily($dropped, '2026-10-10', '5.00');
    t05rDaily($dropped, '2026-10-11', '0.00');
    LatestPrices::rebuild(DB::connection());

    $zeroPrice = t05rPrice($this, 'base1-5', $zero);

    expect($zeroPrice)->toMatchArray(['close' => '0.00', 'day' => '2026-10-11', 'previous_close' => null, 'change' => null, 'change_pct' => null])
        ->and(t05rPrice($this, 'base1-5', $dropped))->toMatchArray(['close' => '0.00', 'previous_close' => '5.00', 'change' => '-5.00', 'change_pct' => '-100.00'])
        ->and(t05rPrice($this, 'base1-5', $absent))->toBeNull()
        ->and(array_keys($zeroPrice))->toBe(['source', 'feed', 'basis', 'currency', 'cadence', 'close', 'day', 'as_of', 'previous_close', 'previous_day', 'change', 'change_pct', 'series_epoch']);
});

it('takes the close and previous from real candles only: a filled candle is never the latest nor the previous', function (): void {
    $between = t05rVariant('base1-5', 'normal');
    $trailing = t05rVariant('base1-5', 'holo');
    // real d1 10.00, filled d2 (carries 10.00), real d3 12.00 -> previous is d1, not the filled d2.
    t05rDaily($between, '2026-10-08', '10.00');
    t05rDaily($between, '2026-10-09', '10.00', 1, '2026-10-08');
    t05rDaily($between, '2026-10-10', '12.00');
    // real d1 10.00, real d2 11.00, filled d3 and d4 (carry 11.00) -> latest is d2 with its own as_of.
    t05rDaily($trailing, '2026-10-08', '10.00', 1, null, '20:00:00');
    t05rDaily($trailing, '2026-10-09', '11.00', 1, null, '21:00:00');
    t05rDaily($trailing, '2026-10-10', '11.00', 1, '2026-10-09', '22:00:00');
    t05rDaily($trailing, '2026-10-11', '11.00', 1, '2026-10-09', '23:00:00');
    LatestPrices::rebuild(DB::connection());

    expect(t05rPrice($this, 'base1-5', $between))->toMatchArray(['close' => '12.00', 'day' => '2026-10-10', 'previous_close' => '10.00', 'previous_day' => '2026-10-08', 'change' => '2.00', 'change_pct' => '20.00'])
        ->and(t05rPrice($this, 'base1-5', $trailing))->toMatchArray(['close' => '11.00', 'day' => '2026-10-09', 'as_of' => '2026-10-09T21:00:00Z', 'previous_close' => '10.00', 'previous_day' => '2026-10-08', 'change' => '1.00', 'change_pct' => '10.00']);
});

it('serves the new day on a warm catalog cache: the PokéSearch card is cached, the price is not', function (): void {
    $key = 'basep-8:holo:-:-';
    $code = t05rIngest('2026-10-10', base_path('tests/Fixtures/tcgcsv-days/snapshots'));
    expect($code)->toBe(0);
    $first = t05rPrice($this, 'basep-8', $key);

    $code = t05rIngest('2026-10-11', base_path('tests/Fixtures/tcgcsv-days/snapshots'));
    expect($code)->toBe(0);
    $second = t05rPrice($this, 'basep-8', $key);

    // One upstream card read for two requests proves the second answer came from the warm catalog cache.
    Http::assertSentCount(1);
    expect($first['day'])->toBe('2026-10-10')
        ->and($second['day'])->toBe('2026-10-11')
        ->and($second['as_of'])->toBe('2026-10-11T20:05:45Z')
        ->and($second['previous_day'])->toBe('2026-10-10');
});

it('reads a card with 4 queries when all 24 variants carry a price', function (): void {
    $keys = [];
    foreach (['-', 'pokeball'] as $treatment) {
        foreach (['-', '1st', 'unlimited', 'shadowless'] as $edition) {
            foreach (['normal', 'holo', 'reverse'] as $finish) {
                $keys[] = t05rVariant('base1-5', $finish, $edition, $treatment);
            }
        }
    }
    $build = (int) DB::table('map_builds')->max('id');
    foreach ($keys as $n => $key) {
        DB::table('source_product_map')->insert([
            'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 970000 + $n,
            'source_subtype' => 'Holofoil', 'source_product_name' => 'Charizard', 'source_number' => '5/102',
            'variant_key' => $key, 'method' => 'number+name', 'confidence' => '1.000',
            'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
        ]);
        t05rDaily($key, '2026-10-10', '1.00');
        t05rDaily($key, '2026-10-11', '1.50');
    }
    LatestPrices::rebuild(DB::connection());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/v1/cards/base1-5');
    DB::disableQueryLog();
    $queries = DB::getQueryLog();

    $response->assertOk();
    expect($response->json('data.variants'))->toHaveCount(24)
        ->and(array_filter(array_column($response->json('data.variants'), 'price'), fn (mixed $p): bool => $p === null))->toBe([])
        ->and(count($queries))->toBe(4)
        ->and(count(array_filter($queries, fn (array $q): bool => str_contains((string) $q['query'], 'price_latest'))))->toBe(1);
});

it('rolls a failed finalize back whole: price_latest, price_daily and the run keep their last good state', function (): void {
    $root = storage_path('framework/testing/pp-t05r-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    try {
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
            t05rCopyDay($root, $day);
        }
        expect(t05rIngest('2026-10-10', $root))->toBe(0)
            ->and(t05rIngest('2026-10-11', $root))->toBe(0);
        $before = PriceDigest::compute(DB::connection());
        $daily = DB::table('price_daily')->count();

        // A close of 9999999999.99 over a previous close of a few dollars gives a change_pct above numeric(12,2).
        $path = "{$root}/2026-10-12/prices/1543.json.zst";
        $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        $hit = false;
        foreach ($document['results'] as $i => $row) {
            if ($row['productId'] === 83694 && $row['subTypeName'] === 'Normal') {
                $document['results'][$i]['marketPrice'] = '__HUGE__';
                $hit = true;
            }
        }
        expect($hit)->toBeTrue();
        file_put_contents($path, zstd_compress(str_replace('"__HUGE__"', '9999999999.99', json_encode($document, JSON_THROW_ON_ERROR))));

        $code = t05rIngest('2026-10-12', $root);
        $output = Artisan::output();
        $after = PriceDigest::compute(DB::connection());
        $run = DB::table('ingest_runs')->where('day', '2026-10-12')->orderByDesc('id')->first();
    } finally {
        t05rRemove($root);
    }

    expect($code)->not->toBe(0)
        ->and($after)->toBe($before)
        ->and(DB::table('price_daily')->count())->toBe($daily)
        ->and($run->status)->toBe('failed')
        ->and((string) $run->error)->toContain('numeric field overflow')
        ->and(DB::table('price_latest')->count())->toBeGreaterThan(0)
        ->and(DB::table('price_latest')->where('day', '2026-10-12')->count())->toBe(0)
        ->and($output)->not->toBe('');
});

it('gives identical digests, price_latest included, after a forced re-ingest and a second rebuild', function (): void {
    $snapshots = base_path('tests/Fixtures/tcgcsv-days/snapshots');
    expect(t05rIngest('2026-10-10', $snapshots))->toBe(0)
        ->and(t05rIngest('2026-10-11', $snapshots))->toBe(0);
    $first = PriceDigest::compute(DB::connection());

    expect(t05rIngest('2026-10-11', $snapshots, true))->toBe(0);
    $reingested = PriceDigest::compute(DB::connection());
    LatestPrices::rebuild(DB::connection());
    $rebuilt = PriceDigest::compute(DB::connection());

    expect($first['tables']['price_latest']['rows'])->toBeGreaterThan(0)
        ->and($reingested)->toBe($first)
        ->and($rebuilt)->toBe($first);
});

it('drops a variant from price_latest when its only real candle disappears and keeps others untouched', function (): void {
    $kept = t05rVariant('base1-5', 'normal');
    $gone = t05rVariant('base1-5', 'holo');
    t05rDaily($kept, '2026-10-11', '3.00');
    t05rDaily($gone, '2026-10-11', '4.00');
    LatestPrices::rebuild(DB::connection());
    expect(DB::table('price_latest')->whereIn('variant_key', [$kept, $gone])->count())->toBe(2);

    DB::table('price_daily')->where('variant_key', $gone)->delete();
    LatestPrices::rebuild(DB::connection());

    expect(t05rPrice($this, 'base1-5', $gone))->toBeNull()
        ->and(t05rPrice($this, 'base1-5', $kept))->toMatchArray(['close' => '3.00', 'day' => '2026-10-11']);
});
