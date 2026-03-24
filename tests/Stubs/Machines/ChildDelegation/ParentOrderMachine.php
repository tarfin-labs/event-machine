<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

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
                    'order_id'     => null,
                    'total_amount' => 0,
                    'payment_id'   => null,
                    'receipt_url'  => null,
                ],
                'states' => [
                    'awaiting_payment' => [
                        'on' => [
                            'START_PAYMENT' => 'processing_payment',
                        ],
                    ],
                    'processing_payment' => [
                        'machine' => ChildPaymentMachine::class,
                        'with'    => [
                            'order_id',
                            'amount' => 'total_amount',
                        ],
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'storePaymentResultAction',
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
                'context' => GenericContext::class,
                'actions' => [
                    'storePaymentResultAction' => function (
                        ContextManager $context,
                        EventBehavior $event
                    ): void {
                        $context->set('payment_id', $event->payload['result']['payment_id'] ?? null);
                        $context->set('receipt_url', $event->payload['result']['receipt_url'] ?? null);
                    },
                ],
            ],
        );
    }
}
