<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * price_events and movement_volume (plan P2-i1 T13, D4, D5, D6, R177(4), R180a; System Design §2 outbox, §5).
 *
 * price_events is the movement outbox: one `price.moved` row per variant whose real close on the newest finalized day
 * crosses the movement rule against its D4 previous close. DetectMovement writes it inside a sync or queued finalize
 * transaction only; replay never writes, updates, deletes or truncates it, and ingestion only ever inserts (ON CONFLICT
 * DO NOTHING on the outbox key). Identity is the id plus UNIQUE (variant_key, source, day, series_epoch).
 * dispatched_at stays null in P2: dispatch, and any deliveries table, is P5. ingest_run_id records the writing run
 * without a foreign key, because runs may be cleaned up and an event is never deleted with them.
 *
 * movement_volume is derived: per (day, source, rule) the D4 comparisons of price_daily and how many cross each rule
 * of the measurement grid. Finalize rewrites the days of its recompute window, and pokeprices:prices:movement-volume
 * rewrites every day; it never reads price_events. Money and change_pct are numeric(12,2) (D5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE price_events (
                id bigint GENERATED ALWAYS AS IDENTITY,
                type text NOT NULL,
                rule text NOT NULL,
                variant_key text NOT NULL,
                card_id text NOT NULL,
                source text NOT NULL,
                day date NOT NULL,
                series_epoch integer NOT NULL,
                direction text NOT NULL,
                close numeric(12,2) NOT NULL,
                previous_close numeric(12,2) NOT NULL,
                previous_day date NOT NULL,
                change numeric(12,2) NOT NULL,
                change_pct numeric(12,2) NOT NULL,
                as_of timestamptz NOT NULL,
                previous_as_of timestamptz NOT NULL,
                ingest_run_id bigint NOT NULL,
                dispatched_at timestamptz NULL,
                CONSTRAINT price_events_pkey PRIMARY KEY (id),
                CONSTRAINT price_events_variant_key_source_day_series_epoch_unique UNIQUE (variant_key, source, day, series_epoch),
                CONSTRAINT price_events_variant_key_foreign FOREIGN KEY (variant_key) REFERENCES card_variants (variant_key),
                CONSTRAINT price_events_type_check CHECK (type = 'price.moved'),
                CONSTRAINT price_events_source_check CHECK (source = 'tcgplayer'),
                CONSTRAINT price_events_direction_check CHECK (direction IN ('up', 'down')),
                CONSTRAINT price_events_change_direction_check CHECK ((direction = 'up') = (change > 0) AND change <> 0)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE movement_volume (
                day date NOT NULL,
                source text NOT NULL,
                rule text NOT NULL,
                pct numeric(12,2) NOT NULL,
                abs numeric(12,2) NOT NULL,
                compared integer NOT NULL,
                up integer NOT NULL,
                down integer NOT NULL,
                epoch_breaks integer NOT NULL,
                filled_skipped integer NOT NULL,
                extreme integer NOT NULL,
                sub_2_previous integer NOT NULL,
                CONSTRAINT movement_volume_pkey PRIMARY KEY (day, source, rule),
                CONSTRAINT movement_volume_source_check CHECK (source = 'tcgplayer')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_volume');
        Schema::dropIfExists('price_events');
    }
};
