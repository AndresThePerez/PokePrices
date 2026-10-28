<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Plan Q1-i3 T03 (B4, B5, B7): one additive migration indexes the dispatch, list, delivery and retention lookups.
// The definitions are read back from the catalog exactly as Postgres reports them; down() removes those seven and
// nothing else.

const LOOKUP_INDEXES_MIGRATION = 'migrations/2026_10_28_000001_add_lookup_indexes.php';

const LOOKUP_INDEXES_TABLES = ['price_events', 'watchlist_items', 'portfolio_holdings', 'deliveries', 'webhook_endpoints'];

const LOOKUP_INDEXES = [
    'deliveries_endpoint_id_index' => 'CREATE INDEX deliveries_endpoint_id_index ON deliveries USING btree (endpoint_id)',
    'deliveries_status_created_at_index' => 'CREATE INDEX deliveries_status_created_at_index ON deliveries USING btree (status, created_at)',
    'deliveries_user_id_index' => 'CREATE INDEX deliveries_user_id_index ON deliveries USING btree (user_id)',
    'portfolio_holdings_variant_key_index' => 'CREATE INDEX portfolio_holdings_variant_key_index ON portfolio_holdings USING btree (variant_key)',
    'price_events_source_day_id_index' => 'CREATE INDEX price_events_source_day_id_index ON price_events USING btree (source, day, id)',
    'watchlist_items_variant_key_index' => 'CREATE INDEX watchlist_items_variant_key_index ON watchlist_items USING btree (variant_key)',
    'webhook_endpoints_user_id_index' => 'CREATE INDEX webhook_endpoints_user_id_index ON webhook_endpoints USING btree (user_id)',
];

/**
 * Every index of the five tables, name => definition, with the schema prefix Postgres adds outside `public` removed.
 *
 * @return array<string, string>
 */
function lookupIndexDefinitions(): array
{
    $schema = (string) DB::selectOne('SELECT current_schema() AS s')->s;
    $rows = DB::select(<<<'SQL'
        SELECT c.relname AS name, pg_get_indexdef(i.indexrelid) AS def
        FROM pg_index i
        JOIN pg_class c ON c.oid = i.indexrelid
        JOIN pg_class t ON t.oid = i.indrelid
        WHERE t.relname IN ('price_events', 'watchlist_items', 'portfolio_holdings', 'deliveries', 'webhook_endpoints')
          AND t.relnamespace = current_schema()::regnamespace
        ORDER BY c.relname
        SQL);

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->name => str_replace(" ON {$schema}.", ' ON ', (string) $r->def)])->all();
}

it('indexes the dispatch, list, delivery and retention lookups with exactly these seven definitions', function (): void {
    $found = array_intersect_key(lookupIndexDefinitions(), LOOKUP_INDEXES);
    ksort($found);

    expect($found)->toBe(LOOKUP_INDEXES);
});

it('adds only indexes: every other index of those tables is unchanged by the migration', function (): void {
    $migration = require database_path(LOOKUP_INDEXES_MIGRATION);
    $with = lookupIndexDefinitions();

    $migration->down();
    $without = lookupIndexDefinitions();
    $migration->up();

    expect(array_diff_key($with, $without))->toBe(LOOKUP_INDEXES)
        ->and(array_diff_key($without, $with))->toBe([])
        ->and(count($without))->toBeGreaterThan(0)
        ->and(lookupIndexDefinitions())->toBe($with);
});

it('rolls back to exactly the indexes it found and applies again to the same seven', function (): void {
    $migration = require database_path(LOOKUP_INDEXES_MIGRATION);

    $migration->down();
    $afterDown = array_keys(array_intersect_key(lookupIndexDefinitions(), LOOKUP_INDEXES));
    $migration->down();
    $afterSecondDown = array_keys(array_intersect_key(lookupIndexDefinitions(), LOOKUP_INDEXES));
    $migration->up();
    $afterUp = array_intersect_key(lookupIndexDefinitions(), LOOKUP_INDEXES);
    ksort($afterUp);

    expect($afterDown)->toBe([])
        ->and($afterSecondDown)->toBe([])
        ->and($afterUp)->toBe(LOOKUP_INDEXES);
});
