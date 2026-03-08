<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetContextWithoutRaiseAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionBAction;

/**
 * Region A sets context but does NOT raise an event.
 * Region B completes normally.
 * Machine stays stuck in parallel state (region_a.working + region_b.finished).
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
                        'onFail' => 'failed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => SetContextWithoutRaiseAction::class,
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
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
