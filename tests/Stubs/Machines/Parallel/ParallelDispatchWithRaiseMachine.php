<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionARaiseAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionBEntryAction;

class ParallelDispatchWithRaiseMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_raise',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working_a',
                                'states'  => [
                                    'working_a' => [
                                        'entry' => RegionARaiseAction::class,
                                        'on'    => [
                                            'REGION_A_PROCESSED' => 'finished_a',
                                        ],
                                    ],
                                    'finished_a' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working_b',
                                'states'  => [
                                    'working_b' => [
                                        'entry' => RegionBEntryAction::class,
                                        'on'    => [
                                            'REGION_B_DONE' => 'finished_b',
                                        ],
                                    ],
                                    'finished_b' => ['type' => 'final'],
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
