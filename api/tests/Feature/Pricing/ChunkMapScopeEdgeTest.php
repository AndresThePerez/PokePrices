<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\PriceNormalizer;
use App\Domain\Pricing\PriceSnapshotReader;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for plan Q1-i1 T03: a chunk's scoped map read must give the normalizer exactly what the full map
// gave it, must not turn odd product ids into chunk failures, and the build pin must hold for chunks that follow a
// completed chunk. Self-contained helpers (a test file must not depend on another file's functions).

function t03eFixture(): string
{
    return base_path('tests/Fixtures/tcgcsv-days/snapshots');
}

function t03eBuildMap(): void
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

/** A writable scratch copy of one fixture day; t03eClean() removes it. */
function t03eCopyDay(string $day): string
{
    $root = storage_path('framework/testing/pp-t03e-'.bin2hex(random_bytes(6)));
    $from = t03eFixture().'/'.$day;
    mkdir("{$root}/{$day}", 0775, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($from) + 1);
        $file->isDir() ? mkdir($to, 0775, true) : copy((string) $file->getPathname(), $to);
    }
    t03eRoots($root);

    return $root;
}

function t03eRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

function t03eClean(): void
{
    foreach (t03eRoots() as $root) {
        if (! is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
}

beforeEach(function (): void {
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    t03eBuildMap();
});

afterEach(fn () => t03eClean());

test('each chunk\'s recorded counts equal what the normalizer gives the same rows with the full map, at every chunk size', function (int $chunkRows): void {
    config(['pricing.chunk_rows' => $chunkRows]);
    $full = [];
    foreach (DB::table('source_product_map')->where('source', 'tcgplayer')->get() as $r) {
        $full[$r->source_product_id.'|'.$r->source_subtype] = ['variant_key' => (string) $r->variant_key, 'series_epoch' => (int) $r->series_epoch];
    }
    $run = (new DayIngest(DB::connection(), t03eFixture()))->sync('2026-10-12');
    $reader = PriceSnapshotReader::at(t03eFixture(), '2026-10-12');
    $checked = 0;
    foreach (DB::table('ingest_run_chunks')->where('ingest_run_id', $run['run_id'])->orderBy('id')->get() as $chunk) {
        $rows = array_slice($reader->rows((int) $chunk->group_id), (int) $chunk->offset, (int) $chunk->rows);
        $expected = PriceNormalizer::normalize($rows, $full);
        $recorded = json_decode((string) $chunk->counts, true, 512, JSON_THROW_ON_ERROR);
        expect([$recorded['mapped'], $recorded['unmapped'], $recorded['null_market']])
            ->toBe([$expected['mapped'], $expected['unmapped'], $expected['null_market']]);
        $checked++;
    }

    expect($run['status'])->toBe('ok')->and($checked)->toBeGreaterThan(0);
})->with([1, 2, 3, 7, 200]);

test('product ids outside the integer column\'s range, zero and negative ids are unmapped rows, not a failed chunk', function (): void {
    $root = t03eCopyDay('2026-10-11');
    $before = (new DayIngest(DB::connection(), $root))->sync('2026-10-11');
    expect($before['status'])->toBe('ok');
    $baseline = DB::table('ingest_run_chunks')->where('ingest_run_id', $before['run_id'])->get(['counts'])
        ->reduce(fn (array $sum, object $c): array => [
            'mapped' => $sum['mapped'] + json_decode((string) $c->counts, true)['mapped'],
            'unmapped' => $sum['unmapped'] + json_decode((string) $c->counts, true)['unmapped'],
        ], ['mapped' => 0, 'unmapped' => 0]);

    $path = "{$root}/2026-10-11/prices/1543.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ([3000000000, 0, -5] as $id) {
        $document['results'][] = ['productId' => $id, 'subTypeName' => 'Normal', 'lowPrice' => 1.0, 'midPrice' => 1.0, 'highPrice' => 1.0, 'marketPrice' => 1.0, 'directLowPrice' => null];
    }
    file_put_contents($path, zstd_compress(json_encode($document, JSON_THROW_ON_ERROR)));

    $after = (new DayIngest(DB::connection(), $root))->sync('2026-10-11', true);
    $counts = DB::table('ingest_run_chunks')->where('ingest_run_id', $after['run_id'])->get(['counts'])
        ->reduce(fn (array $sum, object $c): array => [
            'mapped' => $sum['mapped'] + (json_decode((string) $c->counts, true)['mapped'] ?? 0),
            'unmapped' => $sum['unmapped'] + (json_decode((string) $c->counts, true)['unmapped'] ?? 0),
        ], ['mapped' => 0, 'unmapped' => 0]);

    expect([$after['status'], $after['error']])->toBe(['ok', null])
        ->and($counts)->toBe(['mapped' => $baseline['mapped'], 'unmapped' => $baseline['unmapped'] + 3]);
});

test('a map build that lands after the first chunk finished fails the next chunk and leaves the finished chunk\'s price points alone', function (): void {
    config(['pricing.chunk_rows' => 1]);
    $ingest = new DayIngest(DB::connection(), t03eFixture());
    $start = $ingest->start('2026-10-11', 'queued');
    $first = null;
    foreach ($start['chunks'] as $chunkId) {
        $first = $ingest->chunk($start['run_id'], $chunkId);
        if ($first !== null && ($first['mapped'] ?? 0) > 0) {
            break;
        }
    }
    $pointsAfterFirst = DB::table('price_points')->where('ingest_run_id', $start['run_id'])->count();
    $doneIds = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('status', 'done')->pluck('id')->all();

    t03eBuildMap();
    $message = null;
    $next = DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('status', 'pending')->orderBy('id')->value('id');
    try {
        $ingest->chunk($start['run_id'], (int) $next);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($pointsAfterFirst)->toBeGreaterThan(0)
        ->and($message)->toStartWith("the product map changed since ingest run {$start['run_id']} started")
        ->and(DB::table('price_points')->where('ingest_run_id', $start['run_id'])->count())->toBe($pointsAfterFirst)
        ->and(DB::table('ingest_run_chunks')->where('ingest_run_id', $start['run_id'])->where('status', 'done')->pluck('id')->all())->toBe($doneIds)
        ->and(DB::table('ingest_run_chunks')->where('id', $next)->value('status'))->toBe('pending');
});

test('a day ingested after a map rebuild records the new build and ingests', function (): void {
    $old = (new DayIngest(DB::connection(), t03eFixture()))->start('2026-10-11', 'queued');
    DB::table('ingest_runs')->where('id', $old['run_id'])->update(['status' => 'failed']);
    t03eBuildMap();
    $newBuild = (int) DB::table('map_builds')->max('id');

    $fresh = (new DayIngest(DB::connection(), t03eFixture()))->sync('2026-10-11', true);
    $freshBuild = json_decode((string) DB::table('ingest_runs')->where('id', $fresh['run_id'])->value('counts'), true, 512, JSON_THROW_ON_ERROR)['map_build_id'];

    expect($fresh['status'])->toBe('ok')
        ->and($freshBuild)->toBe($newBuild);
});

test('a run whose recorded build is not the current one (a forged id) fails the chunk and writes nothing', function (): void {
    $ingest = new DayIngest(DB::connection(), t03eFixture());
    $start = $ingest->start('2026-10-11', 'queued');
    $message = null;
    DB::table('ingest_runs')->where('id', $start['run_id'])->update(['counts' => DB::raw("jsonb_set(counts, '{map_build_id}', '999999')")]);
    try {
        $ingest->chunk($start['run_id'], $start['chunks'][0]);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toBe("the product map changed since ingest run {$start['run_id']} started (map build 999999, now ".(int) DB::table('map_builds')->max('id').')')
        ->and(DB::table('price_points')->count())->toBe(0);
});
