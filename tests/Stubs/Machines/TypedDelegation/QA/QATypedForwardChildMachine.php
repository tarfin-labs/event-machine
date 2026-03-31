<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;

/**
 * QA child machine for forward endpoint testing with typed output.
 *
 * Flow: awaiting_input → (SUBMIT_PAYMENT) → completed (final, output: PaymentOutput)
 *
 * The SUBMIT_PAYMENT event sets paymentId and status in context,
 * which PaymentOutput::fromContext() reads when computing output.
 */
class QATypedForwardChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_forward_child',
                'initial' => 'awaiting_input',
                'context' => [
                    'paymentId' => null,
                    'status'    => 'pending',
                ],
                'states' => [
                    'awaiting_input' => [
                        'on' => [
                            'SUBMIT_PAYMENT' => [
                                'target'  => 'completed',
                                'actions' => 'processPaymentAction',
                            ],
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => PaymentOutput::class,
                    ],
                ],
            ],
            behavior: [
                'events' => [
                    'SUBMIT_PAYMENT' => SubmitPaymentEvent::class,
                ],
                'actions' => [
                    'processPaymentAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('paymentId', 'pay_fwd_001');
                        $ctx->set('status', 'charged');
                    },
                ],
            ],
        );
    }
}
