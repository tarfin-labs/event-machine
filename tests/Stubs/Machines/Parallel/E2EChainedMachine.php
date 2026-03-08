<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionARaiseAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionBRaiseAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionCRaiseAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionDRaiseAction;

/**
 * E2E test machine for chained parallel states.
 *
 * phase_one(A,B) → onDone → phase_two(C,D) → onDone → completed.
 *
 * Documents known limitation: exitParallelStateAndTransition() does not call
 * enterParallelState() for parallel targets, so phase_two won't auto-dispatch.
 */
class E2EChainedMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'e2e_chained',
                'initial'        => 'phase_one',
                'should_persist' => true,
                'context'        => [
                    'region_a_result' => null,
                    'region_b_result' => null,
                    'region_c_result' => null,
                    'region_d_result' => null,
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
                                        'entry' => RegionARaiseAction::class,
                                        'on'    => ['REGION_A_PROCESSED' => 'finished_a'],
                                    ],
                                    'finished_a' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working_b',
                                'states'  => [
                                    'working_b' => [
                                        'entry' => RegionBRaiseAction::class,
                                        'on'    => ['REGION_B_PROCESSED' => 'finished_b'],
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
                                        'entry' => RegionCRaiseAction::class,
                                        'on'    => ['REGION_C_PROCESSED' => 'finished_c'],
                                    ],
                                    'finished_c' => ['type' => 'final'],
                                ],
                            ],
                            'region_d' => [
                                'initial' => 'working_d',
                                'states'  => [
                                    'working_d' => [
                                        'entry' => RegionDRaiseAction::class,
                                        'on'    => ['REGION_D_PROCESSED' => 'finished_d'],
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
