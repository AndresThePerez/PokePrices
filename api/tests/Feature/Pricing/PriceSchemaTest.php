<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\IngestRun;
use App\Domain\Pricing\Models\IngestRunChunk;
use App\Domain\Pricing\Models\PriceDaily;
use App\Domain\Pricing\Models\PricePoint;
use App\Domain\Pricing\PricePartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The P2 price schema (plan P2-i1 T02, D1-D3, D5, D9). This file is the "frozen" half of price_points and
// price_daily under the R153 override: a change to either table lands with an edit here, after a plan amendment.
// Every assertion reads the catalog or writes rows through the test connection inside the test transaction.

const P2T02_MIGRATION = 'migrations/2026_10_14_000001_create_price_tables.php';

const P2T02_TABLES = ['price_points', 'price_daily', 'ingest_runs', 'ingest_run_chunks'];

const P2T02_MARKER = '2026-10-12 20:04:31+00';

const P2T02_CONSTRAINTS = [
    'price_points' => [
        'price_points_day_check' => 'c:day,observed_at',
        'price_points_direct_low_check' => 'c:direct_low',
        'price_points_high_check' => 'c:high',
        'price_points_ingest_run_id_foreign' => 'f:ingest_run_id',
        'price_points_low_check' => 'c:low',
        'price_points_market_check' => 'c:market',
        'price_points_mid_check' => 'c:mid',
        'price_points_pkey' => 'p:variant_key,source,observed_at',
        'price_points_series_epoch_check' => 'c:series_epoch',
        'price_points_source_check' => 'c:source',
        'price_points_source_product_id_check' => 'c:source_product_id',
        'price_points_source_subtype_check' => 'c:source_subtype',
        'price_points_variant_key_foreign' => 'f:variant_key',
    ],
    'price_daily' => [
        'price_daily_close_check' => 'c:close',
        'price_daily_filled_from_check' => 'c:is_filled,filled_from,day',
        'price_daily_high_check' => 'c:high',
        'price_daily_is_filled_check' => 'c:is_filled,n_obs',
        'price_daily_low_check' => 'c:low',
        'price_daily_n_obs_check' => 'c:n_obs',
        'price_daily_ohlc_check' => 'c:low,open,close,high',
        'price_daily_open_check' => 'c:open',
        'price_daily_pkey' => 'p:variant_key,source,day',
        'price_daily_series_epoch_check' => 'c:series_epoch',
        'price_daily_source_check' => 'c:source',
        'price_daily_variant_key_foreign' => 'f:variant_key',
    ],
    'ingest_runs' => [
        'ingest_runs_feed_check' => 'c:feed',
        'ingest_runs_mode_check' => 'c:mode',
        'ingest_runs_pkey' => 'p:id',
        'ingest_runs_source_check' => 'c:source',
        'ingest_runs_status_check' => 'c:status',
    ],
    'ingest_run_chunks' => [
        'ingest_run_chunks_ingest_run_id_foreign' => 'f:ingest_run_id',
        'ingest_run_chunks_offset_check' => 'c:offset',
        'ingest_run_chunks_pkey' => 'p:id',
        'ingest_run_chunks_rows_check' => 'c:rows',
        'ingest_run_chunks_run_chunk_key_unique' => 'u:ingest_run_id,chunk_key',
        'ingest_run_chunks_status_check' => 'c:status',
    ],
];

/** A variant of base1-4 and one running ingest run, the parents every price row points at. */
function p2t02Seed(): array
{
    foreach (['unlimited', 'shadowless'] as $edition) {
        DB::table('card_variants')->insert([
            'variant_key' => "base1-4:holo:{$edition}:-",
            'card_id' => 'base1-4',
            'finish' => 'holo',
            'edition' => $edition,
        ]);
    }
    $run = DB::table('ingest_runs')->insertGetId(p2t02Row('ingest_runs', []));

    return ['@run' => $run, '@variant' => 'base1-4:holo:unlimited:-', '@variant2' => 'base1-4:holo:shadowless:-'];
}

/** A valid row for $table with $overrides applied; '@run', '@variant' and '@variant2' resolve to the seeded parents. */
function p2t02Row(string $table, array $ids, array $overrides = []): array
{
    $base = match ($table) {
        'ingest_runs' => [
            'source' => 'tcgplayer',
            'feed' => 'tcgcsv',
            'day' => '2026-10-12',
            'marker' => P2T02_MARKER,
            'mode' => 'sync',
            'status' => 'running',
            'started_at' => '2026-10-13 06:00:00+00',
        ],
        'ingest_run_chunks' => [
            'ingest_run_id' => '@run',
            'chunk_key' => '604:0',
            'group_id' => 604,
            'offset' => 0,
            'rows' => 200,
            'status' => 'pending',
        ],
        'price_points' => [
            'variant_key' => '@variant',
            'source' => 'tcgplayer',
            'observed_at' => P2T02_MARKER,
            'day' => '2026-10-12',
            'series_epoch' => 1,
            'source_product_id' => 42382,
            'source_subtype' => 'Holofoil',
            'market' => '70.81',
            'low' => '55.00',
            'mid' => '75.00',
            'high' => '120.00',
            'direct_low' => null,
            'ingest_run_id' => '@run',
        ],
        'price_daily' => [
            'variant_key' => '@variant',
            'source' => 'tcgplayer',
            'day' => '2026-10-12',
            'series_epoch' => 1,
            'open' => '70.81',
            'high' => '70.81',
            'low' => '70.81',
            'close' => '70.81',
            'n_obs' => 1,
            'is_filled' => false,
            'filled_from' => null,
            'as_of' => P2T02_MARKER,
        ],
    };

    return array_map(
        fn ($v) => is_string($v) && array_key_exists($v, $ids) ? $ids[$v] : $v,
        array_merge($base, $overrides),
    );
}

