<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine B for parallel region delegation tests.
 * Starts in a final state (immediate completion).
 */
class ImmediateChildBMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'immediate_child_b',
                'initial' => 'done',
                'context' => [],
                'states'  => [
                    'done' => ['type' => 'final'],
                ],
            ],
        );
    }
}
