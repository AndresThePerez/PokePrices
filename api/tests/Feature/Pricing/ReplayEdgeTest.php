<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\PriceDigest;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Reviewer edge cases for P2-i1-T07: the 14-day cap boundary and re-anchoring, a subset replay after a full ingest, a
// marker or folder changing under a running replay, lock coverage for the whole run, the dispatched-events guard
// ordering, the partitioned truncate, an unequal pass 2, and the is_filled discipline of price_latest.

const T07E_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function t07eFixture(string $path = ''): string
{
    return base_path('tests/Fixtures/tcgcsv-days'.($path === '' ? '' : '/'.$path));
}

/** Remembers a scratch root; without an argument, returns the remembered roots and forgets them. */
function t07eRoots(?string $add = null): array
{
    static $roots = [];
    if ($add !== null) {
        $roots[] = $add;

        return $roots;
    }
    [$out, $roots] = [$roots, []];

    return $out;
}

/** A fresh scratch root holding copies of fixture days: a list of [from, to] pairs, the copy relabelled to `to`. */
function t07eRoot(array $days): string
{
    $root = storage_path('framework/testing/pp-t07e-'.bin2hex(random_bytes(6)));
    mkdir($root, 0775, true);
    t07eRoots($root);
    foreach ($days as [$from, $to]) {
        $src = t07eFixture('snapshots/'.$from);
        mkdir("{$root}/{$to}", 0775, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $dest = "{$root}/{$to}/".substr((string) $file->getPathname(), strlen($src) + 1);
            $file->isDir() ? mkdir($dest, 0775, true) : copy((string) $file->getPathname(), $dest);
        }
        if ($to !== $from) {
            foreach (['marker.txt', 'manifest.json'] as $name) {
                file_put_contents("{$root}/{$to}/{$name}", str_replace($from, $to, (string) file_get_contents("{$root}/{$to}/{$name}")));
            }
        }
    }

    return $root;
}

/** Runs the sync ingest of each day in order; returns the exit codes by day. */
function t07eIngest(array $days, string $root): array
{
    $codes = [];
    foreach ($days as $day) {
        $codes[$day] = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => $root]);
    }

    return $codes;
}

/** Runs the replay command; returns [exit code, output, decoded JSON or null]. */
function t07eReplay(string $root, array $options = []): array
{
    $code = Artisan::call('pokeprices:prices:replay', ['--snapshots' => $root, '--fresh' => true, ...$options]);
    $output = Artisan::output();
    $start = strpos($output, '{');
    $json = $start === false ? null : json_decode(substr($output, $start), true);

    return [$code, $output, is_array($json) ? $json : null];
}

function t07eWipe(): void
{
    DB::statement('TRUNCATE price_points, price_daily, price_latest');
    DB::table('ingest_run_chunks')->delete();
    DB::table('ingest_runs')->delete();
}

/** The price_daily rows of a variant as day => [close, filled_from or null], text form. */
function t07eSeries(string $variant): array
{
    $out = [];
    foreach (DB::select('SELECT day::text AS day, close::text AS close, filled_from::text AS filled_from FROM price_daily WHERE variant_key = ? ORDER BY day', [$variant]) as $r) {
        $out[$r->day] = [$r->close, $r->filled_from];
    }

    return $out;
}

function t07eEx10(): string
{
    return (string) DB::table('source_product_map')->where('source', 'tcgplayer')
        ->where('source_product_id', 90167)->where('source_subtype', 'Holofoil')->value('variant_key');
}

