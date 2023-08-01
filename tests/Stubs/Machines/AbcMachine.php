<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class AbcMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'initial' => 'stateB',
            'context' => [
                'modelA' => null,
                'value'  => 4,
            ],
            'states' => [
                'stateA' => [
                    'on' => [
                        'EVENT' => 'stateB',
                    ],
                ],
                'stateB' => [
                    'on' => [
                        '@always' => 'stateC',
                    ],
                ],
                'stateC' => [],
            ],
        ]);
    }
}
