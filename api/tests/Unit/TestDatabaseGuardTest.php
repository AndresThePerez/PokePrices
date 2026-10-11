<?php

declare(strict_types=1);

use Tests\TestCase;

it('refuses a non-pgsql default connection', function (): void {
    expect(fn () => TestCase::ensureSafeTestDatabase('sqlite', 'pokeprices_test'))
        ->toThrow(RuntimeException::class, 'PokéPrices tests refuse to run against database pokeprices_test; expected pokeprices_test*');
});

it('refuses a pgsql database outside pokeprices_test*', function (string $name): void {
    expect(fn () => TestCase::ensureSafeTestDatabase('pgsql', $name))
        ->toThrow(RuntimeException::class, 'expected pokeprices_test*');
})->with(['pokeprices', 'laravel', 'test_pokeprices_test', '']);

it('refuses a missing database name', function (): void {
    expect(fn () => TestCase::ensureSafeTestDatabase('pgsql', null))
        ->toThrow(RuntimeException::class, 'expected pokeprices_test*');
});

it('accepts pokeprices_test and its parallel worker databases', function (string $name): void {
    expect(fn () => TestCase::ensureSafeTestDatabase('pgsql', $name))
        ->not->toThrow(RuntimeException::class);
})->with(['pokeprices_test', 'pokeprices_test_test_1']);
