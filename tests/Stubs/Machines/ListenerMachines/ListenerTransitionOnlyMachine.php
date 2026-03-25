<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedTransitionAction;

/**
 * Machine with ONLY queued transition listener.
 * Separated from exit listener to prevent concurrent ListenerJob
 * lost-update when both fire for the same transition.
 */
class ListenerTransitionOnlyMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_transition_only',
                'initial' => 'idle',
                'context' => [
                    'transition_listener_ran' => false,
                ],
                'listen' => [
                    'transition' => [
                        QueuedTransitionAction::class => ['queue' => true],
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
