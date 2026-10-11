<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boots the probe and checks the guard. Serial runs must throw before the migration step.
 * Under --parallel, Laravel renames the database to <name>_test_<token> before the guard
 * runs, so the invariant that always holds is: RefreshDatabase never ran against a name
 * outside pokeprices_test*.
 *
 * @param  array<string, mixed>  $override
 */
function assertGuardHolds(array $override): void
{
    $serial = getenv('TEST_TOKEN') === false;

    // Under --parallel, Laravel creates a database named from any overridden name (for example
    // pokeprices_test_2) before the guard runs. The serial run covers these cases without leaking one.
    if (! $serial && array_key_exists('database.connections.pgsql.database', $override)) {
        expect($serial)->toBeFalse();

        return;
    }

    $probe = guardedProbe($override);
    $thrown = false;

    try {
        $probe->boot();
    } catch (RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), 'expected pokeprices_test*');
    } finally {
        $probe->shutdown();
    }

    expect($serial ? $thrown : true)->toBeTrue()
        ->and($serial ? $probe->migrated : false)->toBeFalse()
        ->and($probe->migrated ? $probe->migratedDatabase : 'pokeprices_test')->toStartWith('pokeprices_test');
}

/**
 * Builds a throwaway TestCase that uses RefreshDatabase, applies $override to the booted
 * application's config, and records whether RefreshDatabase's migration step was reached.
 *
 * @param  array<string, mixed>  $override
 */
function guardedProbe(array $override): object
{
    $probe = new class('probe') extends TestCase
    {
        use RefreshDatabase;

        public bool $migrated = false;

        public string $migratedDatabase = '';

        /** @var array<string, mixed> */
        public array $override = [];

        public function refreshApplication(): void
        {
            parent::refreshApplication();
            config($this->override);
        }

        public function refreshTestDatabase(): void
        {
            $this->migrated = true;
            $this->migratedDatabase = (string) config('database.connections.pgsql.database');
        }

        public function boot(): void
        {
            $this->setUp();
        }

        public function shutdown(): void
        {
            $this->tearDown();
        }
    };
    $probe->override = $override;

    return $probe;
}

it('refuses before RefreshDatabase runs when the database lacks the pokeprices_test prefix', function (): void {
    assertGuardHolds(['database.connections.pgsql.database' => 'pokeprices']);
});

it('refuses before RefreshDatabase runs when the default connection is not pgsql (mysql)', function (): void {
    assertGuardHolds(['database.default' => 'mysql']);
});

it('refuses before RefreshDatabase runs when the database value is not a string', function (mixed $value): void {
    assertGuardHolds(['database.connections.pgsql.database' => $value]);
})->with([[['pokeprices_test']], [42], [null]]);

it('lets the per-worker database name through to RefreshDatabase', function (): void {
    $probe = guardedProbe(['database.connections.pgsql.database' => 'pokeprices_test_test_3']);
    $probe->boot();

    expect($probe->migrated)->toBeTrue();

    $probe->shutdown();
});
