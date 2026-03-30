<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\SimpleFailure;

/**
 * QA middle machine that delegates to a failing child for deep delegation failure testing.
 *
 * This → QATypedFailingChildMachine (throws)
 * When child fails, this machine routes to 'failed' (final), which propagates
 * failure upward to the grandparent via ChildMachineCompletionJob.
 */
class QATypedFailingMiddleMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_failing_middle',
                'failure' => SimpleFailure::class,
                'initial' => 'delegating',
                'context' => [
                    'error' => null,
                ],
                'states' => [
                    'delegating' => [
                        'machine' => QATypedFailingChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'completed',
                        '@fail'   => [
                            'target'  => 'failed',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
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
