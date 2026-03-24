<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * The simplest possible child machine for basic delegation tests.
 *
 * Flow: idle → done (final)
 * Immediately transitions to final on COMPLETE event.
 * No context, no results — pure lifecycle testing.
 */
class SimpleChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'simple_child',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'COMPLETE' => 'done',
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
