<?php

namespace Tarfinlabs\EventMachine\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tarfinlabs\EventMachine\EventMachineServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

//        Factory::guessFactoryNamesUsing(
//            fn (string $modelName) => 'Tarfinlabs\\EventMachine\\Database\\Factories\\'.class_basename($modelName).'Factory'
//        );
    }

    protected function getPackageProviders($app)
    {
        return [
            EventMachineServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_event-machine_table.php.stub';
        $migration->up();
        */
    }
}
