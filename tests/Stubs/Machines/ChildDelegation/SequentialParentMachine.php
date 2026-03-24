<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Parent that delegates to two children sequentially.
 *
 * Flow: idle → (START) → step_a [ImmediateChildMachine] → (@done) → step_b [ImmediateChildMachine] → (@done) → completed
 */
class SequentialParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'sequential_parent',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'step_a'],
                    ],
                    'step_a' => [
                        'machine' => ImmediateChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'step_b',
                        '@fail'   => 'failed',
                    ],
                    'step_b' => [
                        'machine' => ImmediateChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'completed',
                        '@fail'   => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
