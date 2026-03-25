<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\ThrowOnceAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedExitAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\QueuedTransitionAction;

/**
 * Machine with queued listeners for all lifecycle hooks.
 *
 * - Entry: ThrowOnceAction (throws first call, succeeds on retry)
 * - Exit: QueuedExitAction (sets context flag)
 * - Transition: QueuedTransitionAction (sets context flag)
 */
class ListenerLifecycleMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_lifecycle',
                'initial' => 'idle',
                'context' => [
                    'listenerRan'           => false,
                    'exitListenerRan'       => false,
                    'transitionListenerRan' => false,
                ],
                'listen' => [
                    'entry' => [
                        ThrowOnceAction::class => ['queue' => true],
                    ],
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
