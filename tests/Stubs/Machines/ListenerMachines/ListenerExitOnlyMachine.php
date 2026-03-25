<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedExitAction;

/**
 * Machine with ONLY queued exit listener.
 * Separated from transition listener to prevent concurrent ListenerJob
 * lost-update when both fire for the same transition.
 */
class ListenerExitOnlyMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_exit_only',
                'initial' => 'idle',
                'context' => [
                    'exit_listener_ran' => false,
                ],
                'listen' => [
                    'exit' => [
                        QueuedExitAction::class => ['queue' => true],
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
