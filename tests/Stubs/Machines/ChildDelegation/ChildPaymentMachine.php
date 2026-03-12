<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A simple child machine stub for testing machine delegation.
 *
 * Flow: processing → completed (final)
 * Accepts PROCESS event to transition to final state.
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
                    'order_id'    => null,
                    'amount'      => 0,
                    'payment_id'  => null,
                    'receipt_url' => null,
                ],
                'states' => [
                    'processing' => [
                        'entry' => 'processPaymentAction',
                        'on'    => [
                            'PROCESS' => 'completed',
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'result' => 'paymentResultCalculator',
                    ],
                    'failed' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'processPaymentAction' => function (ContextManager $context): void {
                        $context->set('payment_id', 'pay_'.uniqid());
                        $context->set('receipt_url', 'https://example.com/receipt/'.uniqid());
                    },
                ],
                'calculators' => [
                    'paymentResultCalculator' => function (ContextManager $context): array {
                        return [
                            'payment_id'  => $context->get('payment_id'),
                            'receipt_url' => $context->get('receipt_url'),
                            'amount'      => $context->get('amount'),
                        ];
                    },
                ],
            ],
        );
    }
}
