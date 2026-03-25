<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A simple child machine stub for testing machine delegation.
 *
 * Flow: processing → completed (final) via @always auto-transition.
 * Entry action sets payment_id and receipt_url, then auto-transitions.
 * Has a ResultBehavior that returns payment result data.
 */
class ChildPaymentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'child_payment',
                'initial' => 'processing',
                'context' => [
                    'orderId'    => null,
                    'amount'     => 0,
                    'paymentId'  => null,
                    'receiptUrl' => null,
                ],
                'states' => [
                    'processing' => [
                        'entry' => 'processPaymentAction',
                        'on'    => [
                            '@always' => 'completed',
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'result' => function (ContextManager $context): array {
                            return [
                                'paymentId'  => $context->get('paymentId'),
                                'receiptUrl' => $context->get('receiptUrl'),
                                'amount'     => $context->get('amount'),
                            ];
                        },
                    ],
                    'failed' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'processPaymentAction' => function (ContextManager $context): void {
                        $context->set('paymentId', 'pay_'.uniqid());
                        $context->set('receiptUrl', 'https://example.com/receipt/'.uniqid());
                    },
                ],
            ],
        );
    }
}
