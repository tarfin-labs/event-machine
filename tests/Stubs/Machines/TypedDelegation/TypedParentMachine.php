<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;

class TypedParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'typed_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId' => 'ORD-1',
                    'amount'  => 150,
                    'result'  => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => TypedChildMachine::class,
                        'input'   => PaymentInput::class,
                        '@done'   => ['target' => 'completed'],
                        '@fail'   => ['target' => 'errored'],
                    ],
                    'completed' => [
                        'type' => 'final',
                    ],
                    'errored' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}
