<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that throws an exception on entry (for @fail testing).
 */
class FailingChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'failing_child',
                'initial' => 'start',
                'context' => [],
                'states'  => [
                    'start' => [
                        'entry' => 'throwAction',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'throwAction' => function (): void {
                        throw new \RuntimeException('Payment gateway down');
                    },
                ],
            ],
        );
    }
}
