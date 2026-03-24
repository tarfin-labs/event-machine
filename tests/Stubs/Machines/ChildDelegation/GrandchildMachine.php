<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Grandchild machine for three-level delegation tests.
 *
 * Flow: idle → (COMPLETE) → done (final)
 * Waits for COMPLETE event before reaching final state.
 */
class GrandchildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'grandchild',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'COMPLETE' => 'done',
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}
