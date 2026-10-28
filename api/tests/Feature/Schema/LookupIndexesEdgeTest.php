<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Plan Q1-i3 T03 review: edge cases of the lookup-index migration that the exact-definition pins do not state.

const LIE_MIGRATION = 'migrations/2026_10_28_000001_add_lookup_indexes.php';

const LIE_P5_MIGRATION = 'migrations/2026_10_16_000001_create_alert_delivery_tables.php';

const LIE_NAMES = [
    'price_events_source_day_id_index', 'watchlist_items_variant_key_index', 'portfolio_holdings_variant_key_index',
    'deliveries_user_id_index', 'deliveries_endpoint_id_index', 'deliveries_status_created_at_index', 'webhook_endpoints_user_id_index',
];

/** @return array<string, object> lookup index name => its pg_index facts, for the seven names present now */
function lieFacts(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT c.relname AS name, i.indrelid AS tbl, i.indkey::text AS keys, i.indisunique AS uniq, i.indisvalid AS valid,
               i.indisready AS ready, (i.indpred IS NOT NULL) AS partial, (i.indexprs IS NOT NULL) AS expr, am.amname AS method
        FROM pg_index i
        JOIN pg_class c ON c.oid = i.indexrelid
        JOIN pg_am am ON am.oid = c.relam
        WHERE c.relname = ANY (?::text[]) AND c.relnamespace = current_schema()::regnamespace
        ORDER BY c.relname
        SQL, ['{'.implode(',', LIE_NAMES).'}']);

    return collect($rows)->keyBy('name')->all();
}

it('adds no index that repeats the key columns of an index its table already had', function (): void {
    $migration = require database_path(LIE_MIGRATION);
    $migration->down();
    $before = DB::select(<<<'SQL'
        SELECT i.indrelid::regclass::text AS tbl, i.indkey::text AS keys, coalesce(pg_get_expr(i.indpred, i.indrelid), '') AS pred
        FROM pg_index i
        WHERE i.indrelid::regclass::text IN ('price_events', 'watchlist_items', 'portfolio_holdings', 'deliveries', 'webhook_endpoints')
        SQL);
    $migration->up();

    $existing = collect($before)->map(fn (object $r): string => "{$r->tbl}|{$r->keys}|{$r->pred}")->all();
    $added = collect(DB::select(<<<'SQL'
        SELECT i.indrelid::regclass::text AS tbl, i.indkey::text AS keys, coalesce(pg_get_expr(i.indpred, i.indrelid), '') AS pred
        FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
        WHERE c.relname = ANY (?::text[])
        SQL, ['{'.implode(',', LIE_NAMES).'}']))->map(fn (object $r): string => "{$r->tbl}|{$r->keys}|{$r->pred}")->all();

    expect($added)->toHaveCount(7)
        ->and(array_intersect($added, $existing))->toBe([])
        ->and(count(array_unique($added)))->toBe(7);
});

it('creates each of the seven as a valid, ready, plain, non-unique, non-partial btree on columns only', function (): void {
    $facts = lieFacts();

    expect(array_keys($facts))->toHaveCount(7);
    foreach ($facts as $name => $f) {
        expect([$name, $f->method, (bool) $f->uniq, (bool) $f->valid, (bool) $f->ready, (bool) $f->partial, (bool) $f->expr])
            ->toBe([$name, 'btree', false, true, true, false, false]);
    }
});

it('rolls back cleanly after the tables it indexes were already dropped, and re-applies on the recreated tables', function (): void {
    $indexes = require database_path(LIE_MIGRATION);
    $p5 = require database_path(LIE_P5_MIGRATION);

    // Dropping the tables takes their indexes with them; a later rollback of this migration must not throw on the missing ones.
    $p5->down();
    $goneAfterTables = array_keys(lieFacts());
    $indexes->down();
    $p5->up();
    $indexes->up();
    $again = array_keys(lieFacts());
    sort($again);
    $expected = LIE_NAMES;
    sort($expected);

    expect($goneAfterTables)->toBe(['portfolio_holdings_variant_key_index', 'price_events_source_day_id_index', 'watchlist_items_variant_key_index'])
        ->and($again)->toBe($expected);
});
