<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionBAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionCAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionDAction;

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
                    'regionAData' => null,
                    'regionBData' => null,
                    'regionCData' => null,
                    'regionDData' => null,
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
                                        'entry' => ProcessRegionAAction::class,
                                        'on'    => ['REGION_A_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessRegionBAction::class,
                                        'on'    => ['REGION_B_PROCESSED' => 'finished'],
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
                                        'entry' => ProcessRegionCAction::class,
                                        'on'    => ['REGION_C_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_d' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessRegionDAction::class,
                                        'on'    => ['REGION_D_PROCESSED' => 'finished'],
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
