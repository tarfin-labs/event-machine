<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine with forward event routing to ForwardableChildMachine.
 *
 * Flow: idle → processing (async child with forward) → completed/failed
 * Forwards APPROVE and PARENT_UPDATE→CHILD_UPDATE events to child.
 *
 * Unlike AsyncForwardParentMachine, this uses ForwardableChildMachine
 * which actually handles APPROVE and CHILD_UPDATE events.
 */
class ForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'forward_parent_v2',
                'initial' => 'idle',
                'context' => [
                    'output' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ForwardableChildMachine::class,
                        'queue'   => 'default',
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
                'actions' => [
                    'captureResultAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('result', $event->payload['output'] ?? null);
                    },
                ],
            ],
        );
    }
}
