<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public static function ensureSafeTestDatabase(string $connection, mixed $database): void
    {
        $name = is_string($database) ? $database : '';

        if ($connection !== 'pgsql' || ! str_starts_with($name, 'pokeprices_test')) {
            throw new RuntimeException(
                sprintf('PokéPrices tests refuse to run against database %s; expected pokeprices_test*', $name),
            );
        }
    }

    /**
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $default = config('database.default');

        self::ensureSafeTestDatabase(
            is_string($default) ? $default : '',
            config('database.connections.pgsql.database'),
        );

        return parent::setUpTraits();
    }
}
