<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * QA grandparent for deep delegation failure chain via Horizon.
 *
 * This → QATypedFailingMiddleMachine → QATypedFailingChildMachine (throws)
 *
 * Tests that failure propagates through three levels:
 * child throws → middle @fail → middle reaches 'failed' final state →
 * ChildMachineCompletionJob reports failure to grandparent → grandparent @fail.
 */
class QATypedFailingGrandparentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_failing_grandparent',
                'initial' => 'idle',
                'context' => [
                    'error' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => QATypedFailingMiddleMachine::class,
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
