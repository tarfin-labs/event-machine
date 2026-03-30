<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * QA parent machine that delegates to a failing child via Horizon.
 *
 * Tests that typed MachineFailure (SimpleFailure) is delivered to parent @fail
 * through real queue serialization.
 */
class QATypedFailingParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_failing_parent',
                'initial' => 'idle',
                'context' => [
                    'error' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => QATypedFailingChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'completed',
                        '@fail'   => [
                            'target'  => 'errored',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                    },
                ],
            ],
        );
    }
}
