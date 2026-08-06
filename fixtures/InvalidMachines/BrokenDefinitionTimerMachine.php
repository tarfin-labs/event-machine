<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use RuntimeException;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A machine whose definition() throws at build time.
 *
 * Lives outside every PSR-4 root so `machine:validate --all` never discovers it:
 * discovery walks PSR-4 directories, so an autoload mechanism alone would not
 * exclude a file under `tests/`. Loaded by the autoload-dev classmap and reachable
 * by fully-qualified name, which is how a test drives it deliberately.
 */
class BrokenDefinitionTimerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        throw new RuntimeException('definition build failed');
    }
}
