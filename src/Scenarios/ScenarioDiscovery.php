<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Facades\File;

class ScenarioDiscovery
{
    /**
     * Discover all MachineScenario subclasses from the configured path.
     * Uses cache if available (from machine:scenario-cache).
     *
     * @return array<int, class-string<MachineScenario>>
     */
    public static function discover(): array
    {
        $cachePath = app()->bootstrapPath('cache/machine-scenarios.php');

        if (file_exists($cachePath)) {
            return require $cachePath;
        }

        $path = config('machine.scenarios.path');

        if (!is_string($path) || !is_dir($path)) {
            return [];
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

        return $scenarios;
    }
}
