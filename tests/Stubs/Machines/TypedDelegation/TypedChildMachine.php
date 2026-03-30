<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

class TypedChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'typed_child',
                'input'   => PaymentInput::class,
                'failure' => PaymentFailure::class,
                'initial' => 'processing',
                'context' => [
                    'paymentId' => null,
                    'status'    => 'pending',
                ],
                'states' => [
                    'processing' => [
                        'on' => [
                            'PAYMENT_COMPLETED' => 'completed',
                            'PAYMENT_FAILED'    => 'failed',
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => PaymentOutput::class,
                    ],
                    'failed' => [
                        'type'   => 'final',
                        'output' => ['errorCode', 'status'],
                    ],
                ],
            ],
        );
    }
}
