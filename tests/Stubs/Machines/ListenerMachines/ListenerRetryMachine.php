<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\ThrowOnceAction;

/**
 * Machine with only ThrowOnceAction as queued entry listener.
 * Used for testing listener retry behavior in isolation.
 */
class ListenerRetryMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_retry',
                'initial' => 'idle',
                'context' => [
                    'listener_ran' => false,
                ],
                'listen' => [
                    'entry' => [
                        ThrowOnceAction::class => ['queue' => true],
                    ],
                ],
                'states' => [
                    'idle' => [
                        'on' => ['ACTIVATE' => 'active'],
                    ],
                    'active' => ['type' => 'final'],
                ],
            ],
        );
    }
}
