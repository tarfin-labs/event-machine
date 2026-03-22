<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;
use Tarfinlabs\EventMachine\MachineServiceProvider;

class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        // Bus::batch requires explicit batching config pointing to the test connection
        $app['config']->set('queue.batching.database', $app['config']->get('database.default'));
        $app['config']->set('queue.batching.table', 'job_batches');
    }

    protected function getPackageProviders($app): array
    {
        return [
            MachineServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');

        $migration = include __DIR__.'/../database/migrations/create_machine_events_table.php.stub';
        $migration->up();

        $archiveMigration = include __DIR__.'/../database/migrations/create_machine_events_archive_table.php.stub';
        $archiveMigration->up();

        $locksMigration = include __DIR__.'/../database/migrations/create_machine_locks_table.php.stub';
        $locksMigration->up();

        $childrenMigration = include __DIR__.'/../database/migrations/create_machine_children_table.php.stub';
        $childrenMigration->up();

        $currentStatesMigration = include __DIR__.'/../database/migrations/create_machine_current_states_table.php.stub';
        $currentStatesMigration->up();

        $timerFiresMigration = include __DIR__.'/../database/migrations/create_machine_timer_fires_table.php.stub';
        $timerFiresMigration->up();

        // Laravel job_batches table (required for Bus::batch in ProcessTimersCommand)
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('model_a_s', function (Blueprint $table): void {
            $table->id();
            $table->string('value')->nullable();
            $table->ulid('abc_mre')->nullable();
            $table->ulid('traffic_mre')->nullable();
            $table->ulid('elevator_mre')->nullable();
            $table->timestamps();
        });
    }
}
