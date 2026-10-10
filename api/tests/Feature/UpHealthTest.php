<?php

declare(strict_types=1);

it('answers the liveness route /up from Laravel', function (): void {
    $this->get('/up')->assertOk();
});

it('runs feature tests against Postgres, never SQLite', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toStartWith('pokeprices_test');
});
