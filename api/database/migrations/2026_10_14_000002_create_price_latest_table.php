<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * price_latest: one row per (variant, source), the newest real close with its previous
 * close and change. It is derived: LatestPrices::rebuild() recomputes it from price_daily inside every ingest
 * finalize, so it holds no wall-clock or run column and identical price_daily gives identical rows. Money
 * and change_pct are numeric(12,2) (review F8). The previous fields are all null, or the previous close and day are
 * both set (change_pct alone may stay null: a 0.00 previous close).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE price_latest (
                variant_key text NOT NULL,
                source text NOT NULL,
                series_epoch integer NOT NULL,
                close numeric(12,2) NOT NULL,
                day date NOT NULL,
                as_of timestamptz NOT NULL,
                previous_close numeric(12,2) NULL,
                previous_day date NULL,
                change numeric(12,2) NULL,
                change_pct numeric(12,2) NULL,
                CONSTRAINT price_latest_pkey PRIMARY KEY (variant_key, source),
                CONSTRAINT price_latest_variant_key_foreign FOREIGN KEY (variant_key) REFERENCES card_variants (variant_key),
                CONSTRAINT price_latest_source_check CHECK (source = 'tcgplayer'),
                CONSTRAINT price_latest_series_epoch_check CHECK (series_epoch >= 1),
                CONSTRAINT price_latest_close_check CHECK (close >= 0),
                CONSTRAINT price_latest_previous_close_check CHECK (previous_close >= 0),
                CONSTRAINT price_latest_previous_check CHECK (
                    (previous_close IS NULL AND previous_day IS NULL AND change IS NULL AND change_pct IS NULL)
                    OR (previous_close IS NOT NULL AND previous_day IS NOT NULL)
                ),
                CONSTRAINT price_latest_previous_day_check CHECK (previous_day < day)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_latest');
    }
};
