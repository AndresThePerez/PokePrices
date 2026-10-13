<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// pokeprices:prices:replay --fresh (plan P2-i1 T07 items 3 and 4, D11, D12, R180, review F5, F6) over the five
// synthetic days of tests/Fixtures/tcgcsv-days and the map built from the P1 fixture day. Broken days are copies
// under a scratch root; the fixture tree itself is only ever read.

const T07R_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function t07rFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/**
 * Runs the replay command and returns [exit code, output, the decoded JSON document or null].
 *
 * @return array{0: int, 1: string, 2: array<string, mixed>|null}
 */
function t07rReplay(array $options = []): array
{
    $code = Artisan::call('pokeprices:prices:replay', ['--snapshots' => t07rFixture('snapshots'), ...$options]);
    $output = Artisan::output();
    $start = strpos($output, '{');
    $json = $start === false ? null : json_decode(substr($output, $start), true);

    return [$code, $output, is_array($json) ? $json : null];
}

/** Runs the sync ingest of each day and returns the exit codes keyed by day. */
function t07rIngest(array $days, ?string $snapshots = null): array
{
    $codes = [];
    foreach ($days as $day) {
        $codes[$day] = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t07rFixture('snapshots')]);
    }

    return $codes;
}

/** The ingest_runs rows as [id, day, mode, status], by id. */
function t07rRuns(): array
{
    return array_map(fn (object $r): array => [(int) $r->id, $r->day, $r->mode, $r->status],
        DB::select('SELECT id, day::text AS day, mode, status FROM ingest_runs ORDER BY id'));
}

/** Copies the named fixture days under a fresh scratch root that afterEach removes. */
function t07rCopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t07r-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t07rRoots($root);
    foreach ($days as $day) {
        $src = t07rFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t07rRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Every file under $dir as relative path => sha1. */
function t07rTree(string $dir): array
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
    $this->fixtureTree = t07rTree(t07rFixture('snapshots'));
});

