<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that delegates to a grandchild (ImmediateChildMachine).
 * Used for testing three-level delegation chain: Parent → This → Grandchild.
 */
class GrandchildDelegationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'grandchild_delegation',
                'initial' => 'delegating',
                'context' => [
                    'grandchild_result' => null,
                ],
                'states' => [
                    'delegating' => [
                        'machine' => ImmediateChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'completed',
                        '@fail'   => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