/** Calls $hook with the SQL of every query on the default connection; the hook is not re-entered by its own queries. */
function t07eOnQuery(Closure $hook): void
{
    $busy = false;
    DB::listen(function (QueryExecuted $query) use ($hook, &$busy): void {
        if ($busy || $query->connectionName === 't07e_other') {
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

function t07eTree(string $dir): array
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
    $this->fixtureTree = t07eTree(t07eFixture('snapshots'));
});

afterEach(function (): void {
    expect(t07eTree(t07eFixture('snapshots')))->toBe($this->fixtureTree);
    foreach (t07eRoots() as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir((string) $file->getPathname()) : unlink((string) $file->getPathname());
        }
        rmdir($root);
    }
});

test('a variant whose last real close is exactly 14 days before the newest day is filled through that day (cap boundary, both orders)', function (): void {
    // 10-23 is 14 days after 10-09 and carries no copy of the 10-09-only variant: the 14th fill is the newest day.
    $root = t07eRoot([['2026-10-09', '2026-10-09'], ['2026-10-14', '2026-10-23']]);
    $ex10 = t07eEx10();

    expect(t07eIngest(['2026-10-09', '2026-10-23'], $root))->toBe(['2026-10-09' => 0, '2026-10-23' => 0]);
    $ascending = t07eSeries($ex10);
    $digest = PriceDigest::compute(DB::connection())['tables'];
    t07eWipe();
    expect(t07eIngest(['2026-10-23', '2026-10-09'], $root))->toBe(['2026-10-23' => 0, '2026-10-09' => 0]);

    expect(array_keys($ascending))->toHaveCount(15)
        ->and($ascending['2026-10-23'])->toBe(['15.55', '2026-10-09'])
        ->and(t07eSeries($ex10))->toBe($ascending)
        ->and(PriceDigest::compute(DB::connection())['tables'])->toBe($digest);
});

test('a real close landing mid-chain by a late ingest re-anchors the fills after it and leaves the earlier fills alone', function (): void {
    // 10-15 and 10-24 are copies of 10-09, so the 10-09-only variant is real on 09, 15 and 24.
    $root = t07eRoot([['2026-10-09', '2026-10-09'], ['2026-10-09', '2026-10-15'], ['2026-10-09', '2026-10-24']]);
    $ex10 = t07eEx10();

    expect(t07eIngest(['2026-10-09', '2026-10-15', '2026-10-24'], $root))->toBe(['2026-10-09' => 0, '2026-10-15' => 0, '2026-10-24' => 0]);
    $ascending = t07eSeries($ex10);
    $digest = PriceDigest::compute(DB::connection())['tables'];

    t07eWipe();
    // 24 first: 10-09 fills reach 10-23. Then 15 lands mid-chain and must take over 16..23.
    expect(t07eIngest(['2026-10-09', '2026-10-24', '2026-10-15'], $root))->toBe(['2026-10-09' => 0, '2026-10-24' => 0, '2026-10-15' => 0]);
    $late = t07eSeries($ex10);

    expect($ascending['2026-10-14'])->toBe(['15.55', '2026-10-09'])
        ->and($ascending['2026-10-15'])->toBe(['15.55', null])
        ->and($ascending['2026-10-16'])->toBe(['15.55', '2026-10-15'])
        ->and($ascending['2026-10-23'])->toBe(['15.55', '2026-10-15'])
        ->and($ascending['2026-10-24'])->toBe(['15.55', null])
        ->and($late)->toBe($ascending)
        ->and(PriceDigest::compute(DB::connection())['tables'])->toBe($digest);
});

test('a subset replay after a full ingest leaves exactly the state of ingesting only those days, with the other days superseded', function (): void {
    $root = t07eFixture('snapshots');
    expect(t07eIngest(T07E_DAYS, $root))->toBe(array_fill_keys(T07E_DAYS, 0));
    $others = DB::table('ingest_runs')->whereIn('day', ['2026-10-10', '2026-10-12', '2026-10-14'])->pluck('id')->all();

    [$code, $output, $doc] = t07eReplay($root, ['--days' => '2026-10-11,2026-10-09', '--order' => 'desc']);
    $replayed = PriceDigest::compute(DB::connection())['tables'];
    $maxDaily = DB::selectOne('SELECT max(day)::text AS d, min(day)::text AS lo FROM price_daily');
    $latestDays = DB::table('price_latest')->selectRaw('max(day)::text AS d')->value('d');
    $statuses = DB::table('ingest_runs')->whereIn('id', $others)->pluck('status')->unique()->all();

    t07eWipe();
    expect(t07eIngest(['2026-10-09', '2026-10-11'], $root))->toBe(['2026-10-09' => 0, '2026-10-11' => 0]);

    expect($code)->toBe(0, $output)
        ->and($doc['days'])->toBe(['2026-10-09', '2026-10-11'])
        ->and($doc['identical'])->toBeTrue()
        // No stale row of an un-replayed day (10-12, 10-14, or a fill carried from them).
        ->and($maxDaily->d)->toBe('2026-10-11')
        ->and($latestDays)->toBe('2026-10-11')
        ->and($statuses)->toBe(['superseded'])
        ->and($replayed)->toBe(PriceDigest::compute(DB::connection())['tables']);
});

test('a marker that changes after preflight fails the replay loudly instead of silently ingesting the new marker', function (): void {
    $root = t07eRoot(array_map(fn (string $d): array => [$d, $d], T07E_DAYS));
    $wiped = 0;
    t07eOnQuery(function (string $sql) use ($root, &$wiped): void {
        if (str_starts_with($sql, 'TRUNCATE') && ++$wiped === 1) {
            foreach (['marker.txt', 'manifest.json'] as $name) {
                $path = "{$root}/2026-10-12/{$name}";
                file_put_contents($path, str_replace('20:05:44', '20:09:59', (string) file_get_contents($path)));
            }
        }
    });

    [$code, $output, $doc] = t07eReplay($root);

    expect($code)->toBe(1)
        ->and($doc)->toBeNull()
        ->and($output)->toContain('2026-10-12')
        ->and($output)->toContain('marker changed');
});

test('a day folder removed after preflight fails the replay with exit 1 and releases the lock', function (): void {
    $root = t07eRoot(array_map(fn (string $d): array => [$d, $d], T07E_DAYS));
    $wiped = 0;
    t07eOnQuery(function (string $sql) use ($root, &$wiped): void {
        if (str_starts_with($sql, 'TRUNCATE') && ++$wiped === 1) {
            unlink("{$root}/2026-10-14/SUCCESS");
        }
    });

    [$code, $output, $doc] = t07eReplay($root);

    config(['database.connections.t07e_other' => config('database.connections.pgsql')]);
    $free = DB::connection('t07e_other')->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [IngestLock::KEY])->ok;
    DB::connection('t07e_other')->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t07e_other');

    expect($code)->toBe(1)
        ->and($doc)->toBeNull()
        ->and($output)->toContain('2026-10-14')
        ->and($free)->toBeTrue();
});

