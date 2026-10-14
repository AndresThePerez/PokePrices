<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan P4-i1 T05 criterion 1 (D5): watchlist_items and portfolio_holdings with their exact columns and named
// constraints, a cascade from users, and no foreign key to card_variants or any price table.

/** @return array<string, array{type: string, nullable: bool, default: string|null, identity: string}> */
function wlsColumns(string $table): array
{
    $rows = DB::select('SELECT column_name, data_type, is_nullable, column_default, is_identity, identity_generation FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table]);
    $columns = [];
    foreach ($rows as $row) {
        $columns[$row->column_name] = [
            'type' => $row->data_type, 'nullable' => $row->is_nullable === 'YES', 'default' => $row->column_default,
            'identity' => $row->is_identity === 'YES' ? (string) $row->identity_generation : '',
        ];
    }

    return $columns;
}

/** @return array<string, string> constraint name => definition */
function wlsConstraints(string $table): array
{
    $rows = DB::select('SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass ORDER BY conname', [$table]);

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->conname => $r->def])->all();
}

it('creates watchlist_items exactly as D5', function (): void {
    expect(wlsColumns('watchlist_items'))->toBe([
        'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => 'BY DEFAULT'],
        'user_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => ''],
        'variant_key' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'created_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
        'alert_pct' => ['type' => 'smallint', 'nullable' => true, 'default' => null, 'identity' => ''],
    ])
        ->and(wlsConstraints('watchlist_items'))->toBe([
            'watchlist_items_alert_pct_check' => 'CHECK (((alert_pct IS NULL) OR ((alert_pct >= 1) AND (alert_pct <= 100))))',
            'watchlist_items_pkey' => 'PRIMARY KEY (id)',
            'watchlist_items_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'watchlist_items_user_variant_unique' => 'UNIQUE (user_id, variant_key)',
        ]);
});

it('creates portfolio_holdings exactly as D5', function (): void {
    expect(wlsColumns('portfolio_holdings'))->toBe([
        'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => 'BY DEFAULT'],
        'user_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => ''],
        'variant_key' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'quantity' => ['type' => 'integer', 'nullable' => false, 'default' => null, 'identity' => ''],
        'created_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
        'updated_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
    ])
        ->and(wlsConstraints('portfolio_holdings'))->toBe([
            'portfolio_holdings_pkey' => 'PRIMARY KEY (id)',
            'portfolio_holdings_quantity_check' => 'CHECK (((quantity >= 1) AND (quantity <= 9999)))',
            'portfolio_holdings_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'portfolio_holdings_user_variant_unique' => 'UNIQUE (user_id, variant_key)',
        ]);
});

it('holds no foreign key from either table to card_variants or any other table but users', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT tc.table_name, ccu.table_name AS referenced
        FROM information_schema.table_constraints tc
        JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()
          AND tc.table_name IN ('watchlist_items', 'portfolio_holdings')
        ORDER BY tc.table_name
        SQL);

    expect(array_map(fn (object $r): array => [$r->table_name, $r->referenced], $rows))->toBe([
        ['portfolio_holdings', 'users'],
        ['watchlist_items', 'users'],
    ]);
});

it('refuses a duplicate (user, variant) and a quantity outside 1..9999', function (string $sql): void {
    $user = Accounts::user();
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);

    expect(fn () => DB::transaction(fn () => DB::statement(str_replace('?', (string) $user->id, $sql))))->toThrow(QueryException::class);
})->with([
    'watchlist duplicate' => ["INSERT INTO watchlist_items (user_id, variant_key) VALUES (?, 'base1-4:holo:unlimited:-')"],
    'portfolio duplicate' => ["INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, 'base1-4:holo:unlimited:-', 2)"],
    'quantity 0' => ["INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, 'ex5-17:reverse:-:-', 0)"],
    'quantity 10000' => ["INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, 'ex5-17:reverse:-:-', 10000)"],
]);

it('deletes a user\'s watchlist and portfolio rows with the user, and nobody else\'s', function (): void {
    $gone = Accounts::user(['email' => 'gone@example.com']);
    $kept = Accounts::user(['email' => 'kept@example.com']);
    foreach ([$gone, $kept] as $user) {
        AccountRows::watch($user, 'base1-4:holo:unlimited:-');
        AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    }

    $gone->delete();

    expect(DB::table('watchlist_items')->pluck('user_id')->all())->toBe([$kept->id])
        ->and(DB::table('portfolio_holdings')->pluck('user_id')->all())->toBe([$kept->id]);
});

it('rolls the migration back and forth on the test database', function (): void {
    $migration = require database_path('migrations/2026_10_15_000002_create_watchlist_and_portfolio_tables.php');

    $migration->down();
    $down = DB::selectOne("SELECT to_regclass('watchlist_items')::text AS w, to_regclass('portfolio_holdings')::text AS p");
    $migration->up();

    expect([$down->w, $down->p])->toBe([null, null])
        ->and(wlsConstraints('watchlist_items'))->toHaveKey('watchlist_items_user_variant_unique')
        ->and(wlsConstraints('portfolio_holdings'))->toHaveKey('portfolio_holdings_quantity_check');
});
