<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ThrowRuntimeExceptionAction;

/**
 * Parallel machine where Region A succeeds (sets context + raises → final)
 * while Region B throws RuntimeException.
 *
 * Tests partial failure: @fail should fire, Region A's context should survive.
 * idle → processing (parallel) → failed (@fail)
 */
class PartialFailParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'partial_fail_parallel',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'regionAData' => null,
                    'regionBData' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        '@fail'  => 'failed',
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
                                        'entry' => ThrowRuntimeExceptionAction::class,
                                        'on'    => ['REGION_B_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
