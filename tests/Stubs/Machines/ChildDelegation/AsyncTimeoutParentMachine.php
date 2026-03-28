<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine with @timeout configured for async child delegation.
 *
 * Flow: idle → processing (async child with 30s timeout) → completed/timed_out/failed
 */
class AsyncTimeoutParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'timeout_parent',
                'initial' => 'idle',
                'context' => [
                    'output'  => null,
                    'error'   => null,
                    'timeout' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => SimpleChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@fail' => [
                            'target'  => 'failed',
                            'actions' => 'captureErrorAction',
                        ],
                        '@timeout' => [
                            'target'  => 'timed_out',
                            'timeout' => 30,
                            'actions' => 'captureTimeoutAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                    'timed_out' => ['type' => 'final'],
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
                    'captureTimeoutAction' => function (ContextManager $ctx): void {
                        $ctx->set('timeout', true);
                    },
                ],
            ],
        );
    }
}
