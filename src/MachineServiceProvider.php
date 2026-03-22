<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Tarfinlabs\EventMachine\Enums\TimerResolution;
use Tarfinlabs\EventMachine\Support\MachineDiscovery;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tarfinlabs\EventMachine\Commands\TimerStatusCommand;
use Tarfinlabs\EventMachine\Commands\ExportXStateCommand;
use Tarfinlabs\EventMachine\Commands\MachineCacheCommand;
use Tarfinlabs\EventMachine\Commands\MachineClearCommand;
use Tarfinlabs\EventMachine\Commands\MachinePathsCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveEventsCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveStatusCommand;
use Tarfinlabs\EventMachine\Commands\ProcessTimersCommand;
use Tarfinlabs\EventMachine\Commands\ProcessScheduledCommand;
use Tarfinlabs\EventMachine\Commands\MachineConfigValidatorCommand;

/**
 * Class MachineServiceProvider.
 *
 * The MachineServiceProvider class extends the PackageServiceProvider class and is responsible for configuring the Machine package.
 */
class MachineServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the given package.
     *
     * This method is responsible for setting up the necessary configurations and assets for the given package.
     *
     * @param  Package  $package  The package to be configured.
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('event-machine')
            ->hasConfigFile('machine')
            ->hasMigration('create_machine_events_table')
            ->hasMigration('add_archival_index_to_machine_events_table')
            ->hasMigration('create_machine_events_archive_table')
            ->hasMigration('create_machine_locks_table')
            ->hasMigration('create_machine_children_table')
            ->hasMigration('create_machine_current_states_table')
            ->hasMigration('create_machine_timer_fires_table')
            ->hasCommand(ArchiveEventsCommand::class)
            ->hasCommand(ArchiveStatusCommand::class)
            ->hasCommand(MachineConfigValidatorCommand::class)
            ->hasCommand(ExportXStateCommand::class)
            ->hasCommand(ProcessTimersCommand::class)
            ->hasCommand(TimerStatusCommand::class)
            ->hasCommand(MachineCacheCommand::class)
            ->hasCommand(MachineClearCommand::class)
            ->hasCommand(ProcessScheduledCommand::class)
            ->hasCommand(MachinePathsCommand::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Auto-register timer sweep commands with Laravel Scheduler
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->registerTimerSweeps($schedule);
        });
    }

    /**
     * Discover machine classes with timer-configured transitions
     * and register per-class sweep commands with the scheduler.
     */
    protected function registerTimerSweeps(Schedule $schedule): void
    {
        $resolution = TimerResolution::tryFrom(
            (string) config('machine.timers.resolution', 'everyMinute')
        ) ?? TimerResolution::EVERY_MINUTE;

        $cachePath = $this->app->bootstrapPath('cache/machines.php');

        if (file_exists($cachePath)) {
            $timerMachines = require $cachePath;
        } elseif ($this->app->environment('local', 'testing')) {
            $timerMachines = MachineDiscovery::findTimerMachines();
        } else {
            logger()->warning('EventMachine: timer machine cache not found. Run `php artisan machine:cache` in production. Timer sweeps will not be registered.');
            $timerMachines = [];
        }

        foreach ($timerMachines as $machineClass) {
            $schedule->command("machine:process-timers --class={$machineClass}")
                ->{$resolution->value}()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }
}
