<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;

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

        $timerMachines = $this->discoverTimerMachines();

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

    /**
     * @return array<string>
     */
    protected function discoverTimerMachines(): array
    {
        $parser    = (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
        $traverser = new NodeTraverser();
        $visitor   = new MachineClassVisitor();
        $traverser->addVisitor($visitor);

        $allMachines   = [];
        $timerMachines = [];

        if (!is_dir(app_path())) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in(app_path());

        foreach ($finder as $file) {
            try {
                $code = $file->getContents();
                $ast  = $parser->parse($code);

                $visitor->setCurrentFile($file->getRealPath());
                $traverser->traverse($ast);

                $allMachines[] = $visitor->getMachineClasses();
            } catch (\Throwable) {
                continue;
            }
        }

        $allMachines = array_merge(...$allMachines);

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
}
