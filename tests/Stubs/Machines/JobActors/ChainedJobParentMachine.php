<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;

/**
 * Machine with chained job states to test the infinite loop bug.
 *
 * Flow: idle → (START) → step_one [job] → (@done) → step_two [job] → (@done) → completed
 *
 * Without the fix, entering step_one via transition in test mode (sync queue)
 * causes: step_one → ChildJobJob → @done → step_two → ChildJobJob → @done → completed
 * all in one synchronous call, crashing with memory exhaustion if the chain is long.
 */
class ChainedJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'chained_job_parent',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId' => 'ord_chain_001',
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'step_one'],
                    ],
                    'step_one' => [
                        'job'   => SuccessfulTestJob::class,
                        'input' => ['orderId'],
                        '@done' => 'step_two',
                        '@fail' => 'failed',
                    ],
                    'step_two' => [
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
