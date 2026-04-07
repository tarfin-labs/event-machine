<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class ContextRoundTripMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'context_round_trip',
                'initial' => 'idle',
                'context' => [
                    'intVal'      => 42,
                    'floatVal'    => 3.14,
                    'stringVal'   => 'hello',
                    'boolTrue'    => true,
                    'boolFalse'   => false,
                    'nullVal'     => null,
                    'nestedArray' => ['a' => 1, 'b' => ['c' => 2]],
                    'emptyArray'  => [],
                    'zeroInt'     => 0,
                    'emptyString' => '',
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'NEXT' => 'done',
                        ],
                    ],
                    'done' => [
                        'on' => [
                            'MODIFY' => [
                                'actions' => 'modifyContextAction',
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'modifyContextAction' => function (ContextManager $context): void {
                        $context->set('intVal', 99);
                    },
                ],
            ],
        );
    }
}
