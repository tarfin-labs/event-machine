<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Definition\EventMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsOddGuard;

class IsOddMachine extends EventMachine
{
    public static function build(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'context' => [],
            'states'  => [
                'stateA' => [
                    'on' => [
                        'EVENT' => [
                            'target' => 'stateB',
                            'guards' => IsOddGuard::class,
                        ],
                    ],
                ],
                'stateB' => [],
            ],
        ]);
    }
}
