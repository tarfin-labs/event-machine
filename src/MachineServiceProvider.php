<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MachineServiceProvider extends PackageServiceProvider
{
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
            ->hasMigration('create_machine_events_table');
    }
}
