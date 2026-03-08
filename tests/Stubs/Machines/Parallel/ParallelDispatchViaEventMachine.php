<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionAResultAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBResultAction;

/**
 * Machine that starts at a non-parallel state and transitions
 * into a parallel state via an event. Used to test the transition()
 * method entering a parallel state correctly.
 */
class ParallelDispatchViaEventMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_dispatch_via_event',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START_PROCESSING' => 'processing'],
                    ],
                    'processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetRegionAResultAction::class,
                                        'on'    => ['REGION_A_DONE' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
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
        );
    }
}
