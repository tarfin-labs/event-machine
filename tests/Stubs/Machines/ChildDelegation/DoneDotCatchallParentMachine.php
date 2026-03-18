<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine with @done.{state} + @done catch-all for LocalQA tests.
 */
class DoneDotCatchallParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'done_dot_catchall_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine'        => ImmediateChildMachine::class,
                    'queue'          => 'child-queue',
                    '@done.approved' => 'completed',
                    '@done'          => 'fallback',
                    '@fail'          => 'error',
                ],
                'completed' => ['type' => 'final'],
                'fallback'  => ['type' => 'final'],
                'error'     => ['type' => 'final'],
            ],
        ]);
    }
}
