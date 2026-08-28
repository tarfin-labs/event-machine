<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Exercises the three region outcomes, which ReentrantParallelMachine does not reach.
 *
 * That stub declares both escaping transitions on the parallel states themselves, so
 * every one of them belongs to machine level and no region ever records an exit or a
 * deferral. The gap was found by deriving its expected enumeration by hand.
 *
 * Region 'retailer'      — its only transition is declared INSIDE the region and leaves
 *                          it, so the region path ends in a recorded region exit.
 * Region 'customer_info' — no transitions of its own, inheriting only ABORT from the
 *                          parallel state, so every continuation belongs to machine
 *                          level and the region records that rather than a dead end.
 * Region 'documents'     — an @always declared inside the region that leaves it, which
 *                          reaches the unguarded-fallback path that used to return
 *                          recording nothing at all.
 */
class RegionBoundaryMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'region_boundary',
                'initial' => 'idle',
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'collecting'],
                    ],
                    'collecting' => [
                        'type'   => 'parallel',
                        'on'     => ['ABORT' => 'aborted'],
                        'states' => [
                            'retailer' => [
                                'initial' => 'awaiting_vehicle',
                                'states'  => [
                                    'awaiting_vehicle' => [
                                        'on' => ['ESCALATE' => 'escalated'],
                                    ],
                                ],
                            ],
                            'customer_info' => [
                                'initial' => 'awaiting_customer',
                                'states'  => [
                                    'awaiting_customer' => [],
                                ],
                            ],
                            'documents' => [
                                'initial' => 'routing',
                                'states'  => [
                                    'routing' => [
                                        'on' => ['@always' => 'escalated'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'escalated' => ['type' => 'final'],
                    'aborted'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
