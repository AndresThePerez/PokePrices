<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * price_movers (plan P2-i1 T12, D7; System Design §4): the ranked movers of each collected day, so "top movers" is an
 * index scan. For day D a variant with a real close on D and a D4 previous close of at least
 * config('pricing.movers.min_previous_close') ranks `up` (change > 0) or `down` (change < 0) by abs(change_pct) desc,
 * abs(change) desc, variant_key asc, top config('pricing.movers.limit') per direction. The window is the previous
 * close. It is derived: Movers::rebuild() rewrites the days of each ingest's recompute window inside finalize, so it
 * holds no wall-clock or run column. Money and change_pct are numeric(12,2) (D5). It stays outside PriceDigest
 * (R180b); the replay's movers_check covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE price_movers (
                day date NOT NULL,
                source text NOT NULL,
                direction text NOT NULL,
                rank integer NOT NULL,
                variant_key text NOT NULL,
                card_id text NOT NULL,
                series_epoch integer NOT NULL,
                close numeric(12,2) NOT NULL,
                previous_close numeric(12,2) NOT NULL,
                previous_day date NOT NULL,
                change numeric(12,2) NOT NULL,
                change_pct numeric(12,2) NOT NULL,
                CONSTRAINT price_movers_pkey PRIMARY KEY (day, source, direction, rank),
                CONSTRAINT price_movers_day_source_direction_variant_key_unique UNIQUE (day, source, direction, variant_key),
                CONSTRAINT price_movers_variant_key_foreign FOREIGN KEY (variant_key) REFERENCES card_variants (variant_key),
                CONSTRAINT price_movers_source_check CHECK (source = 'tcgplayer'),
                CONSTRAINT price_movers_direction_check CHECK (direction IN ('up', 'down')),
                CONSTRAINT price_movers_rank_check CHECK (rank >= 1),
                CONSTRAINT price_movers_change_direction_check CHECK ((direction = 'up') = (change > 0)),
                CONSTRAINT price_movers_change_check CHECK (change <> 0)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_movers');
    }
};
