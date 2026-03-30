<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;

/**
 * QA middle machine for three-level typed delegation chain.
 *
 * Grandparent → This → QATypedImmediateChildMachine
 *
 * Accepts PaymentInput, delegates to child with same input,
 * captures child output and produces its own PaymentOutput on completion.
 * Uses queue: 'child-queue' for async delegation.
 */
class QATypedMiddleMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_middle',
                'input'   => PaymentInput::class,
                'initial' => 'delegating',
                'context' => [
                    'paymentId' => null,
                    'status'    => 'pending',
                    'orderId'   => null,
                    'amount'    => null,
                    'currency'  => null,
                ],
                'states' => [
                    'delegating' => [
                        'machine' => QATypedImmediateChildMachine::class,
                        'input'   => PaymentInput::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'completed',
                            'actions' => 'captureChildOutputAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => PaymentOutput::class,
                    ],
                    'failed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureChildOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $output = $event->payload['output'] ?? [];
                        $ctx->set('paymentId', $output['paymentId'] ?? $ctx->get('paymentId'));
                        $ctx->set('status', $output['status'] ?? 'completed');
                    },
                ],
            ],
        );
    }
}
