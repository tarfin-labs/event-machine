<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\ScenarioDrift;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A machine that persists, so it has a machine_current_states row to carry a stored scenario.
 *
 * The scenario restore path reads that row, and a machine which does not persist has none — a
 * test using one would exercise nothing while appearing to pass. It has no Scenarios/ directory,
 * so the machine:scenario-validate sweep does not pick it up.
 */
class PersistingScenarioMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'             => 'persisting_scenario_probe',
            'initial'        => 'idle',
            'should_persist' => true,
            'states'         => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ]);
    }
}
