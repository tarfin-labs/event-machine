<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Invalid scenario — valid enough to construct, but has a nonexistent target state.
 * Used for validation error tests (ScenarioValidator catches the invalid target).
 */
class InvalidScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'nonexistent_target_state';
    protected string $description = 'Invalid scenario for testing — target does not exist';
}