test('the pricing:ingest lock stays held against another connection for the whole run, across every day and pass', function (): void {
    config(['database.connections.t07e_other' => config('database.connections.pgsql')]);
    $other = DB::connection('t07e_other');
    $samples = ['held' => 0, 'taken' => 0, 'ingest' => []];
    t07eOnQuery(function (string $sql) use ($other, &$samples): void {
        if (! str_starts_with($sql, 'TRUNCATE') && ! str_contains($sql, 'ingest_runs')) {
            return;
        }
        if ($other->selectOne('SELECT pg_try_advisory_lock(?) AS ok', [IngestLock::KEY])->ok === true) {
            $samples['taken']++;
            $other->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        } else {
            $samples['held']++;
        }
        if ($samples['held'] % 7 === 1) {
            $samples['ingest'][] = (new DayIngest($other, t07eFixture('snapshots')))->sync('2026-10-11', true)['status'];
        }
    });

    [$code, $output] = t07eReplay(t07eFixture('snapshots'));
    $other->select('SELECT pg_advisory_unlock_all()');
    DB::purge('t07e_other');

    expect($code)->toBe(0, $output)
        ->and($samples['taken'])->toBe(0)
        ->and($samples['held'])->toBeGreaterThan(30)
        ->and(array_unique($samples['ingest']))->toBe(['locked']);
});

test('a dispatched price_events row refuses before the first destructive statement, even beside undispatched ones', function (): void {
    DB::statement('CREATE TABLE price_events (id bigint PRIMARY KEY, dispatched_at timestamptz NULL)');
    DB::table('price_events')->insert([
        ['id' => 1, 'dispatched_at' => null], ['id' => 2, 'dispatched_at' => null],
        ['id' => 3, 'dispatched_at' => '2026-10-12 00:00:00+00'], ['id' => 4, 'dispatched_at' => null],
    ]);
    expect(t07eIngest(['2026-10-11'], t07eFixture('snapshots')))->toBe(['2026-10-11' => 0]);
    $statements = [];
    t07eOnQuery(function (string $sql) use (&$statements): void {
        $statements[] = $sql;
    });
    $before = PriceDigest::compute(DB::connection());

    [$code, $output] = t07eReplay(t07eFixture('snapshots'));

    $destructive = array_values(array_filter($statements, fn (string $s): bool => preg_match('/^\s*(truncate|delete|update|insert)/i', $s) === 1));
    expect($code)->toBe(1)
        ->and($output)->toContain('dispatched')
        ->and($destructive)->toBe([])
        ->and(PriceDigest::compute(DB::connection()))->toBe($before)
        ->and(DB::table('price_events')->count())->toBe(4);
});

test('the truncate empties every month partition and a replay creates the partition a day in a new month needs', function (): void {
    // A stray November day in its own partition, then a replay of December and October days from a different root.
    $nov = t07eRoot([['2026-10-14', '2026-11-03']]);
    expect(t07eIngest(['2026-11-03'], $nov))->toBe(['2026-11-03' => 0]);
    expect(DB::table('price_points')->whereRaw("day = '2026-11-03'")->count())->toBeGreaterThan(0);

    $root = t07eRoot([['2026-10-11', '2026-10-11'], ['2026-10-12', '2026-12-01']]);
    expect(DB::selectOne("SELECT to_regclass('price_points_y2026m12') AS t")->t)->toBeNull();

    [$code, $output, $doc] = t07eReplay($root);

    expect($code)->toBe(0, $output)
        ->and($doc['identical'])->toBeTrue()
        ->and(DB::selectOne("SELECT to_regclass('price_points_y2026m12') AS t")->t)->not->toBeNull()
        ->and(DB::table('price_points')->whereRaw("day = '2026-11-03'")->count())->toBe(0)
        ->and(DB::selectOne('SELECT count(*) AS n FROM price_points_y2026m11')->n)->toBe(0)
        ->and(DB::table('price_points')->selectRaw('count(DISTINCT day) AS n')->value('n'))->toBe(2);
});

