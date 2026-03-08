<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionAEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionBEntryAction;

class ParallelDispatchChainedMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_chained',
                'initial'        => 'phase_one',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'phase_one' => [
                        'type'   => 'parallel',
                        'onDone' => 'phase_two',
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
                    'phase_two' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'states' => [
                            'region_c' => [
                                'initial' => 'working_c',
                                'states'  => [
                                    'working_c' => [
                                        'entry' => RegionAEntryAction::class,
                                        'on'    => ['REGION_C_DONE' => 'finished_c'],
                                    ],
                                    'finished_c' => ['type' => 'final'],
                                ],
                            ],
                            'region_d' => [
                                'initial' => 'working_d',
                                'states'  => [
                                    'working_d' => [
                                        'entry' => RegionBEntryAction::class,
                                        'on'    => ['REGION_D_DONE' => 'finished_d'],
                                    ],
                                    'finished_d' => ['type' => 'final'],
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
