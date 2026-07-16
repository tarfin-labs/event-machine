<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Listeners\CountingEntryAction;

/**
 * Job-actor parent with a queued entry listener on every state.
 *
 * Flow: idle → (START) → invoking [job] → (@done) → completed
 *
 * The listener for the `completed` entry is dispatched inside the
 * ChildMachineCompletionJob's persist() — while that job still holds the
 * parent's lock. On the sync queue the ListenerJob runs inline and must
 * detect the held lock (re-entrant) instead of blocking on its own stack.
 */
class QueuedListenerJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'queued_listener_job_parent',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId'      => 'ord_listener_001',
                    'listenerRuns' => 0,
                ],
                'listen' => [
                    'entry' => [
                        [CountingEntryAction::class, '@queue' => true],
                    ],
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'invoking'],
                    ],
                    'invoking' => [
                        'job'   => SuccessfulTestJob::class,
                        'input' => ['orderId'],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
