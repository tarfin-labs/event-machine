<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine that delegates to DispatchToParentChildMachine.
 * Handles CHILD_PROGRESS event dispatched from child via dispatchToParent.
 */
class DispatchToParentParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'dispatch_to_parent_parent',
                'initial' => 'idle',
                'context' => [
                    'childProgress' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => DispatchToParentChildMachine::class,
                        'queue'   => 'child-queue',
                        'on'      => [
                            'CHILD_PROGRESS' => [
                                'target'  => 'processing',
                                'actions' => 'captureProgressAction',
                            ],
                        ],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureProgressAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childProgress', $event->payload['progress'] ?? null);
                    },
                ],
            ],
        );
    }
}
