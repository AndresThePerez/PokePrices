<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// The demo's watchlist and portfolio come from App\Domain\Accounts\DemoAccount alone. config/accounts.php demo holds
// what the environment decides (enabled, email, password) and no list of cards: the two lists it once held were read
// by nothing, so they are gone and the seeded demo is what it was, 14 watchlist cards and 5 holdings.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    // Runtime values only: whatever api/.env holds never reaches a test.
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('keeps only enabled, email and password under accounts.demo, and still seeds 14 watchlist cards and 5 holdings', function (): void {
    foreach (DemoAccount::WATCHLIST as $key) {
        AccountRows::variant($key);
    }

    $code = Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]);

    $demo = User::query()->where('is_demo', true)->sole();
    $watched = AccountRows::watched($demo);
    $held = AccountRows::held($demo);
    expect(array_keys((array) config('accounts.demo')))->toBe(['enabled', 'email', 'password'])
        ->and($code)->toBe(0)
        ->and($watched)->toHaveCount(14)
        ->and(array_unique(array_map(fn (string $key): string => explode(':', $key)[0], $watched)))->toHaveCount(14)
        ->and($held)->toHaveCount(5)
        ->and($held)->toBe([
            'base1-2:holo:unlimited:-' => 1,
            'base1-58:normal:unlimited:-' => 2,
            'ex5-17:reverse:-:-' => 3,
            'neo1-9:holo:unlimited:-' => 1,
            'sv2-203:holo:-:-' => 1,
        ])
        ->and(array_diff(array_keys($held), $watched))->toBe([]);
});
