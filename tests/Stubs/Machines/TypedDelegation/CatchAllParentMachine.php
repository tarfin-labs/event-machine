<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class CatchAllParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'catch_all_parent',
                'initial' => 'idle',
                'context' => [
                    'result' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'reviewing'],
                    ],
                    'reviewing' => [
                        'machine' => DiscriminatedChildMachine::class,
                        '@done'   => ['target' => 'completed'],
                        '@fail'   => ['target' => 'errored'],
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
