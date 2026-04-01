<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with transient initial that reaches final via @always — no delegation.
 * Used for @start scenario testing without delegation complexity.
 *
 * idle (TRANSIENT) → @always → done (FINAL)
 */
class AlwaysFinalMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'always_final',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        '@always' => 'done',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ]);
    }
}