/** Runs $statement in a savepoint and returns the QueryException it raised, or null. */
function p2t02Error(Closure $statement): ?QueryException
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

/** "name:type[(precision,scale)]:NULL|NOT NULL" for each column of $table, in column order. */
function p2t02Columns(string $table): array
{
    return array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}"
            .($c->data_type === 'numeric' ? "({$c->numeric_precision},{$c->numeric_scale})" : '')
            .':'.($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL'),
        DB::select(
            'SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        ),
    );
}

/** conname => "type:col1,col2" for the constraints of $table, sorted by name. */
function p2t02Constraints(string $table): array
{
    $rows = DB::select(
        "SELECT c.conname, c.contype, string_agg(a.attname, ',' ORDER BY k.ord) AS cols
         FROM pg_constraint c
         CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
         JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
         WHERE c.conrelid = ?::regclass
         GROUP BY c.conname, c.contype",
        [$table],
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r->conname] = "{$r->contype}:{$r->cols}";
    }
    ksort($out);

    return $out;
}

/** conname => pg_get_constraintdef for the CHECK constraints of $table, sorted by name. */
function p2t02CheckDefinitions(string $table): array
{
    $out = [];
    foreach (DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c'", [$table]) as $r) {
        $out[$r->conname] = $r->def;
    }
    ksort($out);

    return $out;
}

/** index name => "access method:col1,col2" for the indexes of $table, sorted by name. */
function p2t02Indexes(string $table): array
{
    $rows = DB::select(
        "SELECT ic.relname AS name, am.amname AS method, string_agg(a.attname, ',' ORDER BY k.ord) AS cols
         FROM pg_index x
         JOIN pg_class ic ON ic.oid = x.indexrelid
         JOIN pg_am am ON am.oid = ic.relam
         CROSS JOIN LATERAL unnest(x.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord)
         JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum
         WHERE x.indrelid = ?::regclass
         GROUP BY ic.relname, am.amname",
        [$table],
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r->name] = "{$r->method}:{$r->cols}";
    }
    ksort($out);

    return $out;
}

/** partition name => its bound expression, rendered in UTC, sorted by name. */
function p2t02Partitions(): array
{
    DB::statement("SET LOCAL TIME ZONE 'UTC'");
    $out = [];
    foreach (DB::select(
        "SELECT c.relname, pg_get_expr(c.relpartbound, c.oid) AS bound
         FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
         WHERE i.inhparent = 'price_points'::regclass",
    ) as $r) {
        $out[$r->relname] = $r->bound;
    }
    ksort($out);

    return $out;
}

test('the migration creates the four price tables', function (): void {
    foreach (P2T02_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
    }
});

test('each price table has exactly the planned columns, types, numeric precision and nullability', function (string $table, array $columns): void {
    expect(p2t02Columns($table))->toBe($columns);
})->with([
    'price_points' => ['price_points', [
        'variant_key:text:NOT NULL',
        'source:text:NOT NULL',
        'observed_at:timestamp with time zone:NOT NULL',
        'day:date:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'source_product_id:integer:NOT NULL',
        'source_subtype:text:NOT NULL',
        'market:numeric(12,2):NULL',
        'low:numeric(12,2):NULL',
        'mid:numeric(12,2):NULL',
        'high:numeric(12,2):NULL',
        'direct_low:numeric(12,2):NULL',
        'ingest_run_id:bigint:NOT NULL',
    ]],
    'price_daily (no wall-clock or run column)' => ['price_daily', [
        'variant_key:text:NOT NULL',
        'source:text:NOT NULL',
        'day:date:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'open:numeric(12,2):NOT NULL',
        'high:numeric(12,2):NOT NULL',
        'low:numeric(12,2):NOT NULL',
        'close:numeric(12,2):NOT NULL',
        'n_obs:smallint:NOT NULL',
        'is_filled:boolean:NOT NULL',
        'filled_from:date:NULL',
        'as_of:timestamp with time zone:NOT NULL',
    ]],
    'ingest_runs' => ['ingest_runs', [
        'id:bigint:NOT NULL',
        'source:text:NOT NULL',
        'feed:text:NOT NULL',
        'day:date:NOT NULL',
        'marker:timestamp with time zone:NULL',
        'mode:text:NOT NULL',
        'status:text:NOT NULL',
        'started_at:timestamp with time zone:NOT NULL',
        'finished_at:timestamp with time zone:NULL',
        'counts:jsonb:NOT NULL',
        'error:text:NULL',
    ]],
    'ingest_run_chunks' => ['ingest_run_chunks', [
        'id:bigint:NOT NULL',
        'ingest_run_id:bigint:NOT NULL',
        'chunk_key:text:NOT NULL',
        'group_id:integer:NOT NULL',
        'offset:integer:NOT NULL',
        'rows:integer:NOT NULL',
        'status:text:NOT NULL',
        'attempts:integer:NOT NULL',
        'error:text:NULL',
        'counts:jsonb:NOT NULL',
    ]],
]);

