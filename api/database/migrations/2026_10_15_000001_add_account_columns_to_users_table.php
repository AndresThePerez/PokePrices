<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Account columns on users (plan P4-i1 T02, D5). People register with an email and a password only, so name becomes
 * nullable. is_demo marks the one read-only demo account (users_one_demo_unique allows a single true row); the three
 * two_factor_* columns hold Fortify's encrypted TOTP secret, its encrypted recovery codes and the confirmation time.
 * users_email_lower_unique (review F-P4-04) makes a path that skips the email normalisation fail closed; the default
 * users_email_unique stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN name DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN is_demo boolean NOT NULL DEFAULT false,
                ADD COLUMN two_factor_secret text NULL,
                ADD COLUMN two_factor_recovery_codes text NULL,
                ADD COLUMN two_factor_confirmed_at timestamp(0) without time zone NULL
            SQL);
        DB::statement('CREATE UNIQUE INDEX users_one_demo_unique ON users ((is_demo)) WHERE is_demo');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('DROP INDEX IF EXISTS users_one_demo_unique');
        DB::statement(<<<'SQL'
            ALTER TABLE users
                DROP COLUMN two_factor_confirmed_at,
                DROP COLUMN two_factor_recovery_codes,
                DROP COLUMN two_factor_secret,
                DROP COLUMN is_demo
            SQL);
        DB::statement("UPDATE users SET name = '' WHERE name IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');
    }
};
