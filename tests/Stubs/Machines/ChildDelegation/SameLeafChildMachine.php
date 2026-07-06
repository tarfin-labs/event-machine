<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine with two final states sharing the same leaf name ('done')
 * in different compound branches — for finalState leaf-collision tests.
 */
class SameLeafChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'same_leaf_child',
            'initial' => 'choosing',
            'context' => [],
            'states'  => [
                'choosing' => [
                    'on' => [
                        'GO_A' => 'path_a',
                        'GO_B' => 'path_b',
                    ],
                ],
                'path_a' => [
                    'initial' => 'done',
                    'states'  => [
                        'done' => ['type' => 'final'],
                    ],
                ],
                'path_b' => [
                    'initial' => 'done',
                    'states'  => [
                        'done' => ['type' => 'final'],
                    ],
                ],
            ],
        ]);
    }
}
