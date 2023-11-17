<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class ScenarioMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'stateA',
                'scenario_enabled' => true,
                'context' => [
                    'modelA' => null,
                    'value' => 4,
                ],
                'states' => [
                    'stateA' => [
                        'on' => [
                            'EVENT_B' => 'stateB',
                        ],
                    ],
                    'stateB' => [
                        'on' => [
                            'EVENT_C' => 'stateC',
                        ],
                    ],
                    'stateC' => [],
                ],
            ],
            scenarios: [
                'test' => [
                    'stateA' => [
                        'on' => [
                            'EVENT_B' => 'stateC',
                        ],
                    ],
                ]
            ]
        );
    }
}
