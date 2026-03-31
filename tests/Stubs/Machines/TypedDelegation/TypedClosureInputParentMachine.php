<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;

class TypedClosureInputParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'typed_closure_parent',
                'initial' => 'idle',
                'context' => [
                    'currentOrderId' => 'ORD-CLOSURE',
                    'totalAmount'    => 250,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => TypedChildMachine::class,
                        'input'   => function (ContextManager $ctx): PaymentInput {
                            return new PaymentInput(
                                orderId: $ctx->get('currentOrderId'),
                                amount: $ctx->get('totalAmount'),
                            );
                        },
                        '@done' => ['target' => 'completed'],
                        '@fail' => ['target' => 'errored'],
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
