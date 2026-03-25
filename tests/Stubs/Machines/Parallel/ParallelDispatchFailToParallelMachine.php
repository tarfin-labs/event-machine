<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionAResultAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBResultAction;

class ParallelDispatchFailToParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_dispatch_fail_to_parallel',
                'initial'        => 'primary_processing',
                'should_persist' => true,
                'context'        => [
                    'regionAResult' => null,
                    'regionBResult' => null,
                ],
                'states' => [
                    'primary_processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        '@fail'  => 'fallback_processing',
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
                    'fallback_processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'fallback_a' => [
                                'initial' => 'retrying',
                                'states'  => [
                                    'retrying' => [
                                        'entry' => SetRegionAResultAction::class,
                                        'on'    => ['FALLBACK_A_DONE' => 'completed'],
                                    ],
                                    'completed' => ['type' => 'final'],
                                ],
                            ],
                            'fallback_b' => [
                                'initial' => 'retrying',
                                'states'  => [
                                    'retrying' => [
                                        'entry' => SetRegionBResultAction::class,
                                        'on'    => ['FALLBACK_B_DONE' => 'completed'],
                                    ],
                                    'completed' => ['type' => 'final'],
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
