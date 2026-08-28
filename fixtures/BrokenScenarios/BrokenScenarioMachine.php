<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\BrokenScenarios;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A valid machine whose Scenarios/ directory contains one good scenario and two broken ones.
 *
 * It lives in fixtures/ with its own directory so the broken files sit in a Scenarios/ folder
 * that belongs to this machine alone — scenario discovery derives that folder from the machine
 * file's location, so a shared one would leak the broken files into other machines' counts.
 */
class BrokenScenarioMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'broken_scenario_probe',
            'initial' => 'idle',
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ]);
    }
}
