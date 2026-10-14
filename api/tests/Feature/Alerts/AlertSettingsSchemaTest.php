<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan P5-i1 T05 criterion 1 (D1, migration 2): watchlist_items.alert_pct with its named CHECK (1..100, or null),
// notification_settings with its columns, defaults, named constraints and the cascade from users, no foreign key to
// card_variants or any price table, and the migration's round trip.

const T5_SCHEMA_MIGRATION = 'migrations/2026_10_16_000002_add_alert_settings.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** @return array<string, array{type: string, nullable: bool, default: string|null}> */
function t5Columns(string $table): array
{
    $rows = DB::select('SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table]);
    $columns = [];
    foreach ($rows as $row) {
        $columns[$row->column_name] = ['type' => $row->data_type, 'nullable' => $row->is_nullable === 'YES', 'default' => $row->column_default];
    }

    return $columns;
}

/** @return array<string, string> constraint name => definition */
function t5Constraints(string $table): array
{
    $rows = DB::select('SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass ORDER BY conname', [$table]);

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->conname => $r->def])->all();
}

it('adds watchlist_items.alert_pct, a nullable smallint, with its named CHECK', function (): void {
    expect(t5Columns('watchlist_items')['alert_pct'] ?? null)->toBe(['type' => 'smallint', 'nullable' => true, 'default' => null])
        ->and(t5Constraints('watchlist_items')['watchlist_items_alert_pct_check'] ?? null)
        ->toBe('CHECK (((alert_pct IS NULL) OR ((alert_pct >= 1) AND (alert_pct <= 100))))');
});

it('creates notification_settings exactly as D1', function (): void {
    expect(t5Columns('notification_settings'))->toBe([
        'user_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null],
        'channel' => ['type' => 'text', 'nullable' => false, 'default' => "'email'::text"],
        'digest' => ['type' => 'boolean', 'nullable' => false, 'default' => 'false'],
        'updated_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()'],
    ])
        ->and(t5Constraints('notification_settings'))->toBe([
            'notification_settings_channel_check' => "CHECK ((channel = ANY (ARRAY['email'::text, 'discord'::text])))",
            'notification_settings_pkey' => 'PRIMARY KEY (user_id)',
            'notification_settings_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ]);
});

it('rejects an alert_pct of 0 or 101 and a channel other than email or discord', function (string $sql, string $constraint): void {
    $user = Accounts::user();
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');

    expect(fn () => DB::transaction(fn () => DB::statement(str_replace('?', (string) $user->id, $sql))))->toThrow(QueryException::class, $constraint);
})->with([
    'alert_pct 0' => ['UPDATE watchlist_items SET alert_pct = 0 WHERE user_id = ?', 'watchlist_items_alert_pct_check'],
    'alert_pct 101' => ['UPDATE watchlist_items SET alert_pct = 101 WHERE user_id = ?', 'watchlist_items_alert_pct_check'],
    'channel slack' => ["INSERT INTO notification_settings (user_id, channel) VALUES (?, 'slack')", 'notification_settings_channel_check'],
    'second row for one user' => ['INSERT INTO notification_settings (user_id) VALUES (?), (?)', 'notification_settings_pkey'],
]);

it('accepts alert_pct 1, 100 and null, and a settings row with its defaults', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');

    foreach ([1, 100, null] as $pct) {
        DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => $pct]);
        expect(DB::table('watchlist_items')->where('user_id', $user->id)->value('alert_pct'))->toBe($pct);
    }
    DB::insert('INSERT INTO notification_settings (user_id) VALUES (?)', [$user->id]);
    $row = DB::table('notification_settings')->where('user_id', $user->id)->first();

    expect([$row->channel, $row->digest])->toBe(['email', false]);
});

it('deletes a user\'s settings with the user, and nobody else\'s', function (): void {
    $gone = Accounts::user(['email' => 'gone@example.com']);
    $kept = Accounts::user(['email' => 'kept@example.com']);
    DB::insert("INSERT INTO notification_settings (user_id, channel, digest) VALUES (?, 'email', true), (?, 'email', false)", [$gone->id, $kept->id]);

    $gone->delete();

    expect(DB::table('notification_settings')->pluck('user_id')->all())->toBe([$kept->id]);
});

it('references only users: no foreign key to card_variants, price_events or any price table', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS source, c.confrelid::regclass::text AS target
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid IN ('notification_settings'::regclass, 'watchlist_items'::regclass)
        ORDER BY 1, 2
        SQL);

    expect(array_map(fn (object $r): array => [$r->source, $r->target], $rows))->toBe([
        ['notification_settings', 'users'],
        ['watchlist_items', 'users'],
    ]);
});

it('rolls migration 2 back and forth on the test database', function (): void {
    $migration = require database_path(T5_SCHEMA_MIGRATION);

    $migration->down();
    $down = [
        DB::selectOne("SELECT to_regclass('notification_settings')::text AS t")->t,
        array_key_exists('alert_pct', t5Columns('watchlist_items')),
        array_key_exists('watchlist_items_alert_pct_check', t5Constraints('watchlist_items')),
    ];
    $migration->up();

    expect($down)->toBe([null, false, false])
        ->and(t5Columns('watchlist_items'))->toHaveKey('alert_pct')
        ->and(t5Constraints('watchlist_items'))->toHaveKey('watchlist_items_alert_pct_check')
        ->and(t5Constraints('notification_settings'))->toHaveKey('notification_settings_channel_check');
});
