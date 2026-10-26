<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\PriceSnapshotReader;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Plan Q1-i1 T03 (review b B1): each ingest chunk loads only the source_product_map rows of its own products, in one
// query, and only while the map build its run started on is still the current one. The fixture days are only read.

/** The 2026-10-12 rows with no map row (pinned from the full-map ingest before T03). */
const T03_UNMAPPED_1012 = 8;

function t03Fixture(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

/**
 * Counts what the source_product_map reads return to PHP: [queries, rows]. Each read is re-run once on the same
 * connection to count its result rows.
 *
 * @return object{queries: int, rows: int}
 */
function t03MapReads(): object
{
    $tally = (object) ['queries' => 0, 'rows' => 0, 'busy' => false];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($tally): void {
        if ($tally->busy || ! str_contains($query->sql, 'source_product_map') || ! str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
            return;
        }
        $tally->busy = true;
        try {
            $tally->queries++;
            $tally->rows += count($query->connection->select($query->sql, $query->bindings));
        } finally {
            $tally->busy = false;
        }
    });

    return $tally;
}

/** For each chunk of $runId: the source_product_map rows whose product appears in that chunk's snapshot rows. */
function t03ScopedMapRows(int $runId, string $day): array
{
    $reader = PriceSnapshotReader::at(t03Fixture(), $day);
    $out = [];
    foreach (DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->orderBy('id')->get() as $chunk) {
        $rows = array_slice($reader->rows((int) $chunk->group_id), (int) $chunk->offset, (int) $chunk->rows);
        $products = array_values(array_unique(array_map(fn (array $r): int => (int) $r['productId'], $rows)));
        $out[(string) $chunk->chunk_key] = DB::table('source_product_map')->where('source', 'tcgplayer')
            ->whereIn('source_product_id', $products)->count();
    }

    return $out;
}

/** Every chunk's counts of $runId keyed by chunk_key, in chunk order. */
function t03ChunkCounts(int $runId): array
{
    $out = [];
    foreach (DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->orderBy('id')->get() as $chunk) {
        $counts = json_decode((string) $chunk->counts, true, 512, JSON_THROW_ON_ERROR);
        ksort($counts);
        $out[(string) $chunk->chunk_key] = $counts;
    }

    return $out;
}

/** The price_points rows as text in a stable order, ingest_run_id left out. */
function t03Points(): array
{
    return array_map(fn (object $r): array => (array) $r, DB::select(<<<'SQL'
        SELECT variant_key, source, to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS observed_at,
               day::text AS day, series_epoch, source_product_id, source_subtype,
               market::text AS market, low::text AS low, mid::text AS mid, high::text AS high, direct_low::text AS direct_low
        FROM price_points ORDER BY variant_key, observed_at, source_product_id, source_subtype
        SQL));
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
});

/** Opens a queued run of $day and runs each chunk on its own DayIngest, as each IngestChunk job does. */
function t03RunChunksAsJobs(string $day): int
{
    $start = (new DayIngest(DB::connection(), t03Fixture()))->start($day, 'queued');
    foreach ($start['chunks'] as $chunkId) {
        (new DayIngest(DB::connection(), t03Fixture()))->chunk($start['run_id'], $chunkId);
    }

    return $start['run_id'];
}

test('(a) B1: across a nine-chunk day each chunk job reads only its own products\' map rows, in one query per chunk', function (): void {
    $fullMap = DB::table('source_product_map')->where('source', 'tcgplayer')->count();
    $tally = t03MapReads();

    $runId = t03RunChunksAsJobs('2026-10-11');
    $tally->busy = true;
    $scoped = t03ScopedMapRows($runId, '2026-10-11');

    expect(DB::table('ingest_run_chunks')->where('ingest_run_id', $runId)->where('status', 'done')->count())->toBe(9)
        ->and(count($scoped))->toBe(9)
        ->and($fullMap)->toBe(22)
        ->and($tally->queries)->toBe(9)
        ->and($tally->rows)->toBe(array_sum($scoped))
        ->and($tally->rows)->toBeLessThan(9 * $fullMap);
});

test('(a) B1: with one row per chunk every chunk job still reads only the map rows of its one product', function (): void {
    config(['pricing.chunk_rows' => 1]);
    $tally = t03MapReads();

    $runId = t03RunChunksAsJobs('2026-10-11');
    $tally->busy = true;
    $scoped = t03ScopedMapRows($runId, '2026-10-11');

    expect(count($scoped))->toBe(29)
        ->and($tally->queries)->toBe(29)
        ->and($tally->rows)->toBe(array_sum($scoped))
        ->and(max($scoped))->toBeLessThanOrEqual(2);
});

