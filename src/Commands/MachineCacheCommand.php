<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Support\MachineDiscovery;

/**
 * Cache machine class discovery results for production.
 *
 * Similar to `route:cache` — scans for Machine subclasses with timer configs
 * and writes the result to a cache file for fast boot.
 */
class MachineCacheCommand extends Command
{
    protected $signature   = 'machine:cache';
    protected $description = 'Cache machine class discovery for production (like route:cache)';

    public function handle(): int
    {
        $cachePath = $this->getCachePath();

        $this->info('Scanning for machine classes...');

        $timerMachines = MachineDiscovery::findTimerMachines();

        $content = '<?php return '.var_export($timerMachines, true).';'.PHP_EOL;

        file_put_contents($cachePath, $content);

        $this->info('Machine cache written: '.count($timerMachines).' timer-configured machines found.');
        $this->info("Cache file: {$cachePath}");

        return self::SUCCESS;
    }

    protected function getCachePath(): string
    {
        return $this->laravel->bootstrapPath('cache/machines.php');
    }
}
