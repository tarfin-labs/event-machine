<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine that delegates to a child asynchronously via queue.
 *
 * Flow: idle → processing (async child on queue) → completed/failed
 * Used for testing async dispatch, completion, and cancellation.
 */
class AsyncParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'async_parent',
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
                        'machine' => SimpleChildMachine::class,
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
