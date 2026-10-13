<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Plan P4-i1 T02 criterion 1 (D5) and criterion 16 (review F-P4-04): the users account columns and indexes.

const ACCOUNT_MIGRATION = 'migrations/2026_10_15_000001_add_account_columns_to_users_table.php';

/** @return array<string, array{nullable: bool, type: string, default: string|null}> */
function accountUserColumns(): array
{
    $rows = DB::select("SELECT column_name, is_nullable, data_type, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users'");
    $columns = [];
    foreach ($rows as $row) {
        $columns[$row->column_name] = ['nullable' => $row->is_nullable === 'YES', 'type' => $row->data_type, 'default' => $row->column_default];
    }

    return $columns;
}

/** @return array<string, string> index name => definition */
function accountUserIndexes(): array
{
    $rows = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'users'");

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->indexname => $r->indexdef])->all();
}

function accountInsertUser(string $email, bool $demo = false): void
{
    DB::insert('INSERT INTO users (email, password, is_demo, created_at, updated_at) VALUES (?, ?, ?, now(), now())', [$email, 'x', $demo]);
}

it('adds the account columns with their nullability and defaults and makes name nullable', function (): void {
    $columns = accountUserColumns();

    expect($columns['name']['nullable'])->toBeTrue()
        ->and($columns['is_demo'])->toBe(['nullable' => false, 'type' => 'boolean', 'default' => 'false'])
        ->and($columns['two_factor_secret'])->toBe(['nullable' => true, 'type' => 'text', 'default' => null])
        ->and($columns['two_factor_recovery_codes'])->toBe(['nullable' => true, 'type' => 'text', 'default' => null])
        ->and($columns['two_factor_confirmed_at'])->toBe(['nullable' => true, 'type' => 'timestamp without time zone', 'default' => null]);
    $precision = DB::selectOne("SELECT datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'two_factor_confirmed_at'");
    expect($precision->datetime_precision)->toBe(0);
});

it('keeps users_email_unique and adds the partial demo index and the lower(email) unique index', function (): void {
    $indexes = accountUserIndexes();

    expect($indexes)->toHaveKeys(['users_email_unique', 'users_one_demo_unique', 'users_email_lower_unique'])
        ->and($indexes['users_one_demo_unique'])->toContain('UNIQUE')->toContain('(is_demo)')->toContain('WHERE is_demo')
        ->and($indexes['users_email_lower_unique'])->toContain('UNIQUE')->toContain('lower((email)::text)');
});

it('refuses a second demo user and allows any number of ordinary users', function (): void {
    accountInsertUser('demo-one@example.com', true);
    accountInsertUser('one@example.com');
    accountInsertUser('two@example.com');

    expect(fn () => DB::transaction(fn () => accountInsertUser('demo-two@example.com', true)))
        ->toThrow(QueryException::class, 'users_one_demo_unique');
    expect(DB::table('users')->count())->toBe(3);
});

it('refuses a raw insert of A@example.com next to a@example.com (users_email_lower_unique)', function (): void {
    accountInsertUser('a@example.com');

    expect(fn () => DB::transaction(fn () => accountInsertUser('A@example.com')))
        ->toThrow(QueryException::class, 'users_email_lower_unique');
});

it('rolls the migration back and forth on the test database', function (): void {
    accountInsertUser('nameless@example.com');
    $migration = require database_path(ACCOUNT_MIGRATION);

    $migration->down();
    $down = accountUserColumns();
    $downIndexes = accountUserIndexes();
    $nameless = DB::table('users')->where('email', 'nameless@example.com')->value('name');

    $migration->up();
    $up = accountUserColumns();

    expect(array_keys($down))->not->toContain('is_demo', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at')
        ->and($down['name']['nullable'])->toBeFalse()
        ->and($nameless)->toBe('')
        ->and($downIndexes)->not->toHaveKey('users_one_demo_unique')
        ->and($downIndexes)->not->toHaveKey('users_email_lower_unique')
        ->and($downIndexes)->toHaveKey('users_email_unique')
        ->and($up['is_demo']['default'])->toBe('false')
        ->and($up['name']['nullable'])->toBeTrue()
        ->and(accountUserIndexes())->toHaveKeys(['users_one_demo_unique', 'users_email_lower_unique']);
});
