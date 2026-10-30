<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lookup indexes. Additive: indexes only, no constraint, no column.
 *
 * price_events (source, day, id): the day's events for one source in id order (the dispatch's deliverable-day read).
 * watchlist_items and portfolio_holdings (variant_key): the dispatch joins from an event's variant to the people who
 * hold or watch it; the primary keys lead with user_id, so they cannot serve that join.
 * deliveries (user_id) and (endpoint_id): the foreign-key cascades and the per-person and per-endpoint lists.
 * webhook_endpoints (user_id): the users cascade; the only index today is the partial one for the Discord endpoint.
 * deliveries (status, created_at): the retention delete reads finished rows older than a cut-off.
 *
 * Plain CREATE INDEX: a migration runs in a transaction, which CONCURRENTLY cannot.
 */
return new class extends Migration
{
    private const INDEXES = [
        'price_events_source_day_id_index' => 'price_events (source, day, id)',
        'watchlist_items_variant_key_index' => 'watchlist_items (variant_key)',
        'portfolio_holdings_variant_key_index' => 'portfolio_holdings (variant_key)',
        'deliveries_user_id_index' => 'deliveries (user_id)',
        'deliveries_endpoint_id_index' => 'deliveries (endpoint_id)',
        'deliveries_status_created_at_index' => 'deliveries (status, created_at)',
        'webhook_endpoints_user_id_index' => 'webhook_endpoints (user_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $target) {
            DB::statement("CREATE INDEX {$name} ON {$target}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
