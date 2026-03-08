<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionAResultAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBResultAction;

class ParallelDispatchChainedMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_dispatch_chained',
                'initial'        => 'phase_one',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'phase_one' => [
                        'type'   => 'parallel',
                        '@done'  => 'phase_two',
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
                    'phase_two' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'region_c' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetRegionAResultAction::class,
                                        'on'    => ['REGION_C_DONE' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_d' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetRegionBResultAction::class,
                                        'on'    => ['REGION_D_DONE' => 'finished'],
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
