<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for T13 (price_events outbox, movement_volume): hostile ingest sequences, outbox immutability
// against every writer, epoch bumps, rule boundaries on the rounded change_pct (D4, D6), the volume grid against an
// independent LAG query, volume determinism across replay orders, and the queued finalize retry (R177(4), R180a, R190).

const T13R_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function t13rFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync and returns day => run id. */
function t13rIngest(array $days, ?string $snapshots = null, bool $force = false): array
{
    $runs = [];
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--force' => $force, '--snapshots' => $snapshots ?? t13rFixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
        $runs[$day] = (int) DB::table('ingest_runs')->max('id');
    }

    return $runs;
}

function t13rCounts(int $runId): array
{
    return json_decode((string) DB::table('ingest_runs')->where('id', $runId)->value('counts'), true, 512, JSON_THROW_ON_ERROR);
}

/** Every price_events row as text, by id, including id, run id and dispatched_at. */
function t13rDump(): array
{
    return array_map(fn (object $r): string => (string) $r->t, DB::select('SELECT e::text AS t FROM price_events e ORDER BY id'));
}

/** Event content without id and ingest_run_id, in key order; optionally one day. */
function t13rContent(?string $day = null): array
{
    return array_map(fn (object $r): string => implode(' ', (array) $r), DB::select(<<<'SQL'
        SELECT type, rule, variant_key, card_id, source, day::text, series_epoch, direction, close::text, previous_close::text,
               previous_day::text, change::text, change_pct::text,
               to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of,
               to_char(previous_as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS previous_as_of,
               coalesce(dispatched_at::text, 'null') AS dispatched_at
        FROM price_events WHERE (?::date IS NULL OR day = ?::date) ORDER BY day, variant_key COLLATE "C", series_epoch
        SQL, [$day, $day]));
}

function t13rVolume(): array
{
    return array_map(fn (object $r): string => (string) $r->t, DB::select('SELECT v::text AS t FROM movement_volume v ORDER BY day, rule'));
}

function t13rRoots(?string $add = null): array
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
function t13rCopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t13r-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t13rRoots($root);
    foreach ($days as $day) {
        $src = t13rFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

function t13rSetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T13R_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T13R_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** Sets a variant's close on two consecutive scratch days. */
function t13rPair(string $root, string $from, string $to, string $variant, string $a, string $b): void
{
    $map = [
        'basep-13' => [1418, 85001, 'Holofoil'], 'tk1b-8' => [1543, 88336, 'Normal'],
        'ex10' => [1398, 90166, 'Holofoil'], 'sv8pt5-5' => [23821, 610540, 'Holofoil'],
    ];
    [$group, $product, $subtype] = $map[$variant];
    t13rSetMarket($root, $from, $group, $product, $subtype, $a);
    t13rSetMarket($root, $to, $group, $product, $subtype, $b);
}

function t13rReplay(string $order, ?string $snapshots = null, array $extra = []): int
{
    return Artisan::call('pokeprices:prices:replay', ['--snapshots' => $snapshots ?? t13rFixture('snapshots'), '--fresh' => true, '--order' => $order] + $extra);
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
});

afterEach(function (): void {
    foreach (t13rRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('forcing an old day again, or the newest day a second time, leaves every event row byte-identical, ids and run ids included (R177(4))', function (): void {
    t13rIngest(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14']);
    $before = t13rDump();

    $old = t13rIngest(['2026-10-10', '2026-10-11', '2026-10-12'], null, true);
    $newestAgain = t13rIngest(['2026-10-14'], null, true);

    expect($before)->toHaveCount(2)
        ->and(t13rDump())->toBe($before)
        ->and(array_map(fn (int $run): int => t13rCounts($run)['events'], $old))->toBe(['2026-10-10' => 0, '2026-10-11' => 0, '2026-10-12' => 0])
        ->and(t13rCounts($newestAgain['2026-10-14'])['events'])->toBe(0)
        ->and(t13rCounts($newestAgain['2026-10-14'])['backfill'])->toBe(0);
});

test('a replay --fresh followed by one newest-day ingest reproduces that day\'s events with identical content, and nothing else', function (): void {
    t13rIngest(T13R_DAYS);
    $expected = t13rContent('2026-10-14');
    DB::table('price_events')->delete();

    expect(t13rReplay('desc'))->toBe(0)
        ->and(DB::table('price_events')->count())->toBe(0);
    t13rIngest(['2026-10-14'], null, true);

    expect($expected)->toHaveCount(1)
        ->and(t13rContent())->toBe($expected);
});

test('no ingest, queued finalize, replay in either order or volume command ever touches an undispatched outbox row, even one that owns the outbox key', function (): void {
    $row = ['type' => 'price.moved', 'rule' => 'draft-v1', 'card_id' => 'basep-8', 'source' => 'tcgplayer', 'series_epoch' => 1,
        'direction' => 'down', 'close' => '1.00', 'previous_close' => '9.00', 'previous_day' => '2026-10-10', 'change' => '-8.00',
        'change_pct' => '-88.89', 'as_of' => '2026-10-11 20:05:45+00', 'previous_as_of' => '2026-10-10 20:05:42+00', 'ingest_run_id' => 424242];
    // One row squats the key of the real basep-8 event on 10-11, one is a stray with a future day and another variant.
    DB::table('price_events')->insert([
        $row + ['variant_key' => 'basep-8:holo:-:-', 'day' => '2026-10-11'],
        array_merge($row, ['variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46', 'day' => '2026-12-31']),
    ]);
    $poison = t13rDump();

    t13rIngest(T13R_DAYS);
    t13rIngest(['2026-10-10', '2026-10-14'], null, true);
    $ingest = new DayIngest(DB::connection(), t13rFixture('snapshots'));
    $start = $ingest->start('2026-10-14', 'queued', true);
    foreach ($start['chunks'] as $chunk) {
        $ingest->chunk($start['run_id'], $chunk);
    }
    $ingest->finalize($start['run_id']);
    $codes = [t13rReplay('asc'), t13rReplay('desc'), Artisan::call('pokeprices:prices:movement-volume')];
    $rows = t13rDump();

    expect($codes)->toBe([0, 0, 0])
        // The two poisoned rows come first by id and are unchanged; the key owner kept the real crossing out of the outbox.
        ->and(array_slice($rows, 0, 2))->toBe($poison)
        ->and(DB::table('price_events')->where('variant_key', 'basep-8:holo:-:-')->where('day', '2026-10-11')->count())->toBe(1)
        // Only the genuine base1-8 crossing of 10-14 was added.
        ->and(count($rows))->toBe(3);
});

test('a map epoch bump followed by a forced re-ingest of the newest day neither fires across the break nor adds a second event for the same variant and day', function (): void {
    $runs = t13rIngest(['2026-10-10', '2026-10-11']);
    $before = t13rDump();
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    $again = t13rIngest(['2026-10-11'], null, true);

    expect($before)->toHaveCount(1)
        ->and(t13rDump())->toBe($before)
        ->and(DB::table('price_events')->where('variant_key', 'basep-8:holo:-:-')->where('day', '2026-10-11')->count())->toBe(1)
        ->and(t13rCounts($again['2026-10-11']))->toMatchArray(['events' => 0, 'epoch_breaks' => 1])
        ->and($runs['2026-10-11'])->not->toBe($again['2026-10-11']);
});

test('the rule uses the rounded change_pct half away from zero, ignores a 0.00 previous close, and holds at the largest real ratio', function (): void {
    $root = t13rCopyDays('2026-10-10', '2026-10-11');
    // +4.995 % rounds to 5.00 and fires; -4.995 % rounds to -5.00 and fires (D4, D6 test the rounded figure).
    t13rPair($root, '2026-10-10', '2026-10-11', 'basep-13', '200.00', '209.99');
    t13rPair($root, '2026-10-10', '2026-10-11', 'tk1b-8', '200.00', '190.01');
    // A 0.00 previous close has no change_pct and never fires, and must not break the finalize.
    t13rPair($root, '2026-10-10', '2026-10-11', 'ex10', '0.00', '5.00');
    // 0.01 to the largest observed price (214398.51): 2,143,985,000.00 % still fits numeric(12,2).
    t13rPair($root, '2026-10-10', '2026-10-11', 'sv8pt5-5', '0.01', '214398.51');
    $runs = t13rIngest(['2026-10-10', '2026-10-11'], $root);

    $events = DB::table('price_events')->orderBy('variant_key')->get(['variant_key', 'direction', DB::raw('change::text AS change'), DB::raw('change_pct::text AS change_pct')])
        ->map(fn (object $e): string => "{$e->variant_key} {$e->direction} {$e->change} {$e->change_pct}")->all();

    expect($events)->toBe([
        'basep-13:holo:-:- up 9.99 5.00',
        'basep-8:holo:-:- up 3.00 12.00',
        'sv8pt5-5:reverse:-:pokeball up 214398.50 2143985000.00',
        'tk1b-8:normal:-:- down -9.99 -5.00',
    ])
        ->and(t13rCounts($runs['2026-10-11'])['events'])->toBe(4);
});

test('the volume grid equals an independent LAG query for every rule on boundary-heavy prices, extreme and sub_2_previous included', function (): void {
    $root = t13rCopyDays('2026-10-10', '2026-10-11', '2026-10-12');
    t13rPair($root, '2026-10-10', '2026-10-11', 'basep-13', '20.00', '22.00');   // +10 %, +$2: draft, tight, loose
    t13rPair($root, '2026-10-10', '2026-10-11', 'tk1b-8', '1.99', '12.00');      // x6, +$10.01, previous under $2
    t13rPair($root, '2026-10-10', '2026-10-11', 'ex10', '100.00', '103.00');     // +3 %, +$3: tight only
    t13rPair($root, '2026-10-10', '2026-10-11', 'sv8pt5-5', '25.00', '20.00');   // -20 %, -$5: all four, down
    t13rIngest(['2026-10-12', '2026-10-10', '2026-10-11'], $root);

    $oracle = DB::select(<<<'SQL'
        WITH real AS (
            SELECT variant_key, day, close, lag(close) OVER w AS pc, lag(day) OVER w AS pd
            FROM price_daily WHERE NOT is_filled AND source = 'tcgplayer'
            WINDOW w AS (PARTITION BY variant_key, series_epoch ORDER BY day)
        ), c AS (
            SELECT day, close, pc, close - pc AS ch, round((close - pc) / nullif(pc, 0) * 100, 2) AS pct
            FROM real WHERE pc IS NOT NULL AND day - pd <= 14
        ), rules (rule, p, a) AS (VALUES ('draft-v1', 5.00, 1.00), ('tight', 3.00, 0.50), ('loose', 10.00, 2.00), ('coarse', 20.00, 5.00))
        SELECT d.day::text AS day, r.rule,
               count(c.day) AS compared,
               count(*) FILTER (WHERE c.pct >= r.p AND c.ch >= r.a) AS up,
               count(*) FILTER (WHERE c.pct <= -r.p AND c.ch <= -r.a) AS down,
               count(*) FILTER (WHERE c.pc > 0 AND (c.close / c.pc > 5 OR c.close / c.pc < 0.2) AND abs(c.ch) >= 5) AS extreme,
               count(*) FILTER (WHERE c.pc < 2 AND abs(c.pct) >= r.p AND abs(c.ch) >= r.a) AS sub_2_previous
        FROM (SELECT DISTINCT day FROM price_daily WHERE NOT is_filled) d
        CROSS JOIN rules r LEFT JOIN c ON c.day = d.day
        GROUP BY d.day, r.rule ORDER BY d.day, r.rule
        SQL);
    $expected = array_map(fn (object $r): string => "{$r->day} {$r->rule} {$r->compared} {$r->up} {$r->down} {$r->extreme} {$r->sub_2_previous}", $oracle);
    $actual = array_map(
        fn (object $r): string => "{$r->day} {$r->rule} {$r->compared} {$r->up} {$r->down} {$r->extreme} {$r->sub_2_previous}",
        DB::select('SELECT day::text AS day, rule, compared, up, down, extreme, sub_2_previous FROM movement_volume ORDER BY day, rule'),
    );

    expect($expected)->not->toBe([])
        ->and($actual)->toBe($expected)
        // The pinned boundary rows: tk1b-8 is extreme and has a previous close under $2 under every rule.
        ->and(DB::table('movement_volume')->where('day', '2026-10-11')->where('rule', 'tight')->value('sub_2_previous'))->toBe(1)
        ->and(DB::table('movement_volume')->where('day', '2026-10-11')->where('rule', 'coarse')->value('extreme'))->toBe(1);
});

test('movement_volume is the same after a live ascending ingest and after replays in either order, and silent-event days still hold rows (R190b, R190c)', function (): void {
    t13rIngest(T13R_DAYS);
    $live = t13rVolume();

    expect(t13rReplay('asc'))->toBe(0);
    $asc = t13rVolume();
    expect(t13rReplay('desc'))->toBe(0);
    $desc = t13rVolume();

    expect($live)->not->toBe([])
        ->and($asc)->toBe($live)
        ->and($desc)->toBe($live)
        // 10-12 compares variants but fires no event in the ascending run: its volume row is still written.
        ->and(DB::table('price_events')->where('day', '2026-10-12')->count())->toBe(0)
        ->and((int) DB::table('movement_volume')->where('day', '2026-10-12')->where('rule', 'draft-v1')->value('compared'))->toBeGreaterThan(0);
});

test('a finalize that failed after the events insert and is retried writes the clean run\'s events once, counts no backfill, and an old queued day writes none', function (): void {
    t13rIngest(['2026-10-10']);
    $ingest = new DayIngest(DB::connection(), t13rFixture('snapshots'));
    $start = $ingest->start('2026-10-11', 'queued');
    foreach ($start['chunks'] as $chunk) {
        $ingest->chunk($start['run_id'], $chunk);
    }
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'INSERT INTO price_events')) {
            $armed = false;

            throw new RuntimeException('t13r injected failure after the events insert');
        }
    });
    $failed = null;
    try {
        $ingest->finalize($start['run_id']);
    } catch (RuntimeException $e) {
        $failed = $e->getMessage();
    }
    $afterFailure = DB::table('price_events')->count();
    $counts = $ingest->finalize($start['run_id']);

    expect($failed)->toBe('t13r injected failure after the events insert')
        ->and($afterFailure)->toBe(0)
        ->and($counts)->toMatchArray(['events' => 1, 'epoch_breaks' => 0, 'backfill' => 0])
        ->and(t13rContent())->toBe(['price.moved draft-v1 basep-8:holo:-:- basep-8 tcgplayer 2026-10-11 1 up 28.00 25.00 2026-10-10 3.00 12.00 2026-10-11T20:05:45Z 2026-10-10T20:05:42Z null']);

    // An old day through the queued seams, with the newer day finalized: no event, no table wipe to hide one.
    t13rIngest(['2026-10-14']);
    $total = DB::table('price_events')->count();
    $old = $ingest->start('2026-10-10', 'queued', true);
    foreach ($old['chunks'] as $chunk) {
        $ingest->chunk($old['run_id'], $chunk);
    }
    $oldCounts = $ingest->finalize($old['run_id']);

    expect($oldCounts['events'])->toBe(0)
        ->and(DB::table('price_events')->count())->toBe($total);
});

test('a subset replay --fresh leaves no movement_volume row for a day whose candles it just truncated', function (): void {
    t13rIngest(T13R_DAYS);
    expect(t13rReplay('asc', null, ['--days' => '2026-10-11']))->toBe(0);

    $volumeDays = array_map(fn (object $r): string => (string) $r->day, DB::select('SELECT DISTINCT day::text AS day FROM movement_volume ORDER BY 1'));
    $dailyDays = array_map(fn (object $r): string => (string) $r->day, DB::select('SELECT DISTINCT day::text AS day FROM price_daily WHERE NOT is_filled ORDER BY 1'));

    expect($dailyDays)->toBe(['2026-10-11'])
        ->and($volumeDays)->toBe($dailyDays);
});
