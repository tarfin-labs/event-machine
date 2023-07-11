<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Tarfinlabs\EventMachine\MachineServiceProvider;

class TestCase extends Orchestra
{
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

        $migration = include __DIR__.'/../database/migrations/create_machine_events_table.php.stub';
        $migration->up();

        Schema::create('model_a_s', function (Blueprint $table): void {
            $table->id();
            $table->string('value')->nullable();
            $table->string('abc_mre')->nullable();
            $table->string('traffic_mre')->nullable();
            $table->timestamps();
        });
    }
}
