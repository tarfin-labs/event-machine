<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Grandchild machine for nested delegation testing.
 *
 * idle (TRANSIENT) → @always → gc_done (FINAL)
 */
class GrandchildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'grandchild',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        '@always' => 'gc_done',
                    ],
                ],
                'gc_done' => ['type' => 'final'],
            ],
        ]);
    }
}
