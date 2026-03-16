<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine where a timer event triggers entry into an @always loop.
 *
 * Flow: waiting → (TIMEOUT after 5s) → loop_a → (@always) → loop_b → (@always) → loop_a
 * Used for E2E/LocalQA timer + loop protection tests.
 */
class AlwaysLoopOnTimerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_loop_timer',
                'initial' => 'waiting',
                'context' => [],
                'states'  => [
                    'waiting' => [
                        'on' => [
                            'TIMEOUT' => ['target' => 'loop_a', 'after' => Timer::seconds(5)],
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
        );
    }
}
