<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionAEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionBEntryAction;

class ParallelDispatchFailToParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_fail_to_parallel',
                'initial'        => 'primary_processing',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'primary_processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'onFail' => 'fallback_processing',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working_a',
                                'states'  => [
                                    'working_a' => [
                                        'entry' => RegionAEntryAction::class,
                                        'on'    => ['REGION_A_DONE' => 'finished_a'],
                                    ],
                                    'finished_a' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working_b',
                                'states'  => [
                                    'working_b' => [
                                        'entry' => RegionBEntryAction::class,
                                        'on'    => ['REGION_B_DONE' => 'finished_b'],
                                    ],
                                    'finished_b' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'fallback_processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'states' => [
                            'fallback_a' => [
                                'initial' => 'retrying_a',
                                'states'  => [
                                    'retrying_a' => [
                                        'entry' => RegionAEntryAction::class,
                                        'on'    => ['FALLBACK_A_DONE' => 'done_a'],
                                    ],
                                    'done_a' => ['type' => 'final'],
                                ],
                            ],
                            'fallback_b' => [
                                'initial' => 'retrying_b',
                                'states'  => [
                                    'retrying_b' => [
                                        'entry' => RegionBEntryAction::class,
                                        'on'    => ['FALLBACK_B_DONE' => 'done_b'],
                                    ],
                                    'done_b' => ['type' => 'final'],
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
