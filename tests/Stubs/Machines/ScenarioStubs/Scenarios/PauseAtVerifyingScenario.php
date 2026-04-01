<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;

/**
 * Targets 'verifying' (delegation state) which is NOT final.
 * executeChildScenario should return null (child paused).
 */
class PauseAtVerifyingScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestChildMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'verifying';
    protected string $description = 'Pause at verifying — child not final';

    protected function plan(): array
    {
        return [];
    }
}