test('every planned constraint is a plain named constraint in pg_constraint', function (string $table): void {
    expect(p2t02Constraints($table))->toBe(P2T02_CONSTRAINTS[$table]);
})->with(P2T02_TABLES);

test('the CHECK constraints of price_points and price_daily have exactly the planned definitions', function (): void {
    $subtypes = "ARRAY['Normal'::text, 'Holofoil'::text, 'Reverse Holofoil'::text, '1st Edition'::text, "
        ."'Unlimited'::text, '1st Edition Holofoil'::text, 'Unlimited Holofoil'::text]";

    expect(p2t02CheckDefinitions('price_points'))->toBe([
        'price_points_day_check' => "CHECK ((day = ((observed_at AT TIME ZONE 'UTC'::text))::date))",
        'price_points_direct_low_check' => 'CHECK ((direct_low >= (0)::numeric))',
        'price_points_high_check' => 'CHECK ((high >= (0)::numeric))',
        'price_points_low_check' => 'CHECK ((low >= (0)::numeric))',
        'price_points_market_check' => 'CHECK ((market >= (0)::numeric))',
        'price_points_mid_check' => 'CHECK ((mid >= (0)::numeric))',
        'price_points_series_epoch_check' => 'CHECK ((series_epoch >= 1))',
        'price_points_source_check' => "CHECK ((source = 'tcgplayer'::text))",
        'price_points_source_product_id_check' => 'CHECK ((source_product_id > 0))',
        'price_points_source_subtype_check' => "CHECK ((source_subtype = ANY ({$subtypes})))",
    ])->and(p2t02CheckDefinitions('price_daily'))->toBe([
        'price_daily_close_check' => 'CHECK ((close >= (0)::numeric))',
        'price_daily_filled_from_check' => 'CHECK (((is_filled AND (filled_from IS NOT NULL) AND (filled_from < day) AND ((day - filled_from) <= 14)) OR ((NOT is_filled) AND (filled_from IS NULL))))',
        'price_daily_high_check' => 'CHECK ((high >= (0)::numeric))',
        'price_daily_is_filled_check' => 'CHECK ((is_filled = (n_obs = 0)))',
        'price_daily_low_check' => 'CHECK ((low >= (0)::numeric))',
        'price_daily_n_obs_check' => 'CHECK ((n_obs >= 0))',
        'price_daily_ohlc_check' => 'CHECK (((low <= LEAST(open, close)) AND (high >= GREATEST(open, close))))',
        'price_daily_open_check' => 'CHECK ((open >= (0)::numeric))',
        'price_daily_series_epoch_check' => 'CHECK ((series_epoch >= 1))',
        'price_daily_source_check' => "CHECK ((source = 'tcgplayer'::text))",
    ]);
});

test('the foreign keys point at card_variants(variant_key) and ingest_runs(id)', function (): void {
    $fks = DB::select(
        "SELECT c.conname, c.confrelid::regclass::text AS target, a.attname AS target_col
         FROM pg_constraint c
         JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = c.confkey[1]
         WHERE c.contype = 'f' AND c.conrelid::regclass::text = ANY (?)
         ORDER BY c.conname",
        ['{'.implode(',', P2T02_TABLES).'}'],
    );

    expect(array_map(fn (object $r): string => "{$r->conname}->{$r->target}.{$r->target_col}", $fks))->toBe([
        'ingest_run_chunks_ingest_run_id_foreign->ingest_runs.id',
        'price_daily_variant_key_foreign->card_variants.variant_key',
        'price_points_ingest_run_id_foreign->ingest_runs.id',
        'price_points_variant_key_foreign->card_variants.variant_key',
    ]);
});

