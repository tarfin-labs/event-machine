<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parallel machine where Region A completes via entry action (sets context + raises REGION_A_DONE)
 * but Region B stalls at initial (entry action only sets context, no transition).
 *
 * Used for testing ParallelRegionTimeoutJob behavior.
 * Both regions have entry actions so ParallelRegionJobs are dispatched,
 * which is required for the timeout job to also be dispatched.
 */
class SlowRegionParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'slow_region_parallel',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'region_a_done' => false,
                    'region_b_done' => false,
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
                                        'entry' => 'markRegionAAction',
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
                                        'entry' => 'markRegionBAction',
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
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'markRegionAAction' => function (ContextManager $ctx): void {
                        $ctx->set('region_a_done', true);
                    },
                    'markRegionBAction' => function (ContextManager $ctx): void {
                        // Only sets context, does NOT raise any event → region B stalls
                        $ctx->set('region_b_done', true);
                    },
                ],
            ],
        );
    }
}
