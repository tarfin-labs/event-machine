<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NoRaiseEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RegionBRaiseAction;

/**
 * Region A sets context but does NOT raise an event.
 * Region B completes normally.
 * Machine stays stuck in parallel state (working_a + finished_b).
 */
class E2ENoRaiseMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'e2e_no_raise',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'region_a_context_set' => null,
                    'region_a_pid'         => null,
                    'region_b_result'      => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'completed',
                        'onFail' => 'error',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working_a',
                                'states'  => [
                                    'working_a' => [
                                        'entry' => NoRaiseEntryAction::class,
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
                    'completed' => ['type' => 'final'],
                    'error'     => ['type' => 'final'],
                ],
            ],
        );
    }
}
