<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Spatie\LaravelPackageTools\Package;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Console\Scheduling\Schedule;
use Tarfinlabs\EventMachine\Enums\TimerResolution;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Commands\TimerStatusCommand;
use Tarfinlabs\EventMachine\Commands\ExportXStateCommand;
use Tarfinlabs\EventMachine\Commands\MachineCacheCommand;
use Tarfinlabs\EventMachine\Commands\MachineClassVisitor;
use Tarfinlabs\EventMachine\Commands\MachineClearCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveEventsCommand;
use Tarfinlabs\EventMachine\Commands\ArchiveStatusCommand;
use Tarfinlabs\EventMachine\Commands\ProcessTimersCommand;
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
            ->hasCommand(MachineClearCommand::class);
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

        $cachePath     = $this->app->bootstrapPath('cache/machines.php');
        $timerMachines = file_exists($cachePath)
            ? require $cachePath
            : $this->discoverTimerMachines();

        foreach ($timerMachines as $machineClass) {
            $schedule->command("machine:process-timers --class={$machineClass}")
                ->{$resolution->value}()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }

    /**
     * Discover all Machine subclasses that have timer-configured transitions.
     *
     * Uses PhpParser + MachineClassVisitor for auto-discovery (same as MachineConfigValidatorCommand),
     * then checks each definition for after/every keys on transitions.
     *
     * @return array<string> Machine class FQCNs with timer config
     */
    protected function discoverTimerMachines(): array
    {
        if (!is_dir(app_path())) {
            return [];
        }

        $allMachines   = $this->findAllMachineClasses();
        $timerMachines = [];

        foreach ($allMachines as $machineClass) {
            if (!is_subclass_of($machineClass, Machine::class)) {
                continue;
            }

            try {
                $definition = $machineClass::definition();

                foreach ($definition->idMap as $stateDefinition) {
                    if ($stateDefinition->transitionDefinitions === null) {
                        continue;
                    }

                    foreach ($stateDefinition->transitionDefinitions as $transitionDef) {
                        if ($transitionDef->timerDefinition instanceof TimerDefinition) {
                            $timerMachines[] = $machineClass;

                            continue 3;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $timerMachines;
    }

    /**
     * Find all Machine class FQCNs in the application using PhpParser.
     *
     * @return array<string>
     */
    protected function findAllMachineClasses(): array
    {
        $parser    = (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
        $traverser = new NodeTraverser();
        $visitor   = new MachineClassVisitor();
        $traverser->addVisitor($visitor);

        $machines = [];
        $finder   = new Finder();
        $finder->files()->name('*.php')->in(app_path());

        foreach ($finder as $file) {
            try {
                $code = $file->getContents();
                $ast  = $parser->parse($code);

                $visitor->setCurrentFile($file->getRealPath());
                $traverser->traverse($ast);

                $machines[] = $visitor->getMachineClasses();
            } catch (\Throwable) {
                continue;
            }
        }

        return array_merge(...$machines);
    }
}