test('(b) characterisation: every chunk\'s counts and the stored price points match the full-map baseline', function (int $chunkRows, string $countsSha, string $pointsSha): void {
    config(['pricing.chunk_rows' => $chunkRows]);
    // The fixture carries every row kind this pins: unmapped products, null markets and a product with two mapped subtypes.
    $multi = DB::table('source_product_map')->where('source', 'tcgplayer')->select('source_product_id')
        ->groupBy('source_product_id')->havingRaw('count(*) > 1')->count();
    $ingest = new DayIngest(DB::connection(), t03Fixture());

    $first = $ingest->sync('2026-10-12');
    $again = $ingest->sync('2026-10-12', true);
    $counts = ['first' => t03ChunkCounts((int) $first['run_id']), 'again' => t03ChunkCounts((int) $again['run_id'])];
    $totals = fn (array $chunks): array => array_reduce($chunks, fn (array $sum, array $c): array => [
        'mapped' => $sum['mapped'] + $c['mapped'], 'unmapped' => $sum['unmapped'] + $c['unmapped'],
        'null_market' => $sum['null_market'] + $c['null_market'], 'upserted' => $sum['upserted'] + $c['upserted'],
        'unchanged' => $sum['unchanged'] + $c['unchanged'],
    ], ['mapped' => 0, 'unmapped' => 0, 'null_market' => 0, 'upserted' => 0, 'unchanged' => 0]);

    expect([$first['status'], $again['status']])->toBe(['ok', 'ok'])
        ->and($multi)->toBeGreaterThan(0)
        ->and($totals($counts['first']))->toBe(['mapped' => 20, 'unmapped' => T03_UNMAPPED_1012, 'null_market' => 3, 'upserted' => 20, 'unchanged' => 0])
        ->and($totals($counts['again']))->toBe(['mapped' => 20, 'unmapped' => T03_UNMAPPED_1012, 'null_market' => 3, 'upserted' => 0, 'unchanged' => 20])
        ->and(hash('sha256', json_encode($counts, JSON_THROW_ON_ERROR)))->toBe($countsSha)
        ->and(hash('sha256', json_encode(t03Points(), JSON_THROW_ON_ERROR)))->toBe($pointsSha);
})->with([
    'default chunk size' => [200, '84bdf701dfe9e15682834a78a16183f0674acef2bab4e872aa92a4fd712155df', 'f8f7da036c43bc96272b6fae014706d75dd60db410b0ae3c9b1398f1f2e986bb'],
    'one row per chunk' => [1, '675cf94269935f5b87341d3db068154cadf26c0a380a627107aba10dba25e79b', 'f8f7da036c43bc96272b6fae014706d75dd60db410b0ae3c9b1398f1f2e986bb'],
]);

test('(c) a map build that lands between start and a chunk fails the chunk naming the run, and the chunk writes no price point', function (): void {
    $ingest = new DayIngest(DB::connection(), t03Fixture());
    $start = $ingest->start('2026-10-11', 'queued');
    $recorded = json_decode((string) DB::table('ingest_runs')->where('id', $start['run_id'])->value('counts'), true, 512, JSON_THROW_ON_ERROR)['map_build_id'];
    $newer = (int) DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);

    $error = null;
    try {
        $ingest->chunk($start['run_id'], $start['chunks'][0]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect($recorded)->toBeInt()->not->toBe($newer)
        ->and($error)->toBe("the product map changed since ingest run {$start['run_id']} started (map build {$recorded}, now {$newer})")
        ->and(DB::table('price_points')->count())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->where('id', $start['chunks'][0])->value('status'))->toBe('pending');
});

test('(c) a run that recorded no map build skips the build check and ingests its chunk', function (): void {
    $ingest = new DayIngest(DB::connection(), t03Fixture());
    $start = $ingest->start('2026-10-11', 'queued');
    $counts = json_decode((string) DB::table('ingest_runs')->where('id', $start['run_id'])->value('counts'), true, 512, JSON_THROW_ON_ERROR);
    DB::table('ingest_runs')->where('id', $start['run_id'])->update(['counts' => json_encode(['map_build_id' => null] + $counts, JSON_THROW_ON_ERROR)]);
    DB::table('map_builds')->insert([
        'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:01:00+00',
        'counts' => json_encode(['marker' => '2026-10-11T20:05:45+00:00'], JSON_THROW_ON_ERROR),
    ]);

    $chunk = $ingest->chunk($start['run_id'], $start['chunks'][0]);

    expect($chunk)->toBeArray()
        ->and(DB::table('ingest_run_chunks')->where('id', $start['chunks'][0])->value('status'))->toBe('done');
});
