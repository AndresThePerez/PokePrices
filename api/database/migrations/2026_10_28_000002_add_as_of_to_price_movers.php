<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * price_movers.as_of (plan Q1-i3 T04, finding B10): the snapshot marker of each movers day, max(as_of) of that day's
 * real (never filled) price_daily closes for the source, which GET /v1/movers used to compute from price_daily on every
 * request (review F9). Movers::rebuild() now writes it with the rows; existing rows are backfilled with the same value,
 * then the column becomes NOT NULL. Like the rest of the table it is derived data, outside PriceDigest (R180b).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE price_movers ADD COLUMN as_of timestamptz');
        DB::statement(<<<'SQL'
            UPDATE price_movers m
            SET as_of = (SELECT max(d.as_of) FROM price_daily d WHERE d.source = m.source AND d.day = m.day AND NOT d.is_filled)
            SQL);
        DB::statement('ALTER TABLE price_movers ALTER COLUMN as_of SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS price_movers DROP COLUMN IF EXISTS as_of');
    }
};
