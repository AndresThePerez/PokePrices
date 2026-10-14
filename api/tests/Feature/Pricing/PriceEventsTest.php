<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// price_events, the movement outbox (plan P2-i1 T13 items 1-4, D4, D6, R177(4), R180a; review F5). The map is built
// from the P1 fixture day and the synthetic days of tests/Fixtures/tcgcsv-days are ingested; edited days are copies
// under a scratch root that afterEach removes, and the fixture tree itself is only ever read.
//
// On the unedited fixture the draft-v1 crossings are basep-8 on 10-11 (25.00 -> 28.00, +12 %, +$3.00) and base1-8
// 1st Edition shadowless on 10-14 (118.00 on 10-12 -> 125.00, +5.93 %, +$7.00); svp-46 on 10-11 is -6 % but -$0.30.

const T13_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const T13_MARKERS = [
    '2026-10-10' => '2026-10-10T20:05:42Z',
    '2026-10-11' => '2026-10-11T20:05:45Z',
    '2026-10-12' => '2026-10-12T20:05:44Z',
    '2026-10-14' => '2026-10-14T20:05:46Z',
];

function t13Fixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync (live mode `sync`) and returns each day's run id. */
function t13Ingest(array $days, ?string $snapshots = null, bool $force = false): array
{
    $runs = [];
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--force' => $force, '--snapshots' => $snapshots ?? t13Fixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
        $runs[$day] = (int) DB::table('ingest_runs')->max('id');
    }

    return $runs;
}

/** The finalize counts of a run. */
function t13Counts(int $runId): array
{
    return json_decode((string) DB::table('ingest_runs')->where('id', $runId)->value('counts'), true, 512, JSON_THROW_ON_ERROR);
}

/** Empties every price table, the runs and the outbox, so another live ingest starts from a fresh database. */
function t13Reset(): void
{
    foreach (['price_events', 'movement_volume', 'price_movers', 'price_latest', 'price_daily', 'price_points', 'ingest_run_chunks', 'ingest_runs'] as $table) {
        DB::table($table)->delete();
    }
}

/** Every price_events row as text, every column but id and ingest_run_id, in key order. */
function t13Events(): array
{
    return array_map(fn (object $r): string => implode(' ', (array) $r), DB::select(<<<'SQL'
        SELECT type, rule, variant_key, card_id, source, day::text, series_epoch, direction, close::text, previous_close::text,
               previous_day::text, change::text, change_pct::text,
               to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of,
               to_char(previous_as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS previous_as_of,
               coalesce(dispatched_at::text, 'null') AS dispatched_at
        FROM price_events ORDER BY day, variant_key COLLATE "C", series_epoch
        SQL));
}

/** The whole price_events table as text rows, every column included, by id. */
function t13Dump(): array
{
    return array_map(fn (object $r): string => (string) $r->t, DB::select('SELECT e::text AS t FROM price_events e ORDER BY id'));
}

/** Copies the named fixture days under a fresh scratch root that afterEach removes. */
function t13CopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t13-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t13Roots($root);
    foreach ($days as $day) {
        $src = t13Fixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t13Roots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** Sets the marketPrice of ($productId, $subtype) in group $group of a scratch day to the raw JSON number $token. */
function t13SetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T13_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T13_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** Every file under $dir as relative path => sha1. */
function t13Tree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** Calls $hook with the SQL of every statement the default connection runs (a test-only fault seam). */
function t13OnQuery(Closure $hook): void
{
    $busy = false;
    DB::listen(function (QueryExecuted $query) use ($hook, &$busy): void {
        if ($busy || $query->connectionName !== DB::getDefaultConnection()) {
            return;
        }
        $busy = true;
        try {
            $hook($query->sql);
        } finally {
            $busy = false;
        }
    });
}

/** The SQLSTATE and constraint a statement fails with inside a savepoint, or null when it succeeds. */
function t13Violation(Closure $statement): ?array
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        preg_match('/constraint "([^"]+)"/', $e->getMessage(), $m);

        return [$e->getCode(), $m[1] ?? null];
    }

    return null;
}

