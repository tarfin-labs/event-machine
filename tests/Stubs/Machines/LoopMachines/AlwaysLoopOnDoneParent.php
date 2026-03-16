<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine where @done routes to a state with @always loop.
 *
 * Flow: idle → (START) → delegating [async child] → (@done) → loop_a → (@always) → loop_b → (@always) → loop_a
 * Used for testing async child @done + infinite loop interaction.
 *
 * NOTE: @done routing uses executeChildTransitionBranch which bypasses transition().
 * The @always loop may NOT fire — this is a known limitation.
 */
class AlwaysLoopOnDoneParent extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'loop_done_parent',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => AlwaysLoopImmediateChild::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'loop_a',
                        '@fail'   => 'failed',
                    ],
                    'loop_a' => [
                        'on' => ['@always' => 'loop_b'],
                    ],
                    'loop_b' => [
                        'on' => ['@always' => 'loop_a'],
                    ],
                    'failed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
