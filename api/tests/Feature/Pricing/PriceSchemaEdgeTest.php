<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PriceDaily;
use App\Domain\Pricing\Models\PricePoint;
use App\Domain\Pricing\PricePartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Edge cases for the price tables (plan P2-i1 T02). Reviewer-authored; self-contained helpers (a test file must not
// depend on another file's functions).

/** One variant and one running ingest run; returns [variant_key, run id]. */
function p2edgeSeed(): array
{
    DB::table('card_variants')->insert([
        'variant_key' => 'base1-4:holo:unlimited:-',
        'card_id' => 'base1-4',
        'finish' => 'holo',
        'edition' => 'unlimited',
    ]);
    $run = DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-12', 'marker' => '2026-10-12 20:04:31+00',
        'mode' => 'sync', 'status' => 'running', 'started_at' => '2026-10-13 06:00:00+00',
    ]);

    return ['base1-4:holo:unlimited:-', $run];
}

function p2edgePoint(string $variant, int $run, string $observedAt, string $day, string $market = '70.81'): array
{
    return [
        'variant_key' => $variant, 'source' => 'tcgplayer', 'observed_at' => $observedAt, 'day' => $day,
        'series_epoch' => 1, 'source_product_id' => 42382, 'source_subtype' => 'Holofoil', 'market' => $market,
        'low' => null, 'mid' => null, 'high' => null, 'direct_low' => null, 'ingest_run_id' => $run,
    ];
}

function p2edgeDaily(string $variant, string $day, array $overrides = []): array
{
    return array_merge([
        'variant_key' => $variant, 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => 1, 'open' => '10.00',
        'high' => '10.00', 'low' => '10.00', 'close' => '10.00', 'n_obs' => 1, 'is_filled' => false,
        'filled_from' => null, 'as_of' => '2026-10-12 20:04:31+00',
    ], $overrides);
}

function p2edgeSqlState(Closure $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return (string) $e->getPrevious()?->getCode();
    }

    return null;
}

test('a model created from a Carbon instant saves again onto its own row under a non-UTC session zone', function (): void {
    [$variant, $run] = p2edgeSeed();
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
    $at = CarbonImmutable::parse('2026-10-12 20:04:31+00');
    DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 21:00:00+00', '2026-10-12', '1.00'));

    $point = PricePoint::create(p2edgePoint($variant, $run, '2026-10-12 20:04:31+00', '2026-10-12'));
    $point->observed_at = $at;
    $point->market = '99.99';
    $saved = $point->save();
    $carbon = PricePoint::create(['observed_at' => CarbonImmutable::parse('2026-10-12 22:00:00+02:00'), 'day' => CarbonImmutable::parse('2026-10-12')] + p2edgePoint($variant, $run, 'x', 'x', '7.00'));
    $carbon->market = '8.00';
    $carbonSaved = $carbon->save();
    $daily = PriceDaily::create(p2edgeDaily($variant, '2026-10-12'));
    $daily->close = '10.00';
    $daily->n_obs = 2;
    $dailySaved = $daily->save();

    expect($saved)->toBeTrue()
        ->and($dailySaved)->toBeTrue()
        ->and($carbonSaved)->toBeTrue()
        ->and(DB::table('price_points')->count())->toBe(3)
        ->and(DB::table('price_points')->orderBy('observed_at')->pluck('market')->all())->toBe(['8.00', '99.99', '1.00'])
        ->and(DB::table('price_daily')->value('n_obs'))->toBe(2);
});

test('a loaded price model deletes only its own composite-key row and refresh reads the same row back', function (): void {
    [$variant, $run] = p2edgeSeed();
    DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 20:04:31+00', '2026-10-12', '1.00'));
    DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 20:04:32+00', '2026-10-12', '2.00'));
    DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-12'));
    DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-13', ['as_of' => '2026-10-13 20:00:00+00']));

    $point = PricePoint::query()->where('market', '2.00')->firstOrFail();
    DB::table('price_points')->where('market', '2.00')->update(['mid' => '3.00']);
    $point->refresh();
    $midAfterRefresh = $point->mid;
    $point->delete();
    $daily = PriceDaily::query()->where('day', '2026-10-13')->firstOrFail();
    $daily->delete();

    expect($midAfterRefresh)->toBe('3.00')
        ->and(DB::table('price_points')->pluck('market')->all())->toBe(['1.00'])
        ->and(DB::table('price_daily')->pluck('day')->all())->toBe(['2026-10-12']);
});

