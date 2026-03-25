<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Mid-level machine that delegates to FailingChildMachine WITHOUT @fail handler.
 *
 * When the grandchild fails, the exception re-throws through this machine,
 * allowing a grandparent to catch it via its own @fail handler.
 */
class MidLevelNoFailMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'mid_level_no_fail',
                'initial' => 'delegating',
                'context' => [],
                'states'  => [
                    'delegating' => [
                        'machine' => FailingChildMachine::class,
                        '@done'   => 'completed',
                        // No @fail → exception re-throws to caller
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
