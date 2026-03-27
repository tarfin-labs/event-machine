<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent that fire-and-forgets to FailingEntryChildMachine.
 * No @done → parent stays in processing state independently.
 * Used for testing fire-and-forget failure isolation.
 */
class FailingFireAndForgetParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'failing_ff_parent',
                'initial' => 'idle',
                'context' => [
                    'parentOk' => true,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => FailingEntryChildMachine::class,
                        'queue'   => 'child-queue',
                        'on'      => [
                            'FINISH' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