/** The derived tables a finalize writes: price_daily and price_latest digests plus the movers check. */
function t13Derived(): array
{
    $digest = PriceDigest::compute(DB::connection())['tables'];

    return [$digest['price_daily'], $digest['price_latest'], PriceDigest::movers(DB::connection())];
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
    $this->fixtureTree = t13Tree(t13Fixture('snapshots'));
});

afterEach(function (): void {
    expect(t13Tree(t13Fixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t13Roots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

// Item 1: the table.

test('price_events has exactly the planned columns; money and change_pct are numeric(12,2), dispatched_at the only nullable one', function (): void {
    $columns = array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}"
            .($c->data_type === 'numeric' ? "({$c->numeric_precision},{$c->numeric_scale})" : '')
            .':'.($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL').($c->is_identity === 'YES' ? ':identity' : ''),
        DB::select("SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable, is_identity FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = 'price_events' ORDER BY ordinal_position"),
    );

    expect($columns)->toBe([
        'id:bigint:NOT NULL:identity',
        'type:text:NOT NULL',
        'rule:text:NOT NULL',
        'variant_key:text:NOT NULL',
        'card_id:text:NOT NULL',
        'source:text:NOT NULL',
        'day:date:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'direction:text:NOT NULL',
        'close:numeric(12,2):NOT NULL',
        'previous_close:numeric(12,2):NOT NULL',
        'previous_day:date:NOT NULL',
        'change:numeric(12,2):NOT NULL',
        'change_pct:numeric(12,2):NOT NULL',
        'as_of:timestamp with time zone:NOT NULL',
        'previous_as_of:timestamp with time zone:NOT NULL',
        'ingest_run_id:bigint:NOT NULL',
        'dispatched_at:timestamp with time zone:NULL',
    ])
        // P5-i1 T01 added the deliveries table (same-commit edit, R233); nothing in it references price_events.
        ->and(DB::selectOne("SELECT to_regclass('deliveries') AS t")->t)->not->toBeNull()
        ->and(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'deliveries'::regclass AND (confrelid = 'price_events'::regclass OR pg_get_constraintdef(oid) LIKE '%price_events%')"))->toBe([]);
});

test('each price_events key and CHECK rejects a violating row', function (array $overrides, string $state, string $constraint): void {
    $row = ['type' => 'price.moved', 'rule' => 'draft-v1', 'variant_key' => 'basep-8:holo:-:-', 'card_id' => 'basep-8', 'source' => 'tcgplayer',
        'day' => '2026-10-11', 'series_epoch' => 1, 'direction' => 'up', 'close' => '28.00', 'previous_close' => '25.00',
        'previous_day' => '2026-10-10', 'change' => '3.00', 'change_pct' => '12.00', 'as_of' => '2026-10-11 20:05:45+00',
        'previous_as_of' => '2026-10-10 20:05:42+00', 'ingest_run_id' => 1];
    DB::table('price_events')->insert($row);

    expect(t13Violation(fn () => DB::table('price_events')->insert(array_merge($row, $overrides))))->toBe([$state, $constraint]);
})->with([
    'the same outbox key twice' => [['ingest_run_id' => 2], '23505', 'price_events_variant_key_source_day_series_epoch_unique'],
    'another type' => [['type' => 'price.dropped', 'day' => '2026-10-12'], '23514', 'price_events_type_check'],
    'another source' => [['source' => 'tcgcsv'], '23514', 'price_events_source_check'],
    'another direction' => [['direction' => 'flat', 'day' => '2026-10-12', 'change' => '-3.00', 'change_pct' => '-12.00'], '23514', 'price_events_direction_check'],
    'up with a fall' => [['day' => '2026-10-12', 'change' => '-3.00', 'change_pct' => '-12.00'], '23514', 'price_events_change_direction_check'],
    'unknown variant' => [['variant_key' => 'basep-8:holo:-:nope'], '23503', 'price_events_variant_key_foreign'],
]);

// Item 2: DetectMovement in a live finalize.

test('a live ascending sync ingest writes one draft-v1 event for basep-8 on 10-11, with the D4 previous close, and none for svp-46', function (): void {
    $runs = t13Ingest(['2026-10-10', '2026-10-11']);
    $event = (array) DB::selectOne('SELECT * FROM price_events');

    expect(DB::table('price_events')->count())->toBe(1)
        ->and(t13Events())->toBe([
            'price.moved draft-v1 basep-8:holo:-:- basep-8 tcgplayer 2026-10-11 1 up 28.00 25.00 2026-10-10 3.00 12.00 2026-10-11T20:05:45Z 2026-10-10T20:05:42Z null',
        ])
        ->and($event['ingest_run_id'])->toBe($runs['2026-10-11'])
        ->and($event['dispatched_at'])->toBeNull()
        // -6 % but only -$0.30: below the $1 floor.
        ->and(DB::table('price_events')->where('variant_key', 'svp-46:holo:-:-')->count())->toBe(0)
        ->and(t13Counts($runs['2026-10-11']))->toMatchArray(['events' => 1, 'epoch_breaks' => 0, 'backfill' => 0])
        ->and(t13Counts($runs['2026-10-10']))->toMatchArray(['events' => 0, 'epoch_breaks' => 0, 'backfill' => 0]);
});

test('the queued seams (mode queued) write the event in their finalize too', function (): void {
    t13Ingest(['2026-10-10']);
    $ingest = new DayIngest(DB::connection(), t13Fixture('snapshots'));
    $start = $ingest->start('2026-10-11', 'queued');
    foreach ($start['chunks'] as $chunk) {
        $ingest->chunk($start['run_id'], $chunk);
    }
    $counts = $ingest->finalize($start['run_id']);

    expect($counts)->toMatchArray(['events' => 1, 'epoch_breaks' => 0, 'backfill' => 0])
        ->and(DB::table('price_events')->pluck('ingest_run_id')->all())->toBe([$start['run_id']])
        ->and(DB::table('price_events')->value('variant_key'))->toBe('basep-8:holo:-:-');
});

// Item 4: the rule.

test('the thresholds are inclusive: +5.00 % and +$1.00 and -5.00 % and -$1.00 fire, 4.99 % and a $0.99 change do not', function (): void {
    $root = t13CopyDays('2026-10-10', '2026-10-11');
    // basep-13: 20.00 -> 21.00 (+5.00 %, +$1.00).
    t13SetMarket($root, '2026-10-10', 1418, 85001, 'Holofoil', '20.00');
    t13SetMarket($root, '2026-10-11', 1418, 85001, 'Holofoil', '21.00');
    // tk1b-8: 20.00 -> 19.00 (-5.00 %, -$1.00).
    t13SetMarket($root, '2026-10-10', 1543, 88336, 'Normal', '20.00');
    t13SetMarket($root, '2026-10-11', 1543, 88336, 'Normal', '19.00');
    // ex10-!: 100.20 -> 105.20 (+$5.00 but +4.99 %).
    t13SetMarket($root, '2026-10-10', 1398, 90166, 'Holofoil', '100.20');
    t13SetMarket($root, '2026-10-11', 1398, 90166, 'Holofoil', '105.20');
    // sv8pt5-5 pokeball: 19.80 -> 20.79 (+5.00 % but +$0.99).
    t13SetMarket($root, '2026-10-10', 23821, 610540, 'Holofoil', '19.80');
    t13SetMarket($root, '2026-10-11', 23821, 610540, 'Holofoil', '20.79');
    $runs = t13Ingest(['2026-10-10', '2026-10-11'], $root);

    expect(DB::table('price_events')->orderBy('variant_key')->get(['variant_key', 'direction', DB::raw('change::text AS change'), DB::raw('change_pct::text AS change_pct')])
        ->map(fn (object $e): string => "{$e->variant_key} {$e->direction} {$e->change} {$e->change_pct}")->all())->toBe([
            'basep-13:holo:-:- up 1.00 5.00',
            'basep-8:holo:-:- up 3.00 12.00',
            'tk1b-8:normal:-:- down -1.00 -5.00',
        ])
        ->and(t13Counts($runs['2026-10-11'])['events'])->toBe(3);
});

test('an epoch change on the day does not fire and counts epoch_breaks', function (): void {
    $runs = t13Ingest(['2026-10-10']);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    $runs += t13Ingest(['2026-10-11']);

    expect(DB::table('price_events')->count())->toBe(0)
        ->and(t13Counts($runs['2026-10-11']))->toMatchArray(['events' => 0, 'epoch_breaks' => 1, 'backfill' => 0]);
});

test('a filled previous day falls back to the earlier real close (D4)', function (): void {
    // basep-13 has no row on 10-12 (filled from 10-11 at 12.40); 10-14 is set to 14.00: +12.90 %, +$1.60 vs 10-11.
    $root = t13CopyDays('2026-10-11', '2026-10-12', '2026-10-14');
    t13SetMarket($root, '2026-10-14', 1418, 85001, 'Holofoil', '14.00');
    t13Ingest(['2026-10-11', '2026-10-12', '2026-10-14'], $root);

    expect(DB::table('price_daily')->where('variant_key', 'basep-13:holo:-:-')->where('day', '2026-10-12')->value('is_filled'))->toBeTrue()
        ->and(array_values(array_filter(t13Events(), fn (string $e): bool => str_contains($e, ' basep-13:holo:-:- '))))->toBe([
            'price.moved draft-v1 basep-13:holo:-:- basep-13 tcgplayer 2026-10-14 1 up 14.00 12.40 2026-10-11 1.60 12.90 2026-10-14T20:05:46Z 2026-10-11T20:05:45Z null',
        ]);
});

test('a late older day fires nothing and counts its crossings as backfill', function (): void {
    $runs = t13Ingest(['2026-10-10', '2026-10-12', '2026-10-14', '2026-10-11']);

    // 10-12 was the newest when it came, so its crossings against 10-10 fired then; 10-11 came last and fires nothing.
    expect(t13Events())->toBe([
        'price.moved draft-v1 basep-8:holo:-:- basep-8 tcgplayer 2026-10-12 1 up 28.00 25.00 2026-10-10 3.00 12.00 2026-10-12T20:05:44Z 2026-10-10T20:05:42Z null',
        'price.moved draft-v1 sv8pt5-5:reverse:-:pokeball sv8pt5-5 tcgplayer 2026-10-12 1 up 33.00 31.00 2026-10-10 2.00 6.45 2026-10-12T20:05:44Z 2026-10-10T20:05:42Z null',
        'price.moved draft-v1 base1-8:holo:1st:shadowless base1-8 tcgplayer 2026-10-14 1 up 125.00 118.00 2026-10-12 7.00 5.93 2026-10-14T20:05:46Z 2026-10-12T20:05:44Z null',
    ])
        ->and(DB::table('price_events')->where('day', '2026-10-11')->count())->toBe(0)
        // The late day changed 10-12's previous close, but an event is never updated or deleted.
        ->and(DB::table('price_daily')->where('variant_key', 'basep-8:holo:-:-')->where('day', '2026-10-11')->value('is_filled'))->toBeFalse()
        // basep-8's +12 % on 10-11 arrived after 10-14 was finalized: counted, never written.
        ->and(t13Counts($runs['2026-10-11']))->toMatchArray(['events' => 0, 'backfill' => 1])
        ->and(t13Counts($runs['2026-10-14']))->toMatchArray(['events' => 1, 'backfill' => 0]);
});

test('a live ascending ingest of the five days, twice on fresh databases, gives byte-equal price_events rows (review F5)', function (): void {
    $first = [t13Ingest(T13_DAYS), t13Events()];
    t13Reset();
    $second = [t13Ingest(T13_DAYS), t13Events()];

    expect($first[1])->toBe([
        'price.moved draft-v1 basep-8:holo:-:- basep-8 tcgplayer 2026-10-11 1 up 28.00 25.00 2026-10-10 3.00 12.00 2026-10-11T20:05:45Z 2026-10-10T20:05:42Z null',
        'price.moved draft-v1 base1-8:holo:1st:shadowless base1-8 tcgplayer 2026-10-14 1 up 125.00 118.00 2026-10-12 7.00 5.93 2026-10-14T20:05:46Z 2026-10-12T20:05:44Z null',
    ])
        ->and($second[1])->toBe($first[1])
        // Every day was the newest when finalized: nothing is backfill.
        ->and(array_map(fn (int $run): int => t13Counts($run)['backfill'], $second[0]))->toBe(array_fill_keys(T13_DAYS, 0));
});

test('a live descending ingest writes events only for the newest day; every older day counts backfill', function (): void {
    $runs = t13Ingest(array_reverse(T13_DAYS));
    $backfill = array_map(fn (int $run): int => t13Counts($run)['backfill'], $runs);

    // 10-14 came first, before any previous close existed, so the outbox stays empty: no older day ever fires.
    expect(DB::table('price_events')->where('day', '<>', '2026-10-14')->count())->toBe(0)
        ->and(DB::table('price_events')->count())->toBe(0)
        ->and(t13Counts($runs['2026-10-14']))->toMatchArray(['events' => 0, 'backfill' => 0])
        // 10-12 gives 10-14's base1-8 its previous close; 10-10 gives 10-11's basep-8 one, while 10-14's stays unwritten.
        ->and($backfill)->toBe(['2026-10-14' => 0, '2026-10-12' => 1, '2026-10-11' => 1, '2026-10-10' => 2, '2026-10-09' => 2])
        ->and(array_sum(array_map(fn (int $run): int => t13Counts($run)['events'], $runs)))->toBe(0);
});

// Item 3: atomicity.

test('a forced exception after the events insert rolls back that finalize\'s price_daily, price_latest and price_movers changes too', function (): void {
    t13Ingest(['2026-10-10']);
    $ingest = new DayIngest(DB::connection(), t13Fixture('snapshots'));
    $start = $ingest->start('2026-10-11', 'queued');
    foreach ($start['chunks'] as $chunk) {
        $ingest->chunk($start['run_id'], $chunk);
    }
    $before = t13Derived();
    $inserted = false;
    t13OnQuery(function (string $sql) use (&$inserted): void {
        if (str_starts_with(ltrim($sql), 'WITH') && str_contains($sql, 'INSERT INTO price_events')) {
            $inserted = true;

            throw new RuntimeException('t13 injected failure after the events insert');
        }
    });

    $error = null;
    try {
        $ingest->finalize($start['run_id']);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect($error)->toBe('t13 injected failure after the events insert')
        ->and($inserted)->toBeTrue()
        ->and(DB::table('price_events')->count())->toBe(0)
        ->and(t13Derived())->toBe($before)
        ->and(DB::table('ingest_runs')->where('id', $start['run_id'])->value('status'))->toBe('running');
});

test('a forced exception in the rollup writes no event', function (): void {
    t13Ingest(['2026-10-10']);
    $seen = [];
    t13OnQuery(function (string $sql) use (&$seen): void {
        $seen[] = $sql;
        if (str_contains($sql, 'INSERT INTO price_daily')) {
            throw new RuntimeException('t13 injected rollup failure');
        }
    });

    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => '2026-10-11', '--sync' => true, '--snapshots' => t13Fixture('snapshots')]);
    $ingestStatements = $seen;

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('t13 injected rollup failure')
        ->and(array_filter($ingestStatements, fn (string $s): bool => str_contains($s, 'price_events')))->toBe([])
        ->and(DB::table('price_events')->count())->toBe(0)
        ->and(DB::table('ingest_runs')->orderByDesc('id')->value('status'))->toBe('failed');
});

test('re-finalizing the same day inserts no duplicate and leaves the event untouched', function (): void {
    t13Ingest(['2026-10-10', '2026-10-11']);
    $before = t13Dump();
    $again = t13Ingest(['2026-10-11'], null, true);

    expect($before)->toHaveCount(1)
        ->and(t13Dump())->toBe($before)
        ->and(t13Counts($again['2026-10-11']))->toMatchArray(['events' => 0, 'backfill' => 0]);
});

// Item 4 and R180a: replay and the outbox.

test('replay never runs DetectMovement and leaves a pre-seeded price_events table byte-unchanged, after --fresh in both orders', function (): void {
    t13Ingest(['2026-10-10', '2026-10-11']);
    DB::table('price_events')->insert(['type' => 'price.moved', 'rule' => 'draft-v1', 'variant_key' => 'svp-46:holo:-:-', 'card_id' => 'svp-46',
        'source' => 'tcgplayer', 'day' => '2026-09-30', 'series_epoch' => 1, 'direction' => 'down', 'close' => '4.00', 'previous_close' => '5.50',
        'previous_day' => '2026-09-29', 'change' => '-1.50', 'change_pct' => '-27.27', 'as_of' => '2026-09-30 20:05:40+00',
        'previous_as_of' => '2026-09-29 20:05:40+00', 'ingest_run_id' => 999999]);
    $before = t13Dump();
    $touched = [];
    t13OnQuery(function (string $sql) use (&$touched): void {
        if (str_contains($sql, 'price_events')) {
            $touched[] = $sql;
        }
    });

    $asc = Artisan::call('pokeprices:prices:replay', ['--snapshots' => t13Fixture('snapshots'), '--fresh' => true, '--order' => 'asc']);
    $desc = Artisan::call('pokeprices:prices:replay', ['--snapshots' => t13Fixture('snapshots'), '--fresh' => true, '--order' => 'desc']);
    $replayStatements = $touched;

    expect([$asc, $desc])->toBe([0, 0])
        ->and($before)->toHaveCount(2)
        // 10-14's base1-8 crossing would be a new row if a replay ran DetectMovement.
        ->and(t13Dump())->toBe($before)
        // The only statements naming price_events are the dispatched guard's reads, once per replay.
        ->and(array_map(fn (string $s): string => preg_replace('/\s+/', ' ', $s), $replayStatements))->toBe([
            "SELECT to_regclass('price_events') IS NOT NULL AS present",
            'SELECT EXISTS (SELECT 1 FROM price_events WHERE dispatched_at IS NOT NULL) AS any',
            "SELECT to_regclass('price_events') IS NOT NULL AS present",
            'SELECT EXISTS (SELECT 1 FROM price_events WHERE dispatched_at IS NOT NULL) AS any',
        ])
        ->and(DB::table('ingest_runs')->where('mode', 'replay')->whereNotNull('counts')->get(['counts'])
            ->filter(fn (object $r): bool => array_key_exists('events', json_decode((string) $r->counts, true)))->count())->toBe(0);
});

test('events are only ever inserted: no ingest statement updates or deletes price_events', function (): void {
    $statements = [];
    t13OnQuery(function (string $sql) use (&$statements): void {
        if (str_contains($sql, 'price_events')) {
            $statements[] = $sql;
        }
    });
    t13Ingest(T13_DAYS);
    t13Ingest(['2026-10-11', '2026-10-14'], null, true);

    expect($statements)->not->toBe([])
        ->and(array_filter($statements, fn (string $s): bool => preg_match('/\b(UPDATE|DELETE|TRUNCATE)\b[^;]*price_events|price_events[^;]*\bDO UPDATE\b/i', $s) === 1))->toBe([])
        ->and(DB::table('price_events')->count())->toBe(2);
});
