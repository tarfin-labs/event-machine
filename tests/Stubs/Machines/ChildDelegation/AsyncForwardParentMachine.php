<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Parent machine with forward event routing to async child.
 *
 * Flow: idle → processing (async child with forward) → completed/failed
 * Forwards APPROVE and PARENT_UPDATE events to child.
 */
class AsyncForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'forward_parent',
                'initial' => 'idle',
                'context' => [
                    'result' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => SimpleChildMachine::class,
                        'queue'   => 'child-queue',
                        'forward' => [
                            'APPROVE',
                            'PARENT_UPDATE' => 'CHILD_UPDATE',
                        ],
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'captureResultAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'actions' => [
                    'captureResultAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('result', $event->payload['result'] ?? null);
                    },
                ],
            ],
        );
    }
}
