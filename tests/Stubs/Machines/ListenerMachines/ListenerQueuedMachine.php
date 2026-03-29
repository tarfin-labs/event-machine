<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\Actions\SyncMarkerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\Actions\QueuedMarkerAction;

/**
 * Machine with mixed sync + queued listeners for LocalQA testing.
 *
 * States: idle → active → done (final)
 * Listeners: SyncMarkerAction (sync) + QueuedMarkerAction (queued) on entry
 */
class ListenerQueuedMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'listener_queued',
                'initial' => 'idle',
                'context' => [
                    'syncListenerRan'     => false,
                    'queuedListenerRan'   => false,
                    'queuedListenerRanAt' => null,
                ],
                'listen' => [
                    'entry' => [
                        SyncMarkerAction::class,
                        [QueuedMarkerAction::class, '@queue' => true],
                    ],
                ],
                'states' => [
                    'idle'   => ['on' => ['ACTIVATE' => 'active']],
                    'active' => ['on' => ['FINISH' => 'done']],
                    'done'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