test('the day check follows the UTC date of the instant across midnight whatever the session zone', function (): void {
    [$variant, $run] = p2edgeSeed();
    DB::statement("SET LOCAL TIME ZONE 'Pacific/Kiritimati'");

    $lastMicro = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 23:59:59.999999+00', '2026-10-12')));
    $midnight = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-13 00:00:00+00', '2026-10-13')));
    $midnightWrongDay = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-13 00:00:00+00', '2026-10-12', '2.00')));
    $localDayWrong = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 23:30:00-05', '2026-10-12', '3.00')));
    $localDayRight = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 23:30:00-05', '2026-10-13', '4.00')));

    expect($lastMicro)->toBeNull()
        ->and($midnight)->toBeNull()
        ->and($midnightWrongDay)->toBe('23514')
        ->and($localDayWrong)->toBe('23514')
        ->and($localDayRight)->toBeNull();
});

test('the upsert key (variant_key, source, observed_at) drives ON CONFLICT on the partitioned table', function (): void {
    [$variant, $run] = p2edgeSeed();
    DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-10-12 20:04:31+00', '2026-10-12', '1.00'));

    $updated = DB::affectingStatement(
        'INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype, market, ingest_run_id)
         VALUES (?, ?, ?, ?, 1, 42382, ?, ?, ?)
         ON CONFLICT (variant_key, source, observed_at) DO UPDATE SET market = EXCLUDED.market',
        [$variant, 'tcgplayer', '2026-10-12 20:04:31+00', '2026-10-12', 'Holofoil', '5.00', $run],
    );
    $ignored = DB::affectingStatement(
        'INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype, market, ingest_run_id)
         VALUES (?, ?, ?, ?, 1, 42382, ?, ?, ?)
         ON CONFLICT (variant_key, source, observed_at) DO NOTHING',
        [$variant, 'tcgplayer', '2026-10-12 20:04:31+00', '2026-10-12', 'Holofoil', '9.00', $run],
    );

    expect($updated)->toBe(1)
        ->and($ignored)->toBe(0)
        ->and(DB::table('price_points')->count())->toBe(1)
        ->and(DB::table('price_points')->value('market'))->toBe('5.00');
});

test('the 14-day fill boundary and the OHLC equality boundary hold on price_daily', function (): void {
    [$variant] = p2edgeSeed();
    $fill = fn (string $day, string $from): array => p2edgeDaily($variant, $day, ['n_obs' => 0, 'is_filled' => true, 'filled_from' => $from]);

    $day14 = p2edgeSqlState(fn () => DB::table('price_daily')->insert($fill('2026-10-15', '2026-10-01')));
    $day15 = p2edgeSqlState(fn () => DB::table('price_daily')->insert($fill('2026-10-16', '2026-10-01')));
    $acrossMonth = p2edgeSqlState(fn () => DB::table('price_daily')->insert($fill('2026-11-05', '2026-10-22')));
    $acrossMonth15 = p2edgeSqlState(fn () => DB::table('price_daily')->insert($fill('2026-11-06', '2026-10-22')));
    $flat = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-20', ['open' => '0.00', 'high' => '0.00', 'low' => '0.00', 'close' => '0.00'])));
    $lowAboveClose = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-21', ['open' => '5.00', 'high' => '9.00', 'low' => '5.01', 'close' => '5.00'])));
    $highBelowOpen = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-22', ['open' => '9.00', 'high' => '8.99', 'low' => '1.00', 'close' => '5.00'])));
    $negative = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-23', ['low' => '-0.01'])));
    $filledWithObs = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-24', ['n_obs' => 1, 'is_filled' => true, 'filled_from' => '2026-10-23'])));
    $realWithFrom = p2edgeSqlState(fn () => DB::table('price_daily')->insert(p2edgeDaily($variant, '2026-10-25', ['filled_from' => '2026-10-24'])));

    expect($day14)->toBeNull()
        ->and($day15)->toBe('23514')
        ->and($acrossMonth)->toBeNull()
        ->and($acrossMonth15)->toBe('23514')
        ->and($flat)->toBeNull()
        ->and($lowAboveClose)->toBe('23514')
        ->and($highBelowOpen)->toBe('23514')
        ->and($negative)->toBe('23514')
        ->and($filledWithObs)->toBe('23514')
        ->and($realWithFrom)->toBe('23514');
});

