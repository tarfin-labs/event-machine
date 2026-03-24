<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Middle-level child machine for three-level delegation tests.
 *
 * Flow: idle → (START) → delegating [GrandchildMachine async] → (@done) → completed
 * Delegates to GrandchildMachine and waits for its completion.
 */
class MiddleChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'middle_child',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => GrandchildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => 'completed',
                        '@fail'   => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
