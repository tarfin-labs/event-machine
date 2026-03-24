<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Parent machine with parallel regions that delegate to child machines.
 *
 * Simulates a real-world scenario like CarSalesMachine verification:
 * - Region A: async child machine (via queue)
 * - Region B: sync child machine (immediate completion)
 *
 * Both regions must complete before @done fires on the parallel state.
 */
class ParallelDelegationParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'parallel_delegation_parent',
                'initial' => 'idle',
                'context' => [
                    'result' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'verification'],
                    ],
                    'verification' => [
                        'type'   => 'parallel',
                        'states' => [
                            'region_a' => [
                                'initial' => 'running',
                                'states'  => [
                                    'running' => [
                                        'machine' => ImmediateChildMachine::class,
                                        'queue'   => 'child-queue',
                                        '@done'   => 'completed',
                                        '@fail'   => 'failed',
                                    ],
                                    'completed' => ['type' => 'final'],
                                    'failed'    => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'running',
                                'states'  => [
                                    'running' => [
                                        'machine' => ImmediateChildMachine::class,
                                        '@done'   => 'completed',
                                    ],
                                    'completed' => ['type' => 'final'],
                                ],
                            ],
                        ],
                        '@done' => 'done',
                        '@fail' => 'failed',
                    ],
                    'done'   => ['type' => 'final'],
                    'failed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
