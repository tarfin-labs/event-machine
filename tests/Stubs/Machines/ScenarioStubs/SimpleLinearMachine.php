<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Simple linear machine for scenario @continue tests.
 * a → GO → b → NEXT → c → DONE → d (final).
 */
class SimpleLinearMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'simple_linear',
            'initial' => 'a',
            'context' => ['amount' => 0],
            'states'  => [
                'a' => ['on' => ['GO' => 'b']],
                'b' => ['on' => ['NEXT' => 'c']],
                'c' => ['on' => ['DONE' => 'd']],
                'd' => ['type' => 'final'],
            ],
        ]);
    }
}