test('the indexes: BRIN on price_points.observed_at, (source, day), (source, status, marker)', function (string $table, array $indexes): void {
    expect(p2t02Indexes($table))->toBe($indexes);
})->with([
    'price_points' => ['price_points', [
        'price_points_observed_at_brin' => 'brin:observed_at',
        'price_points_pkey' => 'btree:variant_key,source,observed_at',
    ]],
    'price_daily' => ['price_daily', [
        'price_daily_pkey' => 'btree:variant_key,source,day',
        'price_daily_source_day_index' => 'btree:source,day',
    ]],
    'ingest_runs' => ['ingest_runs', [
        'ingest_runs_pkey' => 'btree:id',
        'ingest_runs_source_status_marker_index' => 'btree:source,status,marker',
    ]],
    'ingest_run_chunks' => ['ingest_run_chunks', [
        'ingest_run_chunks_pkey' => 'btree:id',
        'ingest_run_chunks_run_chunk_key_unique' => 'btree:ingest_run_id,chunk_key',
    ]],
]);

test('price_points is range-partitioned on observed_at with exactly the 2026-10 partition and no default', function (): void {
    $partitioned = DB::selectOne(
        "SELECT p.partstrat, p.partdefid::int AS default_oid, p.partnatts,
                (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = p.partrelid AND a.attnum = p.partattrs[0]) AS key
         FROM pg_partitioned_table p WHERE p.partrelid = 'price_points'::regclass",
    );
    $others = DB::selectOne(
        "SELECT count(*) AS n FROM pg_partitioned_table WHERE partrelid <> 'price_points'::regclass",
    );
    $partitionBrin = DB::selectOne(
        "SELECT count(*) AS n FROM pg_index x JOIN pg_class ic ON ic.oid = x.indexrelid JOIN pg_am am ON am.oid = ic.relam
         WHERE x.indrelid = 'price_points_y2026m10'::regclass AND am.amname = 'brin'",
    );

    expect($partitioned->partstrat)->toBe('r')
        ->and($partitioned->key)->toBe('observed_at')
        ->and($partitioned->partnatts)->toBe(1)
        ->and($partitioned->default_oid)->toBe(0)
        ->and($others->n)->toBe(0)
        ->and(p2t02Partitions())->toBe([
            'price_points_y2026m10' => "FOR VALUES FROM ('2026-10-01 00:00:00+00') TO ('2026-11-01 00:00:00+00')",
        ])
        ->and($partitionBrin->n)->toBe(1);
});

test('the 2026-10 partition takes its first and last instant and refuses the instants either side with 23514', function (): void {
    $ids = p2t02Seed();
    $insert = fn (string $at, string $day) => p2t02Error(fn () => DB::table('price_points')->insert(
        p2t02Row('price_points', $ids, ['observed_at' => $at, 'day' => $day]),
    ));

    $first = $insert('2026-10-01 00:00:00+00', '2026-10-01');
    $last = $insert('2026-10-31 23:59:59.999999+00', '2026-10-31');
    $before = $insert('2026-09-30 23:59:59.999999+00', '2026-09-30');
    $after = $insert('2026-11-01 00:00:00+00', '2026-11-01');

    expect($first)->toBeNull()
        ->and($last)->toBeNull()
        ->and($before)->toBeInstanceOf(QueryException::class)
        ->and($before?->getCode())->toBe('23514')
        ->and($before?->getMessage())->toContain('no partition of relation "price_points" found for row')
        ->and($after?->getCode())->toBe('23514')
        ->and($after?->getMessage())->toContain('no partition of relation "price_points" found for row')
        ->and(DB::table('price_points')->count())->toBe(2)
        ->and(DB::table('price_points_y2026m10')->count())->toBe(2);
});

test('ensureFor creates the month partition of an instant, idempotently', function (): void {
    $ids = p2t02Seed();

    $name = PricePartitions::ensureFor(CarbonImmutable::parse('2026-11-15 12:00:00+00'));
    $again = PricePartitions::ensureFor(CarbonImmutable::parse('2026-11-30 23:59:59+00'));
    $october = PricePartitions::ensureFor(CarbonImmutable::parse(P2T02_MARKER));
    $partitions = p2t02Partitions();
    $november = p2t02Error(fn () => DB::table('price_points')->insert(
        p2t02Row('price_points', $ids, ['observed_at' => '2026-11-15 12:00:00+00', 'day' => '2026-11-15']),
    ));

    expect($name)->toBe('price_points_y2026m11')
        ->and($again)->toBe('price_points_y2026m11')
        ->and($october)->toBe('price_points_y2026m10')
        ->and($partitions)->toBe([
            'price_points_y2026m10' => "FOR VALUES FROM ('2026-10-01 00:00:00+00') TO ('2026-11-01 00:00:00+00')",
            'price_points_y2026m11' => "FOR VALUES FROM ('2026-11-01 00:00:00+00') TO ('2026-12-01 00:00:00+00')",
        ])
        ->and($november)->toBeNull()
        ->and(DB::table('price_points_y2026m11')->count())->toBe(1);
});

