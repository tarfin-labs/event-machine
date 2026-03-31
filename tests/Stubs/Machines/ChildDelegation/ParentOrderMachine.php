<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A parent machine stub that delegates to a child machine.
 *
 * Flow: awaiting_payment → processing_payment (delegates to ChildPaymentMachine) → completed
 * Uses machine key with `with` context transfer and @done/@fail handling.
 */
class ParentOrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'parent_order',
                'initial' => 'awaiting_payment',
                'context' => [
                    'orderId'     => null,
                    'totalAmount' => 0,
                    'paymentId'   => null,
                    'receiptUrl'  => null,
                ],
                'states' => [
                    'awaiting_payment' => [
                        'on' => [
                            'START_PAYMENT' => 'processing_payment',
                        ],
                    ],
                    'processing_payment' => [
                        'machine' => ChildPaymentMachine::class,
                        'input'   => [
                            'orderId',
                            'amount' => 'totalAmount',
                        ],
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'storePaymentOutputAction',
                        ],
                        '@fail' => 'payment_failed',
                    ],
                    'completed' => [
                        'type' => 'final',
                    ],
                    'payment_failed' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'storePaymentOutputAction' => function (
                        ContextManager $context,
                        EventBehavior $event
                    ): void {
                        $context->set('paymentId', $event->payload['output']['paymentId'] ?? null);
                        $context->set('receiptUrl', $event->payload['output']['receiptUrl'] ?? null);
                    },
                ],
            ],
        );
    }
}
