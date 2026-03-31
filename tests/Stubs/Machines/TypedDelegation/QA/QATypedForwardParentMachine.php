<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * QA parent machine with forward endpoint for typed output testing.
 *
 * Delegates to QATypedForwardChildMachine with forward: ['SUBMIT_PAYMENT'].
 * When SUBMIT_PAYMENT is forwarded to the child, child transitions to
 * 'completed' (final) with output: PaymentOutput, which triggers
 * ChildMachineCompletionJob back to parent.
 *
 * Used for testing: forward event → child final with typed output → parent @done.
 */
class QATypedForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_forward_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId'     => 'ORD-FWD-QA',
                    'childOutput' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => QATypedForwardChildMachine::class,
                        'queue'   => 'default',
                        'forward' => [
                            'SUBMIT_PAYMENT',
                        ],
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@fail' => 'errored',
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => QAStartEvent::class,
                ],
                'actions' => [
                    'captureOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childOutput', $event->payload['output'] ?? null);
                    },
                ],
            ],
            endpoints: [
                'START',
            ],
        );
    }
}
