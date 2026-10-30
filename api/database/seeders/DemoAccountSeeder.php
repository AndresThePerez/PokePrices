<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\DemoAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds the read-only demo account; idempotent, and safe on an empty database (a configured key
 * missing from card_variants is skipped with one console line). Not called by DatabaseSeeder. On the dev database:
 * docker compose -f compose.dev.yml exec -T -w /workspace/api api php artisan db:seed --class=DemoAccountSeeder --no-interaction
 */
final class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        DemoAccount::seed(function (string $line): void {
            $this->command?->line($line);
        });
    }
}
