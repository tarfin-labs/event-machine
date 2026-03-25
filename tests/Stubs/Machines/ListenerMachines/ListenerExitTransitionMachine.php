<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedExitAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedTransitionAction;

/**
 * Machine with queued exit + transition listeners only (no ThrowOnceAction).
 * Avoids cross-test static counter interference in Horizon workers.
 */
class ListenerExitTransitionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_exit_transition',
                'initial' => 'idle',
                'context' => [
                    'exit_listener_ran'       => false,
                    'transition_listener_ran' => false,
                ],
                'listen' => [
                    'exit' => [
                        QueuedExitAction::class => ['queue' => true],
                    ],
                    'transition' => [
                        QueuedTransitionAction::class => ['queue' => true],
                    ],
                ],
                'states' => [
                    'idle' => [
                        'on' => ['ACTIVATE' => 'active'],
                    ],
                    'active' => [
                        'on' => ['FINISH' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
        );
    }
}
