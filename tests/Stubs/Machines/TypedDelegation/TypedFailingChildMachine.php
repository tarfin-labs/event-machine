<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

class TypedFailingChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'typed_failing_child',
                'failure' => PaymentFailure::class,
                'initial' => 'processing',
                'context' => [],
                'states'  => [
                    'processing' => [
                        'entry' => function (): void {
                            throw new \RuntimeException('Gateway timeout', 504);
                        },
                    ],
                ],
            ],
        );
    }
}
