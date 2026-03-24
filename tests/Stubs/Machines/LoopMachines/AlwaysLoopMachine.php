<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Machine with an @always loop triggered by TRIGGER event.
 *
 * Flow: idle → (TRIGGER) → loop_a → (@always) → loop_b → (@always) → loop_a (infinite)
 * Used for testing infinite loop protection.
 */
class AlwaysLoopMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_loop',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'TRIGGER' => 'loop_a',
                        ],
                    ],
                    'loop_a' => [
                        'on' => ['@always' => 'loop_b'],
                    ],
                    'loop_b' => [
                        'on' => ['@always' => 'loop_a'],
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