test('ensureFor picks the UTC month of an instant given in another zone and rolls December into January', function (): void {
    // 2026-11-01 01:00 at +02:00 is 2026-10-31 23:00 UTC: October, which already exists.
    $october = PricePartitions::ensureFor(CarbonImmutable::parse('2026-11-01 01:00:00', '+02:00'));
    $december = PricePartitions::ensureFor(CarbonImmutable::parse('2026-12-31 23:00:00+00'));

    expect($october)->toBe('price_points_y2026m10')
        ->and(p2t02Partitions())->toBe([
            'price_points_y2026m10' => "FOR VALUES FROM ('2026-10-01 00:00:00+00') TO ('2026-11-01 00:00:00+00')",
            'price_points_y2026m12' => "FOR VALUES FROM ('2026-12-01 00:00:00+00') TO ('2027-01-01 00:00:00+00')",
        ])
        ->and($december)->toBe('price_points_y2026m12');
});

test('each CHECK rejects a violating row with SQLSTATE 23514 inside DB::transaction', function (string $table, array $overrides, string $constraint): void {
    $ids = p2t02Seed();

    [$error, $count] = DB::transaction(function () use ($table, $ids, $overrides): array {
        $error = p2t02Error(fn () => DB::table($table)->insert(p2t02Row($table, $ids, $overrides)));

        return [$error, DB::table($table)->count()];
    });

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->getCode())->toBe('23514')
        ->and($error?->getMessage())->toMatch("/violates check constraint \"(?:{$constraint})\"/")
        ->and($count)->toBe($table === 'ingest_runs' ? 1 : 0);
})->with([
    'points source ebay' => ['price_points', ['source' => 'ebay'], 'price_points_source_check'],
    'points day is the local, not the UTC, date' => ['price_points', ['observed_at' => '2026-10-12 23:30:00-02:00', 'day' => '2026-10-12'], 'price_points_day_check'],
    'points day a day early' => ['price_points', ['day' => '2026-10-11'], 'price_points_day_check'],
    'points epoch 0' => ['price_points', ['series_epoch' => 0], 'price_points_series_epoch_check'],
    'points product 0' => ['price_points', ['source_product_id' => 0], 'price_points_source_product_id_check'],
    'points subtype Foil' => ['price_points', ['source_subtype' => 'Foil'], 'price_points_source_subtype_check'],
    'points market -0.01' => ['price_points', ['market' => '-0.01'], 'price_points_market_check'],
    'points low -1' => ['price_points', ['low' => '-1'], 'price_points_low_check'],
    'points mid -1' => ['price_points', ['mid' => '-1'], 'price_points_mid_check'],
    'points high -1' => ['price_points', ['high' => '-1'], 'price_points_high_check'],
    'points direct_low -1' => ['price_points', ['direct_low' => '-1'], 'price_points_direct_low_check'],
    'daily source ebay' => ['price_daily', ['source' => 'ebay'], 'price_daily_source_check'],
    'daily epoch 0' => ['price_daily', ['series_epoch' => 0], 'price_daily_series_epoch_check'],
    'daily n_obs -1' => ['price_daily', ['n_obs' => -1], 'price_daily_n_obs_check'],
    // A negative open, high or close forces a negative low through the OHLC rule; both checks name the row.
    'daily open -1' => ['price_daily', ['open' => '-1', 'low' => '-1'], 'price_daily_low_check|price_daily_open_check'],
    'daily close -1' => ['price_daily', ['close' => '-1', 'low' => '-1'], 'price_daily_close_check|price_daily_low_check'],
    'daily all -1' => ['price_daily', ['open' => '-1', 'high' => '-1', 'low' => '-1', 'close' => '-1'], 'price_daily_close_check|price_daily_high_check|price_daily_low_check|price_daily_open_check'],
    'daily low above open' => ['price_daily', ['open' => '70.00', 'close' => '71.00', 'low' => '70.50', 'high' => '72.00'], 'price_daily_ohlc_check'],
    'daily low above close' => ['price_daily', ['open' => '71.00', 'close' => '70.00', 'low' => '70.50', 'high' => '72.00'], 'price_daily_ohlc_check'],
    'daily high below close' => ['price_daily', ['open' => '70.00', 'close' => '72.00', 'low' => '69.00', 'high' => '71.00'], 'price_daily_ohlc_check'],
    'daily high below open' => ['price_daily', ['open' => '72.00', 'close' => '70.00', 'low' => '69.00', 'high' => '71.00'], 'price_daily_ohlc_check'],
    'daily filled with an observation' => ['price_daily', ['is_filled' => true, 'n_obs' => 1, 'filled_from' => '2026-10-11'], 'price_daily_is_filled_check'],
    'daily real without an observation' => ['price_daily', ['is_filled' => false, 'n_obs' => 0], 'price_daily_is_filled_check'],
    'daily filled without filled_from' => ['price_daily', ['is_filled' => true, 'n_obs' => 0], 'price_daily_filled_from_check'],
    'daily filled from the same day' => ['price_daily', ['is_filled' => true, 'n_obs' => 0, 'filled_from' => '2026-10-12'], 'price_daily_filled_from_check'],
    'daily filled from a later day' => ['price_daily', ['is_filled' => true, 'n_obs' => 0, 'filled_from' => '2026-10-13'], 'price_daily_filled_from_check'],
    'daily 15-day fill' => ['price_daily', ['day' => '2026-10-26', 'is_filled' => true, 'n_obs' => 0, 'filled_from' => '2026-10-11'], 'price_daily_filled_from_check'],
    'daily real with filled_from' => ['price_daily', ['filled_from' => '2026-10-11'], 'price_daily_filled_from_check'],
    'runs source ebay' => ['ingest_runs', ['source' => 'ebay'], 'ingest_runs_source_check'],
    'runs feed tcgplayer' => ['ingest_runs', ['feed' => 'tcgplayer'], 'ingest_runs_feed_check'],
    'runs mode manual' => ['ingest_runs', ['mode' => 'manual'], 'ingest_runs_mode_check'],
    'runs status done' => ['ingest_runs', ['status' => 'done'], 'ingest_runs_status_check'],
    'chunks offset -1' => ['ingest_run_chunks', ['offset' => -1], 'ingest_run_chunks_offset_check'],
    'chunks rows -1' => ['ingest_run_chunks', ['rows' => -1], 'ingest_run_chunks_rows_check'],
    'chunks status running' => ['ingest_run_chunks', ['status' => 'running'], 'ingest_run_chunks_status_check'],
]);

