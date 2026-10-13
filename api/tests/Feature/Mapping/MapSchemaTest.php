<?php

declare(strict_types=1);

use App\Domain\Mapping\Models\CardVariant;
use App\Domain\Mapping\Models\MapBuild;
use App\Domain\Mapping\Models\SetAlias;
use App\Domain\Mapping\Models\SourceProductMap;
use App\Domain\Mapping\Models\SourceProductMapHistory;
use App\Domain\Mapping\Models\UnmappedSourceItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The product-map tables (plan T36, R131, review-1 rec 14). Every constraint lives in the database; these tests
// write rows through the test connection and read pg_constraint / information_schema. No price value is written.

const T36_TABLES = [
    'set_aliases',
    'card_variants',
    'source_product_map',
    'source_product_map_history',
    'map_builds',
    'unmapped_source_items',
];

const T36_MIGRATION = 'migrations/2026_10_13_000001_create_product_map_tables.php';

/** One build and two variants of base1-4, the parents every mapping row points at. */
function t36Seed(): array
{
    $build = DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11',
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00',
        'finished_at' => null,
        'counts' => '{}',
    ]);
    foreach (['unlimited', 'shadowless'] as $edition) {
        DB::table('card_variants')->insert([
            'variant_key' => "base1-4:holo:{$edition}:-",
            'card_id' => 'base1-4',
            'finish' => 'holo',
            'edition' => $edition,
            'treatment' => '-',
        ]);
    }

    return ['@build' => $build, '@variant' => 'base1-4:holo:unlimited:-', '@variant2' => 'base1-4:holo:shadowless:-'];
}

/**
 * A valid row for $table with $overrides applied. Values '@build', '@variant' and '@variant2' resolve to the
 * seeded parents. A card_variants row gets its variant_key recomputed unless the override sets one.
 */
function t36Row(string $table, array $ids, array $overrides = []): array
{
    $mapping = [
        'source' => 'tcgplayer',
        'source_group_id' => 604,
        'source_product_id' => 42382,
        'source_subtype' => 'Holofoil',
        'source_product_name' => 'Charizard',
        'source_number' => '4/102',
        'variant_key' => '@variant',
        'method' => 'number+name',
        'confidence' => '1.000',
        'name_agreement' => true,
        'series_epoch' => 1,
        'map_build_id' => '@build',
    ];
    $base = match ($table) {
        'set_aliases' => [
            'pokesearch_set_id' => 'base1',
            'source' => 'tcgplayer',
            'source_group_id' => 604,
            'role' => 'primary',
            'number_rule' => null,
            'method' => 'manual',
            'unlimited_means' => 'unlimited',
            'note' => 'groups.json of 2026-10-11',
        ],
        'card_variants' => [
            'card_id' => 'base1-58',
            'finish' => 'normal',
            'edition' => '-',
            'treatment' => '-',
        ],
        'source_product_map' => $mapping,
        'source_product_map_history' => $mapping + ['ended_at' => '2026-10-12 09:00:00+00', 'end_reason' => 'remapped'],
        'map_builds' => [
            'snapshot_day' => '2026-10-11',
            'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
            'started_at' => '2026-10-12 08:00:00+00',
            'counts' => '{"cards": 0}',
        ],
        'unmapped_source_items' => [
            'map_build_id' => '@build',
            'source' => 'tcgplayer',
            'source_group_id' => 604,
            'source_product_id' => 99999,
            'source_subtype' => 'Normal',
            'product_name' => 'Base Set Booster Pack',
            'number' => null,
            'reason' => 'non_card',
        ],
    };
    $row = array_merge($base, $overrides);
    if ($table === 'card_variants' && ! array_key_exists('variant_key', $overrides)) {
        $row['variant_key'] = implode(':', [$row['card_id'], $row['finish'], $row['edition'], $row['treatment']]);
    }

    return array_map(fn ($v) => is_string($v) && array_key_exists($v, $ids) ? $ids[$v] : $v, $row);
}