afterEach(function (): void {
    expect(t07rTree(t07rFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t07rRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('replay --fresh of the five days is identical in asc and in desc, with the same digests across the two orders', function (): void {
    [$ascCode, $ascOutput, $asc] = t07rReplay(['--fresh' => true, '--order' => 'asc']);
    [$descCode, $descOutput, $desc] = t07rReplay(['--fresh' => true, '--order' => 'desc']);

    $digests = fn (array $doc): array => array_map(fn (array $p): array => $p['digests'], $doc['passes']);

    expect($ascCode)->toBe(0, $ascOutput)
        ->and($descCode)->toBe(0, $descOutput)
        ->and(array_keys($asc))->toBe(['days', 'day_count', 'order', 'map_build_id', 'code_sha', 'passes', 'identical'])
        ->and($asc['days'])->toBe(T07R_DAYS)
        ->and($asc['day_count'])->toBe(5)
        ->and([$asc['order'], $desc['order']])->toBe(['asc', 'desc'])
        ->and($asc['map_build_id'])->toBe((int) DB::table('map_builds')->max('id'))
        ->and($asc['code_sha'])->toBeNull()
        ->and(array_column($asc['passes'], 'pass'))->toBe([1, 2, 3])
        ->and(array_keys($asc['passes'][0]))->toBe(['pass', 'digests', 'movers_check', 'seconds'])
        ->and(array_keys($asc['passes'][0]['digests']))->toBe(['price_points', 'price_daily', 'price_latest'])
        ->and($asc['passes'][0]['digests']['price_daily']['rows'])->toBe(120)
        ->and($asc['passes'][0]['digests']['price_latest']['rows'])->toBeGreaterThan(0)
        ->and($asc['passes'][0]['digests']['price_points']['rows'])->toBeGreaterThan(0)
        ->and($asc['passes'][1]['digests'])->toBe($asc['passes'][0]['digests'])
        ->and($asc['passes'][2]['digests'])->toBe($asc['passes'][0]['digests'])
        ->and([$asc['identical'], $desc['identical']])->toBe([true, true])
        ->and($digests($desc))->toBe($digests($asc))
        // The replay never writes price_events (R180a): the real table (T13) stays empty, though 10-11 and 10-14 cross
        // the draft rule.
        ->and(DB::selectOne("SELECT to_regclass('price_events') AS t")->t)->toBe('price_events')
        ->and(DB::table('price_events')->count())->toBe(0);
});

test('a replay ends in the state a live ascending ingest of the same days gives', function (): void {
    expect(t07rIngest(T07R_DAYS))->toBe(array_fill_keys(T07R_DAYS, 0));
    $live = PriceDigest::compute(DB::connection())['tables'];

    [$code, $output, $doc] = t07rReplay(['--fresh' => true, '--order' => 'desc']);

    expect($code)->toBe(0, $output)
        ->and($doc['passes'][2]['digests'])->toBe($live)
        ->and(PriceDigest::compute(DB::connection())['tables'])->toBe($live);
});

test('--code-sha is carried into the output, and a malformed one is refused before anything runs', function (): void {
    [$code, $output, $doc] = t07rReplay(['--fresh' => true, '--code-sha' => 'a6a2de2c0ffee']);
    [$badCode, $badOutput] = t07rReplay(['--fresh' => true, '--code-sha' => 'not a sha']);

    expect($code)->toBe(0, $output)
        ->and($doc['code_sha'])->toBe('a6a2de2c0ffee')
        ->and($badCode)->toBe(1)
        ->and($badOutput)->toContain('--code-sha');
});

test('without --fresh it names the tables it would wipe, exits 1 and changes nothing', function (): void {
    expect(t07rIngest(['2026-10-11']))->toBe(['2026-10-11' => 0]);
    $before = [PriceDigest::compute(DB::connection()), t07rRuns()];

    [$code, $output] = t07rReplay();

    expect($code)->toBe(1)
        ->and($output)->toContain('--fresh')
        ->and($output)->toContain('price_points')
        ->and($output)->toContain('price_daily')
        ->and($output)->toContain('price_latest')
        ->and($output)->not->toContain('price_events')
        ->and([PriceDigest::compute(DB::connection()), t07rRuns()])->toBe($before);
});

test('preflight: a day missing a prices file exits 1 naming the day, before anything is wiped', function (): void {
    expect(t07rIngest(['2026-10-10', '2026-10-11']))->toBe(['2026-10-10' => 0, '2026-10-11' => 0]);
    $before = [PriceDigest::compute(DB::connection()), t07rRuns()];
    $root = t07rCopyDays(...T07R_DAYS);
    unlink("{$root}/2026-10-12/prices/604.json.zst");

    [$code, $output] = t07rReplay(['--fresh' => true, '--snapshots' => $root]);

    expect($code)->toBe(1)
        ->and($output)->toContain('2026-10-12')
        ->and($output)->toContain('missing_file:prices/604.json.zst')
        ->and($before[0]['tables']['price_points']['rows'])->toBeGreaterThan(0)
        ->and([PriceDigest::compute(DB::connection()), t07rRuns()])->toBe($before);
});

test('an empty --days, an empty root or an unknown day exits 1 and changes nothing', function (): void {
    expect(t07rIngest(['2026-10-11']))->toBe(['2026-10-11' => 0]);
    $before = [PriceDigest::compute(DB::connection()), t07rRuns()];
    $empty = t07rCopyDays();
    $unfinished = t07rCopyDays('2026-10-11');
    unlink("{$unfinished}/2026-10-11/SUCCESS");

    [$emptyDays, $emptyDaysOutput] = t07rReplay(['--fresh' => true, '--days' => '']);
    [$emptyRoot, $emptyRootOutput] = t07rReplay(['--fresh' => true, '--snapshots' => $empty]);
    [$noSuccess, $noSuccessOutput] = t07rReplay(['--fresh' => true, '--snapshots' => $unfinished]);
    [$unknown, $unknownOutput] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11,2026-10-13']);
    [$malformed, $malformedOutput] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11,yesterday']);

    expect([$emptyDays, $emptyRoot, $noSuccess, $unknown, $malformed])->toBe([1, 1, 1, 1, 1])
        ->and($emptyDaysOutput)->toContain('no collected days')
        ->and($emptyRootOutput)->toContain('no collected days')
        // A folder without SUCCESS is not a collected day.
        ->and($noSuccessOutput)->toContain('no collected days')
        ->and($unknownOutput)->toContain('2026-10-13')
        ->and($malformedOutput)->toContain('yesterday')
        ->and([PriceDigest::compute(DB::connection()), t07rRuns()])->toBe($before);
});

test('--days replays only the named days, and a bad --order is refused', function (): void {
    [$code, $output, $doc] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11,2026-10-09', '--order' => 'desc']);
    [$badOrder, $badOrderOutput] = t07rReplay(['--fresh' => true, '--order' => 'random']);

    expect($code)->toBe(0, $output)
        ->and([$doc['days'], $doc['day_count'], $doc['identical']])->toBe([['2026-10-09', '2026-10-11'], 2, true])
        ->and(DB::table('price_points')->distinct()->orderBy('day')->pluck('day')->map(fn (mixed $d): string => substr((string) $d, 0, 10))->all())
        ->toBe(['2026-10-09', '2026-10-11'])
        ->and($badOrder)->toBe(1)
        ->and($badOrderOutput)->toContain('--order');
});

test('the wipe marks the ok and running runs superseded; failed and refused runs keep their status', function (): void {
    expect(t07rIngest(['2026-10-10', '2026-10-11']))->toBe(['2026-10-10' => 0, '2026-10-11' => 0]);
    $running = DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-12',
        'marker' => '2026-10-12 20:05:44+00', 'mode' => 'queued', 'status' => 'running', 'started_at' => '2026-10-12 21:00:00+00']);
    $failed = DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-14',
        'marker' => '2026-10-14 20:05:46+00', 'mode' => 'sync', 'status' => 'failed', 'started_at' => '2026-10-14 21:00:00+00',
        'finished_at' => '2026-10-14 21:01:00+00', 'error' => 't07 earlier failure']);
    $before = t07rRuns();

    [$code, $output] = t07rReplay(['--fresh' => true]);
    $after = t07rRuns();
    $byId = array_column($after, null, 0);
    $replayRuns = array_values(array_filter($after, fn (array $r): bool => $r[0] > $failed));

    expect($code)->toBe(0, $output)
        ->and([$byId[$before[0][0]][3], $byId[$before[1][0]][3], $byId[$running][3], $byId[$failed][3]])
        ->toBe(['superseded', 'superseded', 'superseded', 'failed'])
        // Three passes of five days, mode replay; pass 2's wipe supersedes pass 1, pass 3 adds without a wipe.
        ->and(count($replayRuns))->toBe(15)
        ->and(array_unique(array_column($replayRuns, 2)))->toBe(['replay'])
        ->and(array_column(array_slice($replayRuns, 0, 5), 3))->toBe(array_fill(0, 5, 'superseded'))
        ->and(array_column(array_slice($replayRuns, 5), 3))->toBe(array_fill(0, 10, 'ok'));
});

