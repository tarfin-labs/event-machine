<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that throws but has NO failure declaration — tests raw exception fallback.
 */
class NoFailureChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'no_failure_child',
                'initial' => 'processing',
                'context' => [],
                'states'  => [
                    'processing' => [
                        'entry' => function (): void {
                            throw new \RuntimeException('Unhandled error', 500);
                        },
                    ],
                ],
            ],
        );
    }
}