/** Runs $insert and returns the QueryException it raised, or null. */
function t36Error(Closure $insert): ?QueryException
{
    try {
        DB::transaction($insert);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

/** "name:type:NULL|NOT NULL" for each column of $table, in column order. */
function t36Columns(string $table): array
{
    return array_map(
        fn (object $c): string => "{$c->column_name}:{$c->data_type}:".($c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL'),
        DB::select(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        ),
    );
}

/** conname => "type:col1,col2" for the constraints of $table (p, u, f, c). */
function t36Constraints(string $table): array
{
    $rows = DB::select(
        "SELECT c.conname, c.contype,
                string_agg(a.attname, ',' ORDER BY k.ord) AS cols
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

test('the migration creates the six product-map tables', function (): void {
    foreach (T36_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
    }
});

test('each table has exactly the planned columns, types and nullability', function (string $table, array $columns): void {
    expect(t36Columns($table))->toBe($columns);
})->with([
    'set_aliases' => ['set_aliases', [
        'id:bigint:NOT NULL',
        'pokesearch_set_id:text:NOT NULL',
        'source:text:NOT NULL',
        'source_group_id:integer:NOT NULL',
        'role:text:NOT NULL',
        'number_rule:text:NULL',
        'method:text:NOT NULL',
        'unlimited_means:text:NULL',
        'note:text:NOT NULL',
    ]],
    'card_variants' => ['card_variants', [
        'variant_key:text:NOT NULL',
        'card_id:text:NOT NULL',
        'finish:text:NOT NULL',
        'edition:text:NOT NULL',
        'treatment:text:NOT NULL',
        'catalog_status:text:NOT NULL',
        'catalog_seed_ref:text:NULL',
        'catalog_checked_at:timestamp with time zone:NULL',
    ]],
    'source_product_map' => ['source_product_map', [
        'id:bigint:NOT NULL',
        'source:text:NOT NULL',
        'source_group_id:integer:NOT NULL',
        'source_product_id:integer:NOT NULL',
        'source_subtype:text:NOT NULL',
        'source_product_name:text:NOT NULL',
        'source_number:text:NULL',
        'variant_key:text:NOT NULL',
        'method:text:NOT NULL',
        'confidence:numeric:NOT NULL',
        'name_agreement:boolean:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'map_build_id:bigint:NOT NULL',
    ]],
    'source_product_map_history' => ['source_product_map_history', [
        'id:bigint:NOT NULL',
        'source:text:NOT NULL',
        'source_group_id:integer:NOT NULL',
        'source_product_id:integer:NOT NULL',
        'source_subtype:text:NOT NULL',
        'source_product_name:text:NOT NULL',
        'source_number:text:NULL',
        'variant_key:text:NOT NULL',
        'method:text:NOT NULL',
        'confidence:numeric:NOT NULL',
        'name_agreement:boolean:NOT NULL',
        'series_epoch:integer:NOT NULL',
        'map_build_id:bigint:NOT NULL',
        'ended_at:timestamp with time zone:NOT NULL',
        'end_reason:text:NOT NULL',
    ]],
    'map_builds' => ['map_builds', [
        'id:bigint:NOT NULL',
        'snapshot_day:date:NOT NULL',
        'dataset_ref:text:NOT NULL',
        'seed_ref:text:NULL',
        'started_at:timestamp with time zone:NOT NULL',
        'finished_at:timestamp with time zone:NULL',
        'counts:jsonb:NOT NULL',
    ]],
    'unmapped_source_items' => ['unmapped_source_items', [
        'id:bigint:NOT NULL',
        'map_build_id:bigint:NOT NULL',
        'source:text:NOT NULL',
        'source_group_id:integer:NOT NULL',
        'source_product_id:integer:NOT NULL',
        'source_subtype:text:NOT NULL',
        'product_name:text:NOT NULL',
        'number:text:NULL',
        'reason:text:NOT NULL',
        'candidates:jsonb:NOT NULL',
        'resolved_at:timestamp with time zone:NULL',
        'resolution:text:NULL',
    ]],
]);

test('confidence is numeric(4,3)', function (): void {
    foreach (['source_product_map', 'source_product_map_history'] as $table) {
        $col = DB::selectOne(
            "SELECT numeric_precision, numeric_scale FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'confidence'",
            [$table],
        );
        expect([$col->numeric_precision, $col->numeric_scale])->toBe([4, 3]);
    }
});

test('no column of any product-map table holds a price (EC-P1-18)', function (): void {
    // Rescoped by P2-i1 T02: the price tables are P2's; the invariant P1 owns is that the map tables hold no price.
    $mapTables = [...T36_TABLES, 'unmapped_cards'];
    $priceColumns = fn (): array => DB::select(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ANY (?::text[])
           AND (column_name ILIKE '%price%' OR column_name IN ('market', 'low', 'mid', 'high', 'direct_low', 'close'))
         ORDER BY table_name, column_name",
        ['{'.implode(',', $mapTables).'}'],
    );
    $mapColumns = DB::selectOne(
        'SELECT count(*) AS n FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN (?, ?, ?, ?, ?, ?)',
        T36_TABLES,
    );
    $clean = $priceColumns();

    // The query must see a price-like column added to a map table; the savepoint rolls the probe back.
    $probed = [];
    try {
        DB::transaction(function () use ($priceColumns, &$probed): void {
            DB::statement('ALTER TABLE card_variants ADD COLUMN close numeric(12,2)');
            DB::statement('ALTER TABLE unmapped_cards ADD COLUMN last_price numeric(12,2)');
            $probed = array_map(fn (object $c): string => "{$c->table_name}.{$c->column_name}", $priceColumns());

            throw new RuntimeException('roll the probe back');
        });
    } catch (RuntimeException) {
    }

    expect($clean)->toBe([])
        ->and($probed)->toBe(['card_variants.close', 'unmapped_cards.last_price'])
        ->and($priceColumns())->toBe([])
        ->and($mapColumns->n)->toBe(64);
});

test('every planned constraint is a plain constraint in pg_constraint', function (string $table, array $expected): void {
    expect(t36Constraints($table))->toBe($expected);
})->with([
    'set_aliases' => ['set_aliases', [
        'set_aliases_method_check' => 'c:method',
        'set_aliases_pkey' => 'p:id',
        'set_aliases_role_check' => 'c:role',
        'set_aliases_source_check' => 'c:source',
        'set_aliases_source_group_id_check' => 'c:source_group_id',
        'set_aliases_source_set_group_unique' => 'u:source,pokesearch_set_id,source_group_id',
        'set_aliases_unlimited_means_check' => 'c:unlimited_means',
    ]],
    'card_variants (no foreign key)' => ['card_variants', [
        'card_variants_card_finish_edition_treatment_unique' => 'u:card_id,finish,edition,treatment',
        'card_variants_card_id_check' => 'c:card_id',
        'card_variants_catalog_status_check' => 'c:catalog_status',
        'card_variants_edition_check' => 'c:edition',
        'card_variants_finish_check' => 'c:finish',
        'card_variants_pkey' => 'p:variant_key',
        'card_variants_treatment_check' => 'c:treatment',
        'card_variants_variant_key_check' => 'c:variant_key,card_id,finish,edition,treatment',
    ]],
    'source_product_map' => ['source_product_map', [
        'source_product_map_confidence_check' => 'c:confidence',
        'source_product_map_map_build_id_foreign' => 'f:map_build_id',
        'source_product_map_method_check' => 'c:method',
        'source_product_map_name_agreement_check' => 'c:method,name_agreement',
        'source_product_map_pkey' => 'p:id',
        'source_product_map_series_epoch_check' => 'c:series_epoch',
        'source_product_map_source_check' => 'c:source',
        'source_product_map_source_product_id_check' => 'c:source_product_id',
        'source_product_map_source_product_subtype_unique' => 'u:source,source_product_id,source_subtype',
        'source_product_map_source_subtype_check' => 'c:source_subtype',
        'source_product_map_variant_key_foreign' => 'f:variant_key',
        'source_product_map_variant_source_unique' => 'u:variant_key,source',
    ]],
    'source_product_map_history' => ['source_product_map_history', [
        'source_product_map_history_confidence_check' => 'c:confidence',
        'source_product_map_history_map_build_id_foreign' => 'f:map_build_id',
        'source_product_map_history_method_check' => 'c:method',
        'source_product_map_history_name_agreement_check' => 'c:method,name_agreement',
        'source_product_map_history_pkey' => 'p:id',
        'source_product_map_history_series_epoch_check' => 'c:series_epoch',
        'source_product_map_history_source_check' => 'c:source',
        'source_product_map_history_source_product_id_check' => 'c:source_product_id',
        'source_product_map_history_source_subtype_check' => 'c:source_subtype',
        'source_product_map_history_variant_key_foreign' => 'f:variant_key',
        'source_product_map_history_variant_source_epoch_unique' => 'u:variant_key,source,series_epoch',
    ]],
    'map_builds' => ['map_builds', [
        'map_builds_pkey' => 'p:id',
    ]],
    'unmapped_source_items' => ['unmapped_source_items', [
        'unmapped_source_items_build_source_product_subtype_unique' => 'u:map_build_id,source,source_product_id,source_subtype',
        'unmapped_source_items_map_build_id_foreign' => 'f:map_build_id',
        'unmapped_source_items_pkey' => 'p:id',
        'unmapped_source_items_reason_check' => 'c:reason',
        'unmapped_source_items_source_check' => 'c:source',
    ]],
])->group('DC3');

test('the foreign keys point at map_builds(id) and card_variants(variant_key)', function (): void {
    $fks = DB::select(
        "SELECT c.conname, c.confrelid::regclass::text AS target, a.attname AS target_col
         FROM pg_constraint c
         JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = c.confkey[1]
         WHERE c.contype = 'f' AND c.conrelid::regclass::text = ANY (?)
         ORDER BY c.conname",
        ['{'.implode(',', T36_TABLES).'}'],
    );

    expect(array_map(fn (object $r): string => "{$r->conname}->{$r->target}.{$r->target_col}", $fks))->toBe([
        'source_product_map_history_map_build_id_foreign->map_builds.id',
        'source_product_map_history_variant_key_foreign->card_variants.variant_key',
        'source_product_map_map_build_id_foreign->map_builds.id',
        'source_product_map_variant_key_foreign->card_variants.variant_key',
        'unmapped_source_items_map_build_id_foreign->map_builds.id',
    ]);
});

test('the CHECK constraints reject out-of-domain values', function (string $table, array $overrides, string $constraint): void {
    $ids = t36Seed();
    $error = t36Error(fn () => DB::table($table)->insert(t36Row($table, $ids, $overrides)));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23514')
        ->and($error->getMessage())->toContain("violates check constraint \"{$constraint}\"");
})->with([
    'set_aliases source' => ['set_aliases', ['source' => 'cardmarket'], 'set_aliases_source_check'],
    'set_aliases group 0' => ['set_aliases', ['source_group_id' => 0], 'set_aliases_source_group_id_check'],
    'set_aliases role none' => ['set_aliases', ['role' => 'none'], 'set_aliases_role_check'],
    'set_aliases method heuristic' => ['set_aliases', ['method' => 'heuristic'], 'set_aliases_method_check'],
    'set_aliases unlimited_means 1st' => ['set_aliases', ['unlimited_means' => '1st'], 'set_aliases_unlimited_means_check'],
    'card_variants key without treatment' => ['card_variants', ['variant_key' => 'base1-58:normal:-'], 'card_variants_variant_key_check'],
    'card_variants key out of order' => ['card_variants', ['variant_key' => 'base1-58:-:normal:-'], 'card_variants_variant_key_check'],
    'card_variants card_id leading dash' => ['card_variants', ['card_id' => '-base1'], 'card_variants_card_id_check'],
    'card_variants card_id with space' => ['card_variants', ['card_id' => 'base1 58'], 'card_variants_card_id_check'],
    'card_variants card_id 129 chars' => ['card_variants', ['card_id' => str_repeat('a', 129)], 'card_variants_card_id_check'],
    'card_variants finish foil' => ['card_variants', ['finish' => 'foil'], 'card_variants_finish_check'],
    'card_variants edition first' => ['card_variants', ['edition' => 'first'], 'card_variants_edition_check'],
    'card_variants treatment upper case' => ['card_variants', ['treatment' => 'Pokeball'], 'card_variants_treatment_check'],
    'card_variants treatment double dash' => ['card_variants', ['treatment' => 'cracked--ice'], 'card_variants_treatment_check'],
    'card_variants treatment empty' => ['card_variants', ['treatment' => ''], 'card_variants_treatment_check'],
    'card_variants catalog_status missing' => ['card_variants', ['catalog_status' => 'missing'], 'card_variants_catalog_status_check'],
    'map source ebay' => ['source_product_map', ['source' => 'ebay'], 'source_product_map_source_check'],
    'map product 0' => ['source_product_map', ['source_product_id' => 0], 'source_product_map_source_product_id_check'],
    'map subtype Foil' => ['source_product_map', ['source_subtype' => 'Foil'], 'source_product_map_source_subtype_check'],
    'map method guess' => ['source_product_map', ['method' => 'guess'], 'source_product_map_method_check'],
    'map confidence above 1' => ['source_product_map', ['confidence' => '1.5'], 'source_product_map_confidence_check'],
    'map confidence below 0' => ['source_product_map', ['confidence' => '-0.001'], 'source_product_map_confidence_check'],
    'map number without name agreement' => ['source_product_map', ['method' => 'number', 'name_agreement' => false], 'source_product_map_name_agreement_check'],
    'map epoch 0' => ['source_product_map', ['series_epoch' => 0], 'source_product_map_series_epoch_check'],
    'history number without name agreement' => ['source_product_map_history', ['method' => 'number', 'name_agreement' => false], 'source_product_map_history_name_agreement_check'],
    'history epoch 0' => ['source_product_map_history', ['series_epoch' => 0], 'source_product_map_history_series_epoch_check'],
    'history subtype Foil' => ['source_product_map_history', ['source_subtype' => 'Foil'], 'source_product_map_history_source_subtype_check'],
    'unmapped reason other' => ['unmapped_source_items', ['reason' => 'other'], 'unmapped_source_items_reason_check'],
    'unmapped source ebay' => ['unmapped_source_items', ['source' => 'ebay'], 'unmapped_source_items_source_check'],
]);

test('every planned domain value is accepted', function (string $table, array $overrides): void {
    $ids = t36Seed();
    $before = DB::table($table)->count();
    DB::table($table)->insert(t36Row($table, $ids, $overrides));

    expect(DB::table($table)->count())->toBe($before + 1);
})->with([
    'set_aliases edition-split shadowless' => ['set_aliases', ['source_group_id' => 1663, 'role' => 'edition-split', 'unlimited_means' => 'shadowless']],
    'set_aliases secondary number rule' => ['set_aliases', ['source_group_id' => 1840, 'role' => 'secondary', 'number_rule' => '8', 'unlimited_means' => null]],
    'set_aliases name' => ['set_aliases', ['method' => 'name']],
    'set_aliases ptcgo' => ['set_aliases', ['method' => 'ptcgo']],
    'card_variants reverse 1st pokeball' => ['card_variants', ['finish' => 'reverse', 'edition' => '1st', 'treatment' => 'pokeball']],
    'card_variants shadowless' => ['card_variants', ['edition' => 'shadowless']],
    'card_variants unlimited cracked-ice' => ['card_variants', ['edition' => 'unlimited', 'treatment' => 'cracked-ice']],
    'card_variants stamp-e-league-winner' => ['card_variants', ['treatment' => 'stamp-e-league-winner']],
    'card_variants card_id with bang' => ['card_variants', ['card_id' => 'pop5-!']],
    'card_variants card_id 128 chars' => ['card_variants', ['card_id' => 'a'.str_repeat('B.9_?-', 21).'x']],
    'card_variants ok' => ['card_variants', ['catalog_status' => 'ok', 'catalog_seed_ref' => 'abc1234', 'catalog_checked_at' => '2026-10-12 08:00:00+00']],
    'card_variants catalog_missing' => ['card_variants', ['catalog_status' => 'catalog_missing']],
    'map number with name agreement' => ['source_product_map', ['method' => 'number', 'name_agreement' => true]],
    'map number+name without agreement' => ['source_product_map', ['method' => 'number+name', 'name_agreement' => false]],
    'map name_tiebreak' => ['source_product_map', ['method' => 'name_tiebreak']],
    'map alias' => ['source_product_map', ['method' => 'alias']],
    'map manual' => ['source_product_map', ['method' => 'manual', 'confidence' => '0']],
    'map golden' => ['source_product_map', ['method' => 'golden', 'source_subtype' => 'Unlimited Holofoil']],
    'map subtype 1st Edition Holofoil' => ['source_product_map', ['source_subtype' => '1st Edition Holofoil']],
    'map subtype Reverse Holofoil' => ['source_product_map', ['source_subtype' => 'Reverse Holofoil', 'source_number' => null]],
    'history epoch 2' => ['source_product_map_history', ['series_epoch' => 2]],
    'unmapped no_set_alias' => ['unmapped_source_items', ['reason' => 'no_set_alias']],
    'unmapped no_number_match' => ['unmapped_source_items', ['reason' => 'no_number_match']],
    'unmapped name_disagreement' => ['unmapped_source_items', ['reason' => 'name_disagreement']],
    'unmapped ambiguous' => ['unmapped_source_items', ['reason' => 'ambiguous']],
    'unmapped duplicate_target' => ['unmapped_source_items', ['reason' => 'duplicate_target']],
    'unmapped unknown_tag' => ['unmapped_source_items', ['reason' => 'unknown_tag']],
    'map_builds with seed and finish' => ['map_builds', ['seed_ref' => 'abc1234', 'finished_at' => '2026-10-12 08:05:00+00']],
]);

test('treatment defaults to "-", catalog_status to unverified, subtype to empty and candidates to []', function (): void {
    $ids = t36Seed();
    DB::table('card_variants')->insert(['variant_key' => 'base2-1:holo:-:-', 'card_id' => 'base2-1', 'finish' => 'holo', 'edition' => '-']);
    $row = t36Row('unmapped_source_items', $ids);
    unset($row['source_subtype']);
    DB::table('unmapped_source_items')->insert($row);

    $variant = DB::table('card_variants')->where('variant_key', 'base2-1:holo:-:-')->first();
    $item = DB::table('unmapped_source_items')->where('source_product_id', 99999)->first();

    expect($variant->treatment)->toBe('-')
        ->and($variant->catalog_status)->toBe('unverified')
        ->and($variant->catalog_seed_ref)->toBeNull()
        ->and($item->source_subtype)->toBe('')
        ->and(json_decode($item->candidates, true))->toBe([]);
});

test('the foreign keys reject an unknown build or variant', function (string $table, array $overrides, string $constraint): void {
    $ids = t36Seed();
    $error = t36Error(fn () => DB::table($table)->insert(t36Row($table, $ids, $overrides)));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23503')
        ->and($error->getMessage())->toContain("violates foreign key constraint \"{$constraint}\"");
})->with([
    'map variant' => ['source_product_map', ['variant_key' => 'base1-4:normal:-:-'], 'source_product_map_variant_key_foreign'],
    'map build' => ['source_product_map', ['map_build_id' => 999999999], 'source_product_map_map_build_id_foreign'],
    'history variant' => ['source_product_map_history', ['variant_key' => 'base1-4:normal:-:-'], 'source_product_map_history_variant_key_foreign'],
    'history build' => ['source_product_map_history', ['map_build_id' => 999999999], 'source_product_map_history_map_build_id_foreign'],
    'unmapped build' => ['unmapped_source_items', ['map_build_id' => 999999999], 'unmapped_source_items_map_build_id_foreign'],
]);

test('source_product_map_history is append-only: UPDATE and DELETE are refused', function (): void {
    $ids = t36Seed();
    DB::table('source_product_map_history')->insert(t36Row('source_product_map_history', $ids));

    $update = t36Error(fn () => DB::table('source_product_map_history')->update(['end_reason' => 'edited']));
    $delete = t36Error(fn () => DB::table('source_product_map_history')->delete());

    expect($update)->toBeInstanceOf(QueryException::class)
        ->and($update->getCode())->toBe('23001')
        ->and($update->getMessage())->toContain('source_product_map_history is append-only')
        ->and($delete)->toBeInstanceOf(QueryException::class)
        ->and($delete->getCode())->toBe('23001')
        ->and(DB::table('source_product_map_history')->value('end_reason'))->toBe('remapped');
});

test('each UNIQUE raises SQLSTATE 23505 inside DB::transaction and the savepoint keeps the transaction alive', function (string $table, array $first, array $duplicate, string $constraint): void {
    $ids = t36Seed();

    [$error, $count] = DB::transaction(function () use ($table, $ids, $first, $duplicate): array {
        DB::table($table)->insert(t36Row($table, $ids, $first));
        $error = null;
        try {
            DB::transaction(fn () => DB::table($table)->insert(t36Row($table, $ids, $duplicate)));
        } catch (QueryException $e) {
            $error = $e;
        }

        // Only a rolled-back savepoint lets the outer transaction run another statement (no 25P02).
        return [$error, DB::table($table)->count()];
    });

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23505')
        ->and($error->getMessage())->toMatch("/violates unique constraint \"(?:{$constraint})\"/")
        ->and($count)->toBe($table === 'card_variants' ? 3 : 1);
})->with([
    'set_aliases (source, set, group)' => ['set_aliases', [], ['note' => 'second', 'role' => 'secondary'], 'set_aliases_source_set_group_unique'],
    // Equal parts imply an equal variant_key (its CHECK), so the primary key may fire first; both are 23505.
    'card_variants (card, finish, edition, treatment)' => ['card_variants', [], [], 'card_variants_card_finish_edition_treatment_unique|card_variants_pkey'],
    'source_product_map (source, product, subtype)' => ['source_product_map', [], ['variant_key' => '@variant2', 'source_product_name' => 'Charizard (Shadowless)'], 'source_product_map_source_product_subtype_unique'],
    'source_product_map (variant, source)' => ['source_product_map', [], ['source_product_id' => 106999], 'source_product_map_variant_source_unique'],
    'source_product_map_history (variant, source, epoch)' => ['source_product_map_history', [], ['source_product_id' => 106999, 'end_reason' => 'other'], 'source_product_map_history_variant_source_epoch_unique'],
    'unmapped_source_items (build, source, product, subtype)' => ['unmapped_source_items', [], ['reason' => 'ambiguous'], 'unmapped_source_items_build_source_product_subtype_unique'],
])->group('DC3');

test('a colon in treatment violates its CHECK inside DB::transaction', function (string $treatment): void {
    t36Seed();

    [$error, $count] = DB::transaction(function () use ($treatment): array {
        $error = null;
        try {
            DB::transaction(fn () => DB::table('card_variants')->insert([
                'variant_key' => "base1-4:holo:-:{$treatment}",
                'card_id' => 'base1-4',
                'finish' => 'holo',
                'edition' => '-',
                'treatment' => $treatment,
            ]));
        } catch (QueryException $e) {
            $error = $e;
        }

        return [$error, DB::table('card_variants')->count()];
    });

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23514')
        ->and($error->getMessage())->toContain('violates check constraint "card_variants_treatment_check"')
        ->and($count)->toBe(2);
})->with(['stamp:prerelease', ':', 'pokeball:', '-:-'])->group('DC3');

test('the models read and write their tables with typed casts', function (): void {
    $build = MapBuild::create([
        'snapshot_day' => '2026-10-11',
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'started_at' => '2026-10-12 08:00:00+00',
        'counts' => ['cards' => 1, 'by_reason' => ['non_card' => 1]],
    ]);
    $variant = CardVariant::create([
        'variant_key' => 'base1-4:holo:unlimited:-',
        'card_id' => 'base1-4',
        'finish' => 'holo',
        'edition' => 'unlimited',
        'treatment' => '-',
        'catalog_checked_at' => '2026-10-12 08:00:00+00',
    ]);
    $alias = SetAlias::create(t36Row('set_aliases', []));
    $map = SourceProductMap::create(t36Row('source_product_map', ['@build' => $build->id, '@variant' => $variant->variant_key]));
    $history = SourceProductMapHistory::create(t36Row('source_product_map_history', ['@build' => $build->id, '@variant' => $variant->variant_key]));
    $item = UnmappedSourceItem::create(t36Row('unmapped_source_items', ['@build' => $build->id]) + ['candidates' => ['base1-4']]);

    $build = MapBuild::findOrFail($build->id);
    $variant = CardVariant::findOrFail('base1-4:holo:unlimited:-');
    $map = SourceProductMap::findOrFail($map->id);
    $history = SourceProductMapHistory::findOrFail($history->id);
    $item = UnmappedSourceItem::findOrFail($item->id);

    expect($build->counts)->toBe(['cards' => 1, 'by_reason' => ['non_card' => 1]])
        ->and($build->snapshot_day)->toBeInstanceOf(CarbonImmutable::class)
        ->and($build->snapshot_day->toDateString())->toBe('2026-10-11')
        ->and($build->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($variant->getKey())->toBe('base1-4:holo:unlimited:-')
        ->and($variant->catalog_checked_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(SetAlias::findOrFail($alias->id)->source_group_id)->toBe(604)
        ->and($map->name_agreement)->toBeTrue()
        ->and($map->confidence)->toBe('1.000')
        ->and($map->series_epoch)->toBe(1)
        ->and($map->map_build_id)->toBe($build->id)
        ->and($history->ended_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($item->candidates)->toBe(['base1-4'])
        ->and($item->resolved_at)->toBeNull();
});

test('the migration rolls back and re-applies cleanly', function (): void {
    // unmapped_cards (a later migration) references map_builds, so it rolls back first and re-applies last,
    // in the order migrate:rollback and migrate use. The P2 price migrations reference card_variants, so they
    // roll back before it (reverse filename order) and re-apply after it (P2-i1 T02, review F1).
    $migration = require database_path(T36_MIGRATION);
    $cards = require database_path('migrations/2026_10_13_000002_create_unmapped_cards_table.php');
    $priceFiles = glob(database_path('migrations/2026_10_14_*.php')) ?: [];
    sort($priceFiles);
    $prices = array_map(fn (string $f): object => require $f, $priceFiles);

    foreach (array_reverse($prices) as $price) {
        $price->down();
    }
    $cards->down();
    $migration->down();
    $afterDown = array_map(fn (string $t): bool => Schema::hasTable($t), T36_TABLES);
    $guard = DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'source_product_map_history_append_only'");

    $migration->up();
    $cards->up();
    foreach ($prices as $price) {
        $price->up();
    }
    $afterUp = array_map(fn (string $t): bool => Schema::hasTable($t), T36_TABLES);
    expect(Schema::hasTable('unmapped_cards'))->toBeTrue()
        ->and($priceFiles)->not->toBe([])
        ->and(Schema::hasTable('price_points') && Schema::hasTable('price_daily'))->toBeTrue();

    expect($afterDown)->toBe(array_fill(0, 6, false))
        ->and($guard->n)->toBe(0)
        ->and($afterUp)->toBe(array_fill(0, 6, true))
        ->and(t36Constraints('source_product_map'))->toHaveKeys([
            'source_product_map_source_product_subtype_unique',
            'source_product_map_variant_source_unique',
            'source_product_map_name_agreement_check',
        ]);
});

// unmapped_cards (T40 fix round 1): a dataset card no priced item of the build reached is listed per build, so the
// coverage audit sees every card as mapped or listed for review. A table of its own: unmapped_source_items keeps
// its product columns NOT NULL.

test('unmapped_cards has exactly its planned columns and named constraints', function (): void {
    expect(t36Columns('unmapped_cards'))->toBe([
        'id:bigint:NOT NULL',
        'map_build_id:bigint:NOT NULL',
        'source:text:NOT NULL',
        'card_id:text:NOT NULL',
        'reason:text:NOT NULL',
        'resolved_at:timestamp with time zone:NULL',
        'resolution:text:NULL',
    ])->and(t36Constraints('unmapped_cards'))->toBe([
        'unmapped_cards_build_source_card_unique' => 'u:map_build_id,source,card_id',
        'unmapped_cards_card_id_check' => 'c:card_id',
        'unmapped_cards_map_build_id_foreign' => 'f:map_build_id',
        'unmapped_cards_pkey' => 'p:id',
        'unmapped_cards_reason_check' => 'c:reason',
        'unmapped_cards_source_check' => 'c:source',
    ]);
})->group('DC3');

test('unmapped_cards accepts its two reasons and refuses another reason, a bad card id and an unknown build', function (): void {
    $ids = t36Seed();
    $row = fn (array $o): array => array_merge(['map_build_id' => $ids['@build'], 'source' => 'tcgplayer', 'card_id' => 'svp-105', 'reason' => 'no_priced_product'], $o);
    DB::table('unmapped_cards')->insert($row([]));
    DB::table('unmapped_cards')->insert($row(['card_id' => 'fut20-1', 'reason' => 'no_set_alias']));

    $reason = t36Error(fn () => DB::table('unmapped_cards')->insert($row(['card_id' => 'svp-106', 'reason' => 'other'])));
    $cardId = t36Error(fn () => DB::table('unmapped_cards')->insert($row(['card_id' => 'svp:106'])));
    $source = t36Error(fn () => DB::table('unmapped_cards')->insert($row(['card_id' => 'svp-107', 'source' => 'ebay'])));
    $build = t36Error(fn () => DB::table('unmapped_cards')->insert($row(['card_id' => 'svp-108', 'map_build_id' => 999999999])));

    expect(DB::table('unmapped_cards')->count())->toBe(2)
        ->and($reason?->getMessage())->toContain('unmapped_cards_reason_check')
        ->and($cardId?->getMessage())->toContain('unmapped_cards_card_id_check')
        ->and($source?->getMessage())->toContain('unmapped_cards_source_check')
        ->and($build?->getMessage())->toContain('unmapped_cards_map_build_id_foreign');
});

test('unmapped_cards (build, source, card) raises SQLSTATE 23505 inside DB::transaction', function (): void {
    $ids = t36Seed();
    $row = ['map_build_id' => $ids['@build'], 'source' => 'tcgplayer', 'card_id' => 'svp-105', 'reason' => 'no_priced_product'];
    DB::table('unmapped_cards')->insert($row);

    $error = t36Error(fn () => DB::table('unmapped_cards')->insert(['reason' => 'no_set_alias'] + $row));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->getCode())->toBe('23505')
        ->and($error?->getMessage())->toContain('unmapped_cards_build_source_card_unique')
        ->and(DB::table('unmapped_cards')->count())->toBe(1);
})->group('DC3');
