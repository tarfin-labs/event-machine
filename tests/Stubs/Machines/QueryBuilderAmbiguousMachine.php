<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with duplicate leaf state names for testing ambiguous resolution.
 *
 * Two regions each have an 'idle' leaf state:
 *   qb_ambiguous.region_a.idle
 *   qb_ambiguous.region_b.idle
 */
class QueryBuilderAmbiguousMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'             => 'qb_ambiguous',
            'initial'        => 'processing',
            'should_persist' => true,
            'context'        => [],
            'states'         => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => ['START_A' => 'working'],
                                ],
                                'working' => [
                                    'on' => ['DONE_A' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => ['START_B' => 'working'],
                                ],
                                'working' => [
                                    'on' => ['DONE_B' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);
    }
}
