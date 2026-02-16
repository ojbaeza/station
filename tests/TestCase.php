<?php

declare(strict_types=1);

namespace Station\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Station\StationServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            StationServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment(mixed $app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('station.default', 'station');
        $app['config']->set('station.storage.database.connection', 'testing');
        $app['config']->set('station.storage.database.table_prefix', 'station_');
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