test('every planned domain value and boundary is accepted', function (string $table, array $overrides): void {
    $ids = p2t02Seed();
    $before = DB::table($table)->count();
    DB::table($table)->insert(p2t02Row($table, $ids, $overrides));

    expect(DB::table($table)->count())->toBe($before + 1);
})->with([
    'points day is the UTC date of a +02:00 instant' => ['price_points', ['observed_at' => '2026-10-13 00:30:00+02:00', 'day' => '2026-10-12']],
    'points all prices null (null market is stored raw)' => ['price_points', ['market' => null, 'low' => null, 'mid' => null, 'high' => null]],
    'points zero prices' => ['price_points', ['market' => '0.00', 'low' => '0', 'mid' => '0', 'high' => '0', 'direct_low' => '0']],
    'points largest numeric(12,2)' => ['price_points', ['market' => '9999999999.99']],
    'points subtype Normal' => ['price_points', ['source_subtype' => 'Normal']],
    'points subtype Reverse Holofoil' => ['price_points', ['source_subtype' => 'Reverse Holofoil']],
    'points subtype 1st Edition' => ['price_points', ['source_subtype' => '1st Edition']],
    'points subtype Unlimited' => ['price_points', ['source_subtype' => 'Unlimited']],
    'points subtype 1st Edition Holofoil' => ['price_points', ['source_subtype' => '1st Edition Holofoil']],
    'points subtype Unlimited Holofoil' => ['price_points', ['source_subtype' => 'Unlimited Holofoil']],
    'points epoch 2' => ['price_points', ['series_epoch' => 2]],
    'daily low equals open, high equals close' => ['price_daily', ['open' => '70.00', 'close' => '72.00', 'low' => '70.00', 'high' => '72.00']],
    'daily wide candle' => ['price_daily', ['open' => '71.00', 'close' => '70.00', 'low' => '0.00', 'high' => '214398.51', 'n_obs' => 2]],
    'daily 1-day fill' => ['price_daily', ['day' => '2026-10-12', 'is_filled' => true, 'n_obs' => 0, 'filled_from' => '2026-10-11']],
    'daily 14-day fill' => ['price_daily', ['day' => '2026-10-25', 'is_filled' => true, 'n_obs' => 0, 'filled_from' => '2026-10-11']],
    'runs mode queued' => ['ingest_runs', ['mode' => 'queued']],
    'runs mode replay' => ['ingest_runs', ['mode' => 'replay']],
    'runs status ok' => ['ingest_runs', ['status' => 'ok', 'finished_at' => '2026-10-13 06:01:00+00']],
    'runs status failed' => ['ingest_runs', ['status' => 'failed', 'error' => 'boom']],
    'runs status refused before the marker' => ['ingest_runs', ['status' => 'refused', 'marker' => null, 'error' => 'marker_day_mismatch']],
    'runs status skipped_unchanged' => ['ingest_runs', ['status' => 'skipped_unchanged']],
    'runs status superseded' => ['ingest_runs', ['status' => 'superseded']],
    'chunks done' => ['ingest_run_chunks', ['status' => 'done', 'counts' => '{"mapped": 1, "unmapped": 0, "null_market": 0, "upserted": 1, "unchanged": 0}']],
    'chunks failed' => ['ingest_run_chunks', ['status' => 'failed', 'attempts' => 3, 'error' => 'boom']],
    'chunks empty' => ['ingest_run_chunks', ['rows' => 0, 'offset' => 400, 'chunk_key' => '604:400']],
]);

