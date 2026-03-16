<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Commands\MachineClassVisitor;

/**
 * Discovers Machine subclasses in the application using PhpParser.
 *
 * Shared by MachineCacheCommand and MachineServiceProvider to avoid
 * duplicating the discovery + timer-check logic.
 */
class MachineDiscovery
{
    /**
     * Find all Machine subclasses that have timer-configured transitions.
     *
     * @return array<string> Machine class FQCNs with timer config
     */
    public static function findTimerMachines(): array
    {
        $allMachines   = self::findAllMachineClasses();
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
    public static function findAllMachineClasses(): array
    {
        if (!is_dir(app_path())) {
            return [];
        }

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

        return array_merge(...($machines !== [] ? $machines : [[]]));
    }
}
