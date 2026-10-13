<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// movement_volume and pokeprices:prices:movement-volume (plan P2-i1 T13 items 5 and 6, D4, D6). The volume is
// computed from price_daily, never from price_events, per (day, rule) over the grid draft-v1 (5.00 %, $1.00), tight
// (3.00 %, $0.50), loose (10.00 %, $2.00) and coarse (20.00 %, $5.00). Edited days are copies under a scratch root
// that afterEach removes; the fixture tree itself is only ever read.

const T13V_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const T13V_RULES = ['coarse', 'draft-v1', 'loose', 'tight'];

function t13vFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Ingests $days in order with --sync. */
function t13vIngest(array $days, ?string $snapshots = null): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $snapshots ?? t13vFixture('snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** movement_volume as "day rule compared up down epoch_breaks filled_skipped extreme sub_2_previous", by day and rule. */
function t13vRows(?string $rule = null): array
{
    return array_map(
        fn (object $r): string => "{$r->day} {$r->rule} {$r->compared} {$r->up} {$r->down} {$r->epoch_breaks} {$r->filled_skipped} {$r->extreme} {$r->sub_2_previous}",
        DB::select(<<<'SQL'
            SELECT day::text AS day, rule, compared, up, down, epoch_breaks, filled_skipped, extreme, sub_2_previous
            FROM movement_volume WHERE source = 'tcgplayer' AND (?::text IS NULL OR rule = ?) ORDER BY day, rule
            SQL, [$rule, $rule]),
    );
}

/** "up down" of each day for $rule. */
function t13vMoves(string $rule): array
{
    return DB::table('movement_volume')->where('rule', $rule)->orderBy('day')->get(['day', 'up', 'down'])
        ->mapWithKeys(fn (object $r): array => [substr((string) $r->day, 0, 10) => "{$r->up} {$r->down}"])->all();
}

/**
 * Independently of the implementation: per day, the real closes whose variant has a real close on one of the 14 days
 * before (every fixture series is a single epoch, so this is D4's previous close).
 */
function t13vCompared(): array
{
    $out = [];
    foreach (DB::select(<<<'SQL'
        SELECT c.day::text AS day, count(*) FILTER (WHERE EXISTS (
            SELECT 1 FROM price_daily q WHERE q.variant_key = c.variant_key AND NOT q.is_filled AND q.day < c.day AND q.day >= c.day - 14
        )) AS n
        FROM price_daily c WHERE NOT c.is_filled GROUP BY c.day ORDER BY c.day
        SQL) as $r) {
        $out[$r->day] = (int) $r->n;
    }

    return $out;
}

/** Copies the named fixture days under a fresh scratch root that afterEach removes. */
function t13vCopyDays(string ...$days): string
{
    $root = storage_path('framework/testing/pp-t13v-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t13vRoots($root);
    foreach ($days as $day) {
        $src = t13vFixture('snapshots/'.$day);
        mkdir("{$root}/{$day}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$day}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
    }

    return $root;
}

/** Remembers a scratch root (or a scratch file); without an argument, returns them and forgets them. */
function t13vRoots(?string $add = null): array
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
function t13vSetMarket(string $root, string $day, int $group, int $productId, string $subtype, string $token): void
{
    $path = "{$root}/{$day}/prices/{$group}.json.zst";
    $document = json_decode((string) zstd_uncompress((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    foreach ($document['results'] as $i => $row) {
        if ($row['productId'] === $productId && $row['subTypeName'] === $subtype) {
            $document['results'][$i]['marketPrice'] = '__T13V_TOKEN__';
        }
    }
    file_put_contents($path, zstd_compress(str_replace('"__T13V_TOKEN__"', $token, json_encode($document, JSON_THROW_ON_ERROR))));
}

/** A scratch copy of the five days where 10-11 adds two extreme moves and a mover from under $2 (see the tests). */
function t13vExtremes(): string
{
    $root = t13vCopyDays(...T13V_DAYS);
    // basep-13: 10.00 -> 60.00 (ratio 6, +$50): extreme, up under every rule.
    t13vSetMarket($root, '2026-10-10', 1418, 85001, 'Holofoil', '10.00');
    t13vSetMarket($root, '2026-10-11', 1418, 85001, 'Holofoil', '60.00');
    // ex10-!: 30.00 -> 5.00 (ratio 0.17, -$25): extreme, down under every rule.
    t13vSetMarket($root, '2026-10-10', 1398, 90166, 'Holofoil', '30.00');
    t13vSetMarket($root, '2026-10-11', 1398, 90166, 'Holofoil', '5.00');
    // tk1b-8: 1.50 -> 2.60 (+73.33 %, +$1.10): a draft-v1 and tight mover with a previous close under $2.
    t13vSetMarket($root, '2026-10-10', 1543, 88336, 'Normal', '1.50');
    t13vSetMarket($root, '2026-10-11', 1543, 88336, 'Normal', '2.60');

    return $root;
}

/** Every file under $dir as relative path => sha1. */
function t13vTree(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr((string) $file->getPathname(), strlen($dir))] = sha1_file((string) $file->getPathname());
    }
    ksort($out);

    return $out;
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
    $this->fixtureTree = t13vTree(t13vFixture('snapshots'));
});

afterEach(function (): void {
    expect(t13vTree(t13vFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t13vRoots() as $root) {
        if (is_file($root)) {
            unlink($root);

            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('movement_volume has the planned columns and the primary key (day, source, rule)', function (): void {
    $columns = array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}"
            .($c->data_type === 'numeric' ? "({$c->numeric_precision},{$c->numeric_scale})" : '')
            .':'.($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL'),
        DB::select("SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = 'movement_volume' ORDER BY ordinal_position"),
    );
    $pk = DB::selectOne(<<<'SQL'
        SELECT string_agg(a.attname, ',' ORDER BY k.ord) AS cols
        FROM pg_constraint c CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
        WHERE c.conrelid = 'movement_volume'::regclass AND c.contype = 'p'
        SQL);

    expect($columns)->toBe([
        'day:date:NOT NULL',
        'source:text:NOT NULL',
        'rule:text:NOT NULL',
        'pct:numeric(12,2):NOT NULL',
        'abs:numeric(12,2):NOT NULL',
        'compared:integer:NOT NULL',
        'up:integer:NOT NULL',
        'down:integer:NOT NULL',
        'epoch_breaks:integer:NOT NULL',
        'filled_skipped:integer:NOT NULL',
        'extreme:integer:NOT NULL',
        'sub_2_previous:integer:NOT NULL',
    ])
        ->and($pk->cols)->toBe('day,source,rule');
});

test('the command measures every day for the four rules from price_daily', function (): void {
    t13vIngest(T13V_DAYS);
    DB::table('movement_volume')->delete();
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    $code = Artisan::call('pokeprices:prices:movement-volume');
    $output = Artisan::output();
    $compared = t13vCompared();

    expect($code)->toBe(0, $output)
        ->and(DB::table('movement_volume')->distinct()->orderBy('rule')->pluck('rule')->all())->toBe(T13V_RULES)
        ->and(DB::table('movement_volume')->count())->toBe(20)
        ->and(t13vMoves('draft-v1'))->toBe(['2026-10-09' => '0 0', '2026-10-10' => '0 0', '2026-10-11' => '1 0', '2026-10-12' => '0 0', '2026-10-14' => '1 0'])
        // tight: sv8pt5-5 pokeball's +$1.00 a day (3.33 %, 3.23 %, 3.13 %, 3.03 %), basep-8 on 10-11, and on 10-14
        // base1-8 (+5.93 %), base1-4 shadowless (+3.23 %) and basep-13 (+4.03 %, +$0.50 vs 10-11 across the gap).
        ->and(t13vMoves('tight'))->toBe(['2026-10-09' => '0 0', '2026-10-10' => '1 0', '2026-10-11' => '2 0', '2026-10-12' => '1 0', '2026-10-14' => '4 0'])
        ->and(t13vMoves('loose'))->toBe(['2026-10-09' => '0 0', '2026-10-10' => '0 0', '2026-10-11' => '1 0', '2026-10-12' => '0 0', '2026-10-14' => '0 0'])
        ->and(t13vMoves('coarse'))->toBe(array_fill_keys(T13V_DAYS, '0 0'))
        ->and(DB::table('movement_volume')->orderBy('day')->orderBy('rule')->get(['day', 'compared'])
            ->mapWithKeys(fn (object $r): array => [substr((string) $r->day, 0, 10) => (int) $r->compared])->all())->toBe($compared)
        ->and($compared['2026-10-09'])->toBe(0)
        ->and($compared['2026-10-11'])->toBeGreaterThan(10)
        ->and(DB::table('movement_volume')->where('rule', 'draft-v1')->where('day', '2026-10-12')->value('filled_skipped'))
        ->toBe(DB::table('price_daily')->where('day', '2026-10-12')->where('is_filled', true)->count())
        ->and(DB::table('price_daily')->where('day', '2026-10-12')->where('is_filled', true)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('movement_volume')->sum('epoch_breaks') + DB::table('movement_volume')->sum('extreme') + DB::table('movement_volume')->sum('sub_2_previous'))->toBe(0)
        // From price_daily, never from price_events.
        ->and(array_filter($queries, fn (string $s): bool => str_contains($s, 'price_events')))->toBe([]);
});

test('extreme counts ratio > 5 or < 0.2 with |change| >= $5, and sub_2_previous the movers from under $2.00', function (): void {
    t13vIngest(T13V_DAYS, t13vExtremes());
    $compared = t13vCompared()['2026-10-11'];
    $filled = DB::table('price_daily')->where('day', '2026-10-11')->where('is_filled', true)->count();

    // compared, up, down, epoch_breaks, filled_skipped, extreme, sub_2_previous.
    expect(t13vRows())->toContain(
        "2026-10-11 coarse {$compared} 1 1 0 {$filled} 2 0",
        "2026-10-11 draft-v1 {$compared} 3 1 0 {$filled} 2 1",
        "2026-10-11 loose {$compared} 2 1 0 {$filled} 2 0",
        "2026-10-11 tight {$compared} 4 1 0 {$filled} 2 1",
    )
        // basep-13 back to 12.90 on 10-14 is a ratio of 0.215: a draft-v1 move, not an extreme one.
        ->and(DB::table('movement_volume')->where('day', '<>', '2026-10-11')->sum('extreme'))->toBe(0);
});

test('an epoch change counts epoch_breaks and leaves the variant out of compared', function (): void {
    t13vIngest(['2026-10-10']);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    t13vIngest(['2026-10-11']);

    $row = DB::table('movement_volume')->where('rule', 'draft-v1')->where('day', '2026-10-11')->first();

    expect([(int) $row->up, (int) $row->down, (int) $row->epoch_breaks])->toBe([0, 0, 1])
        ->and((int) $row->compared)->toBe(t13vCompared()['2026-10-11'] - 1);
});

test('finalize fills movement_volume for its window, equal to what the command computes', function (): void {
    t13vIngest(T13V_DAYS, t13vExtremes());
    $filled = t13vRows();
    $code = Artisan::call('pokeprices:prices:movement-volume');

    expect($filled)->toHaveCount(20)
        ->and($code)->toBe(0)
        ->and(t13vRows())->toBe($filled);
});

test('consistency: on days ingested in ascending order, draft-v1 up + down per day equals that day\'s price_events count', function (): void {
    t13vIngest(T13V_DAYS, t13vExtremes());
    Artisan::call('pokeprices:prices:movement-volume');
    $volume = DB::table('movement_volume')->where('rule', 'draft-v1')->orderBy('day')->get(['day', 'up', 'down'])
        ->mapWithKeys(fn (object $r): array => [substr((string) $r->day, 0, 10) => (int) $r->up + (int) $r->down])->all();
    $events = array_fill_keys(T13V_DAYS, 0);
    foreach (DB::select('SELECT day::text AS day, count(*) AS n FROM price_events GROUP BY day') as $r) {
        $events[$r->day] = (int) $r->n;
    }

    // 10-10: basep-13 down, ex10-! and tk1b-8 up (the edited previous closes); 10-11: four; 10-12: ex10-! up and
    // tk1b-8 down; 10-14: base1-8 up and basep-13 down.
    expect($volume)->toBe($events)
        ->and($events)->toBe(['2026-10-09' => 0, '2026-10-10' => 3, '2026-10-11' => 4, '2026-10-12' => 2, '2026-10-14' => 2]);
});

test('--markdown writes the days, the map build and epochs, one table per rule, the means and the pre-measure comparison', function (): void {
    t13vIngest(T13V_DAYS);
    $path = storage_path('framework/testing/pp-t13v-'.bin2hex(random_bytes(6)).'.md');
    t13vRoots($path);

    $code = Artisan::call('pokeprices:prices:movement-volume', ['--markdown' => $path]);
    $output = Artisan::output();
    $md = (string) file_get_contents($path);
    $build = (int) DB::table('map_builds')->max('id');

    expect($code)->toBe(0, $output)
        ->and($md)->toStartWith('# Measured movement volume')
        ->and($md)->toContain('Days measured: 2026-10-09, 2026-10-10, 2026-10-11, 2026-10-12, 2026-10-14')
        ->and($md)->toContain("Map build: {$build}")
        ->and($md)->toContain('series_epoch 1: ')
        ->and(preg_match_all('/^## (draft-v1|tight|loose|coarse) \(/m', $md, $m))->toBe(4)
        ->and($m[1])->toBe(['draft-v1', 'tight', 'loose', 'coarse'])
        ->and($md)->toContain('| day | compared | up | down | epoch_breaks | extreme |')
        ->and($md)->toContain('| 2026-10-11 | '.t13vCompared()['2026-10-11'].' | 1 | 0 | 0 | 0 |')
        // Means over the four days that have a previous close: draft-v1 2 / 4, tight 8 / 4.
        ->and($md)->toContain('| draft-v1 | 5.00 | 1.00 | 0.50 | 0 | 1 |')
        ->and($md)->toContain('| tight | 3.00 | 0.50 | 2.00 | 1 | 4 |')
        ->and($md)->toContain('260, 225 and 216')
        ->and(strtolower($md))->not->toMatch('/\b(live|real-time|realtime)\b/');
});
