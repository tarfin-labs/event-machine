<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that starts in a final state (immediate completion).
 */
class ImmediateChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'immediate_child',
                'initial' => 'done',
                'context' => [],
                'states'  => [
                    'done' => ['type' => 'final'],
                ],
            ],
        );
    }
}
