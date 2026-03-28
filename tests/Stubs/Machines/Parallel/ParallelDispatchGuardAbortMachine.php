<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBOutputAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SimulateConcurrentModificationAction;

class ParallelDispatchGuardAbortMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'parallel_dispatch_guard_abort',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'concurrentData' => null,
                    'regionBData'    => null,
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
                                        'entry' => SimulateConcurrentModificationAction::class,
                                        'on'    => [
                                            'REGION_A_DONE' => 'finished',
                                        ],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetRegionBOutputAction::class,
                                        'on'    => [
                                            'REGION_B_DONE' => 'finished',
                                        ],
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
