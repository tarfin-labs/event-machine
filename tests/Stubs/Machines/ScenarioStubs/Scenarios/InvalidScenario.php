<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Invalid scenario — missing $target property.
 * Used for validation error tests.
 *
 * Note: Cannot be instantiated without catching ScenarioConfigurationException.
 */
class InvalidScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    // $target intentionally missing
    protected string $description = 'Invalid scenario for testing';
}
