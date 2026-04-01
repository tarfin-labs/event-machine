<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\GrandchildMachine;

/**
 * Scenario for GrandchildMachine — @start → gc_done (final).
 * Used for nested child scenario testing (grandchild delegation within delegation).
 */
class GrandchildDoneScenario extends MachineScenario
{
    protected string $machine     = GrandchildMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'gc_done';
    protected string $description = 'Grandchild reaches final';

    protected function plan(): array
    {
        return [];
    }
}