test('with the pricing:ingest lock held by another connection it exits 1 and touches nothing', function (): void {
    expect(t07rIngest(['2026-10-11']))->toBe(['2026-10-11' => 0]);
    $before = [PriceDigest::compute(DB::connection()), t07rRuns()];

    config(['database.connections.t07_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t07_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [IngestLock::KEY]);
    try {
        [$code, $output] = t07rReplay(['--fresh' => true]);
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('t07_holder');
    }

    expect($code)->toBe(1)
        ->and($output)->toContain('pricing ingest lock held')
        ->and([PriceDigest::compute(DB::connection()), t07rRuns()])->toBe($before);
});

test('the lock is released after a replay, so another connection can take it', function (): void {
    [$code] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11']);

    config(['database.connections.t07_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('t07_holder');
    $taken = $holder->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [IngestLock::KEY])->ok;
    $holder->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t07_holder');

    expect($code)->toBe(0)
        ->and($taken)->toBeTrue();
});

test('--fresh refuses while a price_events row has dispatched_at set, and passes while none has', function (): void {
    // The real price_events table (T13): one undispatched event from a sync ingest, then one dispatched row.
    expect(t07rIngest(['2026-10-10', '2026-10-11']))->toBe(['2026-10-10' => 0, '2026-10-11' => 0]);
    $id = (int) DB::table('price_events')->value('id');

    [$pending, $pendingOutput] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11']);
    $events = DB::select('SELECT id, variant_key, dispatched_at FROM price_events ORDER BY id');
    DB::table('price_events')->insert([['type' => 'price.moved', 'rule' => 'draft-v1', 'variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46',
        'source' => 'tcgplayer', 'day' => '2026-10-12', 'series_epoch' => 1, 'direction' => 'down', 'close' => '4.00', 'previous_close' => '5.50',
        'previous_day' => '2026-10-11', 'change' => '-1.50', 'change_pct' => '-27.27', 'as_of' => '2026-10-12 20:05:44+00',
        'previous_as_of' => '2026-10-11 20:05:45+00', 'ingest_run_id' => 1, 'dispatched_at' => '2026-10-12 00:00:00+00']]);
    $before = [PriceDigest::compute(DB::connection()), t07rRuns()];
    [$dispatched, $dispatchedOutput] = t07rReplay(['--fresh' => true, '--days' => '2026-10-11']);

    expect($pending)->toBe(0, $pendingOutput)
        ->and($events)->toEqual([(object) ['id' => $id, 'variant_key' => 'basep-8:holo:-:-', 'dispatched_at' => null]])
        ->and($dispatched)->toBe(1)
        ->and($dispatchedOutput)->toContain('dispatched')
        ->and([PriceDigest::compute(DB::connection()), t07rRuns()])->toBe($before)
        ->and(DB::table('price_events')->count())->toBe(2);
});
