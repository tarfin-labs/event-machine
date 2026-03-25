<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parallel machine where Region A completes quickly (raises event)
 * but Region B stalls at initial (no raise — simulates slow region).
 *
 * Used for testing ParallelRegionTimeoutJob behavior.
 */
class SlowRegionParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'slow_region_parallel',
                'initial' => 'processing',
                'context' => [
                    'region_a_done' => false,
                    'region_b_done' => false,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        '@fail'  => 'failed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => 'completeRegionAAction',
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
                                        // No entry action, no raise → stalls here
                                        'on' => [
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
                    'completeRegionAAction' => function (ContextManager $ctx): void {
                        $ctx->set('region_a_done', true);
                        $ctx->raise(['type' => 'REGION_A_DONE']);
                    },
                ],
            ],
        );
    }
}
