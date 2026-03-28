<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine that delegates to ImmediateChildMachine (auto-completes).
 *
 * Same as AsyncParentMachine but uses ImmediateChildMachine which starts
 * in a final state, so the async delegation completes immediately.
 * Used for LocalQA tests where child must actually complete via Horizon.
 */
class AsyncAutoCompleteParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'async_auto_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId' => null,
                    'output'  => null,
                    'error'   => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START'   => 'processing',
                            'ADVANCE' => 'skipped',
                        ],
                    ],
                    'processing' => [
                        'machine' => ImmediateChildMachine::class,
                        'with'    => ['orderId'],
                        'queue'   => 'child-queue',
                        'on'      => [
                            'CANCEL' => 'skipped',
                        ],
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@fail' => [
                            'target'  => 'failed',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'skipped'   => ['type' => 'final'],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childOutput', $event->payload['output'] ?? null);
                    },
                    'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                    },
                ],
            ],
        );
    }
}
