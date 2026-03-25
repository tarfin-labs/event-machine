<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that accepts forwarded events from a parent.
 *
 * Flow: idle → (APPROVE) → approved (final)
 *              (CHILD_UPDATE) → updated → (COMPLETE) → done (final)
 *
 * Used to test forward event routing from AsyncForwardParentMachine.
 */
class ForwardableChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'forwardable_child',
                'initial' => 'idle',
                'context' => [
                    'updatedValue' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'APPROVE'      => 'approved',
                            'CHILD_UPDATE' => [
                                'target'  => 'updated',
                                'actions' => 'captureUpdateAction',
                            ],
                            'COMPLETE' => 'done',
                        ],
                    ],
                    'updated' => [
                        'on' => [
                            'COMPLETE' => 'done',
                        ],
                    ],
                    'approved' => ['type' => 'final'],
                    'done'     => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureUpdateAction' => function (ContextManager $ctx): void {
                        $ctx->set('updatedValue', 'received');
                    },
                ],
            ],
        );
    }
}
