<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\DemoAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds the read-only demo account: its watchlist and portfolio (App\Domain\Accounts\DemoAccount), its alert, its
 * sample signed webhook and two delivered sample messages (App\Domain\Alerts\DemoSamples). Idempotent, sends and
 * queues nothing, and safe on an empty database (a key missing from card_variants is skipped with one console line;
 * with no move of a demo card there is no sample message). Not called by DatabaseSeeder. On the dev database:
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
