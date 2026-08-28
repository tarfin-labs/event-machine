<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Reproduces the production shape that made PathEnumerator recurse without bound:
 * two parallel states, each carrying a parent-level transition that re-enters a
 * parallel state. Region states inherit those transitions through the parent
 * chain, so region enumeration escapes the region and walks the whole machine.
 *
 * idle → [START] → data_collection (PARALLEL: retailer + customer_info)
 *   → @done → verification (PARALLEL: findeks + turmob)
 *     → @done → completed (FINAL)
 *     → [EDIT] → data_collection      ← re-enters the earlier parallel state
 *   → [RESTART] → data_collection     ← re-enters itself
 */
class ReentrantParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'reentrant_parallel',
                'initial' => 'idle',
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'data_collection'],
                    ],
                    'data_collection' => [
                        'type'   => 'parallel',
                        'on'     => ['RESTART' => 'data_collection'],
                        '@done'  => 'verification',
                        'states' => [
                            'retailer' => [
                                'initial' => 'awaiting_vehicle',
                                'states'  => [
                                    'awaiting_vehicle' => [
                                        'on' => ['VEHICLE_PROVIDED' => 'vehicle_ready'],
                                    ],
                                    'vehicle_ready' => ['type' => 'final'],
                                ],
                            ],
                            'customer_info' => [
                                'initial' => 'awaiting_customer',
                                'states'  => [
                                    'awaiting_customer' => [
                                        'on' => ['CUSTOMER_PROVIDED' => 'customer_ready'],
                                    ],
                                    'customer_ready' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'verification' => [
                        'type'   => 'parallel',
                        'on'     => ['EDIT' => 'data_collection'],
                        '@done'  => 'completed',
                        'states' => [
                            'findeks' => [
                                'initial' => 'running',
                                'states'  => [
                                    'running'  => ['on' => ['FINDEKS_DONE' => 'finished']],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'turmob' => [
                                'initial' => 'running',
                                'states'  => [
                                    'running'  => ['on' => ['TURMOB_DONE' => 'finished']],
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
