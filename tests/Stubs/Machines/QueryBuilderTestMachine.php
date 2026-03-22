<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Simple 3-state machine for query builder tests.
 *
 * idle --[START]--> active --[FINISH]--> completed (final)
 */
class QueryBuilderTestMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'             => 'qb_test',
            'initial'        => 'idle',
            'should_persist' => true,
            'context'        => [
                'name' => null,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'active',
                    ],
                ],
                'active' => [
                    'on' => [
                        'FINISH' => 'completed',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ]);
    }
}
