<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tarfinlabs\EventMachine\Commands\TimerStatusCommand;
use Tarfinlabs\EventMachine\Commands\ExportXStateCommand;
use Tarfinlabs\EventMachine\Commands\MachinePathsCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveEventsCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveStatusCommand;
use Tarfinlabs\EventMachine\Commands\ProcessTimersCommand;
use Tarfinlabs\EventMachine\Commands\MachineCoverageCommand;
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
            ->hasMigration('add_scenario_columns_to_machine_current_states_table')
            ->hasCommand(ArchiveEventsCommand::class)
            ->hasCommand(ArchiveStatusCommand::class)
            ->hasCommand(MachineConfigValidatorCommand::class)
            ->hasCommand(ExportXStateCommand::class)
            ->hasCommand(ProcessTimersCommand::class)
            ->hasCommand(TimerStatusCommand::class)
            ->hasCommand(ProcessScheduledCommand::class)
            ->hasCommand(MachinePathsCommand::class)
            ->hasCommand(MachineCoverageCommand::class);
    }
}
