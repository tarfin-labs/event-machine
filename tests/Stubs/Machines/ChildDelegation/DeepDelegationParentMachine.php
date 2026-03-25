<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine for three-level delegation chain: This → GrandchildDelegation → ImmediateChild.
 * Used for testing deep async delegation via Horizon.
 */
class DeepDelegationParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'deep_delegation_parent',
                'initial' => 'idle',
                'context' => [
                    'chain_completed' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => GrandchildDelegationMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'completed',
                            'actions' => 'markChainCompleteAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'markChainCompleteAction' => function (ContextManager $ctx): void {
                        $ctx->set('chain_completed', true);
                    },
                ],
            ],
        );
    }
}
