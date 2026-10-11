<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boots the probe and checks the guard. Serial runs must throw before the migration step.
 * Under --parallel, Laravel renames the database to <name>_test_<token> before the guard
 * runs, so the invariant that always holds is: RefreshDatabase never ran against a name
 * outside pokeprices_test*. Returns the violated part of the invariant, or '' when it held.
 *
 * @param  array<string, mixed>  $override
 */
function guardViolation(array $override): string
{
    $serial = getenv('TEST_TOKEN') === false;

    // Under --parallel, Laravel creates a database named from any overridden name (for example
    // pokeprices_test_2) before the guard runs. The serial run covers these cases without leaking one.
    if (! $serial && array_key_exists('database.connections.pgsql.database', $override)) {
        return '';
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

    if ($serial && ! $thrown) {
        return 'the guard did not refuse with "expected pokeprices_test*"';
    }

    if ($serial && $probe->migrated) {
        return 'RefreshDatabase reached its migration step';
    }

    if ($probe->migrated && ! str_starts_with($probe->migratedDatabase, 'pokeprices_test')) {
        return 'RefreshDatabase migrated '.$probe->migratedDatabase;
    }

    return '';
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
    expect(guardViolation(['database.connections.pgsql.database' => 'pokeprices']))->toBe('');
});

it('refuses before RefreshDatabase runs when the default connection is not pgsql (mysql)', function (): void {
    expect(guardViolation(['database.default' => 'mysql']))->toBe('');
});

it('refuses before RefreshDatabase runs when the database value is not a string', function (mixed $value): void {
    expect(guardViolation(['database.connections.pgsql.database' => $value]))->toBe('');
})->with([[['pokeprices_test']], [42], [null]]);

it('lets the per-worker database name through to RefreshDatabase', function (): void {
    // Serial: a per-worker-shaped name passes the guard without any database being touched.
    // Parallel: Laravel's own per-worker database (created from pokeprices_test, so a fresh volume
    // works and no stray database is left, R100) must reach RefreshDatabase unchanged.
    $serial = getenv('TEST_TOKEN') === false;
    $probe = guardedProbe($serial ? ['database.connections.pgsql.database' => 'pokeprices_test_test_3'] : []);
    $probe->boot();

    expect($probe->migrated)->toBeTrue()
        ->and($probe->migratedDatabase)->toBe($serial ? 'pokeprices_test_test_3' : 'pokeprices_test_test_'.getenv('TEST_TOKEN'));

    $probe->shutdown();
});
