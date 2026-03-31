<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;

/**
 * Fire-and-forget job actor: dispatches job and transitions to target immediately.
 *
 * Flow: idle → (START) → dispatching [job + target] → waiting → (FINISH) → completed
 * The job runs independently — parent doesn't wait for completion.
 */
class FireAndForgetJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'ff_job_parent',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId' => 'ord_ff_001',
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'dispatching'],
                    ],
                    'dispatching' => [
                        'job'    => SuccessfulTestJob::class,
                        'input'  => ['orderId'],
                        'target' => 'waiting', // fire-and-forget: target without @done
                    ],
                    'waiting' => [
                        'on' => ['FINISH' => 'completed'],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