test('a money value beyond numeric(12,2) overflows with 22003', function (): void {
    $ids = p2t02Seed();
    $error = p2t02Error(fn () => DB::table('price_points')->insert(p2t02Row('price_points', $ids, ['market' => '10000000000.00'])));

    expect($error?->getCode())->toBe('22003')
        ->and(DB::table('price_points')->count())->toBe(0);
});

test('each primary key and UNIQUE rejects a duplicate with 23505 inside DB::transaction', function (string $table, array $duplicate, string $constraint): void {
    $ids = p2t02Seed();

    [$error, $count] = DB::transaction(function () use ($table, $ids, $duplicate): array {
        DB::table($table)->insert(p2t02Row($table, $ids));
        $error = p2t02Error(fn () => DB::table($table)->insert(p2t02Row($table, $ids, $duplicate)));

        return [$error, DB::table($table)->count()];
    });

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->getCode())->toBe('23505')
        ->and($error?->getMessage())->toContain("violates unique constraint \"{$constraint}\"")
        ->and($count)->toBe($table === 'ingest_runs' ? 2 : 1);
})->with([
    // The upsert key is (variant, source, observed_at): another product, subtype or price is still the same point.
    'price_points (variant, source, observed_at)' => ['price_points', ['source_product_id' => 106999, 'source_subtype' => 'Unlimited Holofoil', 'market' => '1.00'], 'price_points_y2026m10_pkey'],
    'price_daily (variant, source, day)' => ['price_daily', ['close' => '70.81', 'n_obs' => 2, 'as_of' => '2026-10-12 21:00:00+00'], 'price_daily_pkey'],
    'ingest_run_chunks (run, chunk_key)' => ['ingest_run_chunks', ['offset' => 200, 'rows' => 1], 'ingest_run_chunks_run_chunk_key_unique'],
]);

test('the same variant at another marker or another day is a new row', function (): void {
    $ids = p2t02Seed();
    DB::table('price_points')->insert(p2t02Row('price_points', $ids));
    DB::table('price_points')->insert(p2t02Row('price_points', $ids, ['observed_at' => '2026-10-12 21:00:00+00']));
    DB::table('price_points')->insert(p2t02Row('price_points', $ids, ['variant_key' => $ids['@variant2']]));
    DB::table('price_daily')->insert(p2t02Row('price_daily', $ids));
    DB::table('price_daily')->insert(p2t02Row('price_daily', $ids, ['day' => '2026-10-13', 'as_of' => '2026-10-13 20:00:00+00']));

    expect(DB::table('price_points')->count())->toBe(3)
        ->and(DB::table('price_daily')->count())->toBe(2);
});

test('the foreign keys reject an unknown variant or run with 23503', function (string $table, array $overrides, string $constraint): void {
    $ids = p2t02Seed();
    $error = p2t02Error(fn () => DB::table($table)->insert(p2t02Row($table, $ids, $overrides)));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->getCode())->toBe('23503')
        ->and($error?->getMessage())->toContain("violates foreign key constraint \"{$constraint}\"")
        ->and(DB::table($table)->count())->toBe(0);
})->with([
    'points variant' => ['price_points', ['variant_key' => 'base1-4:normal:-:-'], 'price_points_variant_key_foreign'],
    'points run' => ['price_points', ['ingest_run_id' => 999999999], 'price_points_ingest_run_id_foreign'],
    'daily variant' => ['price_daily', ['variant_key' => 'base1-4:normal:-:-'], 'price_daily_variant_key_foreign'],
    'chunks run' => ['ingest_run_chunks', ['ingest_run_id' => 999999999], 'ingest_run_chunks_ingest_run_id_foreign'],
]);

test('counts default to {} and attempts to 0', function (): void {
    $ids = p2t02Seed();
    DB::table('ingest_run_chunks')->insert(p2t02Row('ingest_run_chunks', $ids));

    $run = DB::table('ingest_runs')->where('id', $ids['@run'])->first();
    $chunk = DB::table('ingest_run_chunks')->first();

    expect(json_decode($run->counts, true))->toBe([])
        ->and(json_decode($chunk->counts, true))->toBe([])
        ->and($chunk->attempts)->toBe(0)
        ->and($chunk->error)->toBeNull();
});

