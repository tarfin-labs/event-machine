<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class ElevatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'initial' => 'state_b',
            'context' => [
                'model_a' => null,
                'value'   => 4,
            ],
            'states' => [
                'state_a' => [
                    'on' => [
                        'EVENT' => 'state_b',
                    ],
                ],
                'state_b' => [
                    'on' => [
                        '@always' => 'state_c',
                    ],
                ],
                'state_c' => [],
            ],
        ]);
    }
}
