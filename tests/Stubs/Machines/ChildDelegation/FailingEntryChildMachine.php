<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use RuntimeException;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that throws RuntimeException in entry action.
 * Used for testing fire-and-forget failure isolation.
 */
class FailingEntryChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'failing_entry_child',
                'initial' => 'exploding',
                'context' => [],
                'states'  => [
                    'exploding' => [
                        'entry' => 'throwAction',
                        'on'    => ['RECOVER' => 'recovered'],
                    ],
                    'recovered' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'throwAction' => function (ContextManager $ctx): void {
                        throw new RuntimeException('Intentional entry action failure for testing');
                    },
                ],
            ],
        );
    }
}
