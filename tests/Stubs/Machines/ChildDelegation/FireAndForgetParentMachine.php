<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Fire-and-forget parent: stay in state pattern.
 *
 * Dispatches ImmediateChildMachine to queue without @done.
 * Parent stays in 'processing' and handles its own events.
 */
class FireAndForgetParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'ff_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ImmediateChildMachine::class,
                        'input'   => ['orderId'],
                        'queue'   => 'child-queue',
                        // No @done → fire-and-forget, stay in state
                        'on' => [
                            'FINISH' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