test('the models read and write their tables with typed casts and UTC instants whatever the session zone', function (): void {
    p2t02Seed();
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
    $marker = CarbonImmutable::parse(P2T02_MARKER);

    $run = IngestRun::create(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-12', 'marker' => $marker, 'mode' => 'sync', 'status' => 'running', 'started_at' => $marker, 'counts' => ['rows' => 3]]);
    IngestRunChunk::create(['ingest_run_id' => $run->id, 'chunk_key' => '604:0', 'group_id' => 604, 'offset' => 0, 'rows' => 3, 'status' => 'done', 'counts' => ['mapped' => 3]]);
    PricePoint::create(p2t02Row('price_points', ['@run' => $run->id, '@variant' => 'base1-4:holo:unlimited:-'], ['observed_at' => $marker]));
    PriceDaily::create(p2t02Row('price_daily', ['@variant' => 'base1-4:holo:unlimited:-'], ['as_of' => $marker]));

    $run = IngestRun::findOrFail($run->id);
    $chunk = IngestRunChunk::query()->where('ingest_run_id', $run->id)->firstOrFail();
    $point = PricePoint::query()->firstOrFail();
    $daily = PriceDaily::query()->firstOrFail();
    $stored = DB::selectOne("SELECT to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at FROM price_points");

    expect($stored->at)->toBe('2026-10-12 20:04:31')
        ->and($run->counts)->toBe(['rows' => 3])
        ->and($run->marker?->utc()->toIso8601String())->toBe('2026-10-12T20:04:31+00:00')
        ->and($run->day->toDateString())->toBe('2026-10-12')
        ->and($chunk->counts)->toBe(['mapped' => 3])
        ->and($chunk->attempts)->toBe(0)
        ->and($chunk->offset)->toBe(0)
        ->and($point->observed_at->utc()->toIso8601String())->toBe('2026-10-12T20:04:31+00:00')
        ->and($point->day->toDateString())->toBe('2026-10-12')
        ->and($point->market)->toBe('70.81')
        ->and($point->direct_low)->toBeNull()
        ->and($point->series_epoch)->toBe(1)
        ->and($point->ingest_run_id)->toBe($run->id)
        ->and($daily->close)->toBe('70.81')
        ->and($daily->is_filled)->toBeFalse()
        ->and($daily->n_obs)->toBe(1)
        ->and($daily->filled_from)->toBeNull()
        ->and($daily->as_of->utc()->toIso8601String())->toBe('2026-10-12T20:04:31+00:00');
});

test('saving a loaded price model updates only its own composite-key row', function (): void {
    $ids = p2t02Seed();
    DB::table('price_points')->insert(p2t02Row('price_points', $ids));
    DB::table('price_points')->insert(p2t02Row('price_points', $ids, ['observed_at' => '2026-10-12 21:00:00+00']));
    DB::table('price_points')->insert(p2t02Row('price_points', $ids, ['variant_key' => $ids['@variant2']]));
    DB::table('price_daily')->insert(p2t02Row('price_daily', $ids));
    DB::table('price_daily')->insert(p2t02Row('price_daily', $ids, ['day' => '2026-10-13', 'as_of' => '2026-10-13 20:00:00+00']));

    $point = PricePoint::query()->where('variant_key', $ids['@variant'])->where('observed_at', '2026-10-12 21:00:00+00')->firstOrFail();
    $point->market = '80.00';
    $point->save();
    $daily = PriceDaily::query()->where('day', '2026-10-13')->firstOrFail();
    $daily->close = '70.00';
    $daily->low = '70.00';
    $daily->save();

    expect(DB::table('price_points')->orderBy('variant_key')->orderBy('observed_at')->pluck('market')->all())->toBe(['70.81', '70.81', '80.00'])
        ->and(DB::table('price_daily')->orderBy('day')->pluck('close')->all())->toBe(['70.81', '70.00']);
});

test('config/pricing.php holds the D9 values', function (): void {
    expect(config('pricing'))->toBe([
        'source' => 'tcgplayer',
        'feed' => 'tcgcsv',
        'fill_cap_days' => 14,
        'chunk_rows' => 200,
        'movement' => ['rule' => 'draft-v1', 'pct' => '5.00', 'abs' => '1.00'],
        'movers' => ['limit' => 50, 'min_previous_close' => '2.00'],
        'freshness' => ['slo_hours' => 26, 'missing_day_warning_days' => 14],
        'queue' => 'default',
    ]);
});

test('the round trip, pinned to this migration: down() then up() restores the tables, the partition and every constraint', function (): void {
    $files = glob(database_path('migrations/2026_10_14_*.php')) ?: [];
    sort($files);
    $self = database_path(P2T02_MIGRATION);
    $later = array_values(array_filter($files, fn (string $f): bool => strcmp($f, $self) > 0));
    $migration = require $self;
    $laterMigrations = array_map(fn (string $f): object => require $f, $later);

    foreach (array_reverse($laterMigrations) as $m) {
        $m->down();
    }
    $migration->down();
    $afterDown = array_map(fn (string $t): bool => Schema::hasTable($t), P2T02_TABLES);
    $partitionAfterDown = Schema::hasTable('price_points_y2026m10');
    $migration->up();
    foreach ($laterMigrations as $m) {
        $m->up();
    }

    expect($files)->toContain($self)
        ->and($afterDown)->toBe([false, false, false, false])
        ->and($partitionAfterDown)->toBeFalse()
        ->and(array_map(fn (string $t): bool => Schema::hasTable($t), P2T02_TABLES))->toBe([true, true, true, true])
        ->and(array_keys(p2t02Partitions()))->toBe(['price_points_y2026m10']);
    foreach (P2T02_TABLES as $table) {
        expect(p2t02Constraints($table))->toBe(P2T02_CONSTRAINTS[$table]);
    }
});
