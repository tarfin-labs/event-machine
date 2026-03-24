<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

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
                    'result'  => null,
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
                            'actions' => 'captureResultAction',
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
                'context' => GenericContext::class,
                'actions' => [
                    'captureResultAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('result', $event->payload['result'] ?? null);
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
