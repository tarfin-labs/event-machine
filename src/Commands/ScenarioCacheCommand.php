<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

class ScenarioCacheCommand extends Command
{
    protected $signature   = 'machine:scenario-cache';
    protected $description = 'Cache scenario class discovery for production-like staging environments';

    public function handle(): int
    {
        $path = config('machine.scenarios.path');

        if (!is_dir($path)) {
            $this->warn("Scenarios path does not exist: {$path}");

            return self::SUCCESS;
        }

        $scenarios = [];

        foreach (File::allFiles($path) as $file) {
            $contents  = file_get_contents($file->getPathname());
            $namespace = null;
            $class     = null;

            if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
                $namespace = $matches[1];
            }

            if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
                $class = $matches[1];
            }

            if ($namespace !== null && $class !== null) {
                $fqcn = $namespace.'\\'.$class;
                if (class_exists($fqcn) && is_subclass_of($fqcn, MachineScenario::class)) {
                    $scenarios[] = $fqcn;
                }
            }
        }

        $cachePath = app()->bootstrapPath('cache/machine-scenarios.php');

        File::put($cachePath, '<?php return '.var_export($scenarios, true).';');

        $this->info('Scenario classes cached successfully. Found '.count($scenarios).' scenario(s).');

        return self::SUCCESS;
    }
}
