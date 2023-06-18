<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tarfinlabs\EventMachine\MachineServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        //        Factory::guessFactoryNamesUsing(
        //            fn (string $modelName) => 'Tarfinlabs\\EventMachine\\Database\\Factories\\'.class_basename($modelName).'Factory'
        //        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            MachineServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        $migration = include __DIR__.'/../database/migrations/create_machine_table.php.stub';
        $migration->up();
    }
}
