<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine with multiple @always transitions for listener isolation testing.
 *
 * Flow: step_1 → @always step_2 → @always done (final)
 * The child goes through 3 states — parent listen should NOT fire for any of them.
 */
class MultiStateChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'multi_state_child',
                'initial' => 'step_1',
                'context' => [],
                'states'  => [
                    'step_1' => ['on' => ['@always' => 'step_2']],
                    'step_2' => ['on' => ['@always' => 'done']],
                    'done'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
