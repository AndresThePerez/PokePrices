<?php

declare(strict_types=1);

it('forces the phpunit.xml database over the compose environment', function (): void {
    expect(DB::connection()->getDatabaseName())->toMatch('/^pokeprices_test(_test_[0-9]+)?$/')
        ->and(config('database.connections.pgsql.database'))->toStartWith('pokeprices_test')
        ->and($_SERVER['DB_DATABASE'])->toStartWith('pokeprices_test')
        ->and(env('DB_DATABASE'))->toStartWith('pokeprices_test');
});

it('runs under the eight phpunit.xml environment values', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('database.default'))->toBe('pgsql')
        ->and(env('BCRYPT_ROUNDS'))->toBe('4');
});
