<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Alert settings (System Design §2 "thresholds per watchlist item").
 *
 * watchlist_items.alert_pct: the whole-percent threshold of one watchlist row, or null (no alert). The API refuses
 * anything below the movement rule; the CHECK keeps the column inside 1..100 whatever writes it. The threshold
 * lives on the watchlist row, so removing the row removes it.
 *
 * notification_settings: where a person's alerts and digest go (email or their Discord webhook) and whether a daily
 * digest is sent. A person without a row has the defaults (email, digest off). Deleted with the user; no reference to
 * card_variants or any price table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE watchlist_items ADD COLUMN alert_pct smallint NULL');
        DB::statement('ALTER TABLE watchlist_items ADD CONSTRAINT watchlist_items_alert_pct_check CHECK (alert_pct IS NULL OR alert_pct BETWEEN 1 AND 100)');
        DB::statement(<<<'SQL'
            CREATE TABLE notification_settings (
                user_id bigint NOT NULL,
                channel text NOT NULL DEFAULT 'email',
                digest boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT notification_settings_pkey PRIMARY KEY (user_id),
                CONSTRAINT notification_settings_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT notification_settings_channel_check CHECK (channel IN ('email', 'discord'))
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notification_settings');
        DB::statement('ALTER TABLE watchlist_items DROP CONSTRAINT IF EXISTS watchlist_items_alert_pct_check');
        DB::statement('ALTER TABLE watchlist_items DROP COLUMN IF EXISTS alert_pct');
    }
};