test('a partition made by ensureFor carries the BRIN index and the primary key, and a non-prepared month still fails loudly', function (): void {
    [$variant, $run] = p2edgeSeed();
    $name = PricePartitions::ensureFor(CarbonImmutable::parse('2026-11-30 23:59:59.999999+00'));
    $indexes = array_map(
        fn (object $r): string => "{$r->amname}:{$r->pk}",
        DB::select(
            'SELECT am.amname, x.indisprimary::int::text AS pk FROM pg_index x JOIN pg_class ic ON ic.oid = x.indexrelid
             JOIN pg_am am ON am.oid = ic.relam WHERE x.indrelid = ?::regclass ORDER BY am.amname',
            [$name],
        ),
    );

    $inNovember = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-11-30 23:59:59.999999+00', '2026-11-30')));
    $december = p2edgeSqlState(fn () => DB::table('price_points')->insert(p2edgePoint($variant, $run, '2026-12-01 00:00:00+00', '2026-12-01')));

    expect($name)->toBe('price_points_y2026m11')
        ->and($indexes)->toBe(['brin:0', 'btree:1'])
        ->and($inNovember)->toBeNull()
        ->and($december)->toBe('23514');
});

test('down() leaves no price object behind, even a partition ensureFor added', function (): void {
    PricePartitions::ensureFor(CarbonImmutable::parse('2026-11-15 00:00:00+00'));
    PricePartitions::ensureFor(CarbonImmutable::parse('2027-01-01 00:00:00+00'));
    $files = glob(database_path('migrations/2026_10_14_*.php')) ?: [];
    sort($files);
    $migrations = array_map(fn (string $f): object => require $f, $files);

    foreach (array_reverse($migrations) as $m) {
        $m->down();
    }
    $leftovers = DB::select(
        "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND (c.relname LIKE 'price\\_%' OR c.relname LIKE 'ingest\\_run%')
         ORDER BY c.relname",
    );
    $mapStillThere = Schema::hasTable('card_variants');
    foreach ($migrations as $m) {
        $m->up();
    }

    expect(array_map(fn (object $r): string => $r->relname, $leftovers))->toBe([])
        ->and($mapStillThere)->toBeTrue()
        ->and(Schema::hasTable('price_points_y2026m10'))->toBeTrue()
        ->and(Schema::hasTable('price_points_y2026m11'))->toBeFalse();
});

test('ingest status and mode are closed enums and chunk status refuses run statuses', function (): void {
    [, $run] = p2edgeSeed();
    $runStatus = fn (string $status): ?string => p2edgeSqlState(fn () => DB::table('ingest_runs')->insert([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-12', 'mode' => 'sync', 'status' => $status,
        'started_at' => '2026-10-13 06:00:00+00',
    ]));
    $chunkStatus = fn (string $status): ?string => p2edgeSqlState(fn () => DB::table('ingest_run_chunks')->insert([
        'ingest_run_id' => $run, 'chunk_key' => "604:{$status}", 'group_id' => 604, 'offset' => 0, 'rows' => 0, 'status' => $status,
    ]));

    expect($runStatus('superseded'))->toBeNull()
        ->and($runStatus('Running'))->toBe('23514')
        ->and($runStatus(''))->toBe('23514')
        ->and($runStatus('done'))->toBe('23514')
        ->and($chunkStatus('done'))->toBeNull()
        ->and($chunkStatus('ok'))->toBe('23514')
        ->and($chunkStatus('superseded'))->toBe('23514')
        ->and(DB::table('ingest_runs')->whereNull('marker')->count())->toBe(1);
});
