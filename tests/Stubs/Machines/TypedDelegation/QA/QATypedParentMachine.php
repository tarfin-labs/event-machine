<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;

/**
 * QA parent machine with typed input delegation via Horizon.
 *
 * Delegates to QATypedImmediateChildMachine with input: PaymentInput::class.
 * Uses queue: 'child-queue' for async delegation through Horizon.
 * Captures child output in @done action for verification.
 */
class QATypedParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId'     => 'ORD-QA-001',
                    'amount'      => 250,
                    'childOutput' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => QATypedImmediateChildMachine::class,
                        'input'   => PaymentInput::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@fail' => [
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
