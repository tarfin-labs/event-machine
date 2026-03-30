<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

/**
 * QA child machine with typed contracts that auto-completes.
 *
 * Starts in 'processing', entry action sets context from input, then transitions to 'completed'.
 * Uses typed input/output/failure contracts for end-to-end queue serialization testing.
 */
class QATypedImmediateChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_immediate_child',
                'input'   => PaymentInput::class,
                'failure' => PaymentFailure::class,
                'initial' => 'processing',
                'context' => [
                    'paymentId' => 'pay_qa_001',
                    'status'    => 'pending',
                    'orderId'   => null,
                    'amount'    => null,
                    'currency'  => null,
                ],
                'states' => [
                    'processing' => [
                        'entry' => 'markProcessedAction',
                        'on'    => [
                            '@always' => 'completed',
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => PaymentOutput::class,
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'markProcessedAction' => function (ContextManager $ctx): void {
                        $ctx->set('status', 'processed');
                    },
                ],
            ],
        );
    }
}
