<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\WriteToDbAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBResultAction;

/**
 * Test machine for transaction safety verification.
 *
 * Region A flow:
 *   working (entry: ProcessRegionAAction → raises REGION_A_PROCESSED)
 *     → REGION_A_PROCESSED → finished (entry: WriteToDbAction → DB INSERT)
 *
 * In ParallelRegionJob::handle():
 *   1. ProcessRegionAAction runs OUTSIDE lock (line 70)
 *   2. REGION_A_PROCESSED is captured as a raised event
 *   3. Inside lock, transition(REGION_A_PROCESSED) processes the event
 *   4. Target finished's entry action (WriteToDbAction) does DB INSERT ← INSIDE LOCK
 *   5. persist() writes machine_events ← INSIDE LOCK
 *
 * If persist fails at step 5, the INSERT from step 4 should be rolled back (Fix B).
 */
class ParallelDispatchDbWriteMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_dispatch_db_write',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        // Entry action sets context + raises REGION_A_PROCESSED
                                        'entry' => ProcessRegionAAction::class,
                                        'on'    => ['REGION_A_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => [
                                        'type' => 'final',
                                        // Entry action does DB INSERT — runs inside lock
                                        // when raised event is processed
                                        'entry' => WriteToDbAction::class,
                                    ],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetRegionBResultAction::class,
                                        'on'    => ['REGION_B_DONE' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