test('a pass 2 that differs from pass 1 prints identical false and exits 1', function (): void {
    $wipes = 0;
    $stray = false;
    t07eOnQuery(function (string $sql) use (&$wipes, &$stray): void {
        if (str_starts_with($sql, 'TRUNCATE') && ++$wipes === 2 && ! $stray) {
            $stray = true;
            DB::table('price_daily')->insert([
                'variant_key' => 'base1-4:holo:unlimited:-', 'source' => 'tcgplayer', 'day' => '2030-01-01', 'series_epoch' => 1,
                'open' => '1.00', 'high' => '1.00', 'low' => '1.00', 'close' => '1.00', 'n_obs' => 1, 'is_filled' => false,
                'filled_from' => null, 'as_of' => '2030-01-01 00:00:00+00',
            ]);
        }
    });

    [$code, $output, $doc] = t07eReplay(t07eFixture('snapshots'), ['--code-sha' => 'abcdef0']);

    expect($code)->toBe(1)
        ->and($doc)->not->toBeNull()
        ->and($doc['identical'])->toBeFalse()
        ->and($doc['passes'][0]['digests']['price_daily']['sha256'])->not->toBe($doc['passes'][1]['digests']['price_daily']['sha256'])
        ->and($doc['passes'][1]['digests']['price_daily']['rows'])->toBe($doc['passes'][0]['digests']['price_daily']['rows'] + 1)
        ->and($doc['code_sha'])->toBe('abcdef0')
        ->and($doc['map_build_id'])->toBeInt();
});

test('after a full replay no filled row is a price_latest row or its previous close, and every fill repeats its anchor exactly', function (): void {
    [$code, $output] = t07eReplay(t07eFixture('snapshots'), ['--order' => 'desc']);

    // basep-13 is real on 10-11 (12.40) and again on 10-14 (12.90); 10-12 and 10-13 are fills of 12.40.
    $basep = (array) DB::selectOne("SELECT close::text AS close, day::text AS day, previous_close::text AS previous_close, previous_day::text AS previous_day FROM price_latest WHERE variant_key = 'basep-13:holo:-:-'");
    $filledInLatest = DB::selectOne('SELECT count(*) AS n FROM price_latest l JOIN price_daily d ON d.variant_key = l.variant_key AND d.day = l.day WHERE d.is_filled')->n;
    $filledAsPrevious = DB::selectOne('SELECT count(*) AS n FROM price_latest l JOIN price_daily d ON d.variant_key = l.variant_key AND d.day = l.previous_day WHERE d.is_filled')->n;
    $badFill = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n FROM price_daily f
        LEFT JOIN price_daily r ON r.variant_key = f.variant_key AND r.day = f.filled_from AND NOT r.is_filled
        WHERE f.is_filled AND (r.variant_key IS NULL OR f.close <> r.close OR f.open <> r.close OR f.high <> r.close OR f.low <> r.close
            OR f.n_obs <> 0 OR f.series_epoch <> r.series_epoch OR f.as_of <> r.as_of)
        SQL)->n;
    $realWithFill = DB::selectOne('SELECT count(*) AS n FROM price_daily WHERE NOT is_filled AND (filled_from IS NOT NULL OR n_obs = 0)')->n;
    // A fill is the unbroken continuation of its anchor: no real row of the variant lies between anchor and fill.
    $broken = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n FROM price_daily f
        JOIN price_daily r ON r.variant_key = f.variant_key AND NOT r.is_filled AND r.day > f.filled_from AND r.day < f.day
        WHERE f.is_filled
        SQL)->n;

    expect($code)->toBe(0, $output)
        ->and($basep)->toBe(['close' => '12.90', 'day' => '2026-10-14', 'previous_close' => '12.40', 'previous_day' => '2026-10-11'])
        ->and($filledInLatest)->toBe(0)
        ->and($filledAsPrevious)->toBe(0)
        ->and($badFill)->toBe(0)
        ->and($realWithFill)->toBe(0)
        ->and($broken)->toBe(0)
        ->and(DB::table('price_daily')->where('is_filled', true)->count())->toBe(26);
});
