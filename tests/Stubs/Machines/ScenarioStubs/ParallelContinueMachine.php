<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parallel machine for @continue scenario tests.
 *
 *   idle → BEGIN → work (parallel)
 *     ├─ a (initial: a1)
 *     │   ├─ a1 → A_NEXT → a2 (final)
 *     └─ b (initial: b1)
 *         ├─ b1 → B_NEXT → b2 (final)
 *     work.@done → completed (final)
 *
 *   work also accepts WORK_DONE event guarded by isReadyGuard
 *   (a in a2 AND b in b2) — used to test parent-event from leaf
 *   pattern (the player should fire WORK_DONE from a leaf where both
 *   regions have already advanced).
 */
class ParallelContinueMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'parallel_continue',
                'initial' => 'idle',
                'states'  => [
                    'idle' => ['on' => ['BEGIN' => 'work']],
                    'work' => [
                        'type' => 'parallel',
                        'on'   => [
                            'WORK_DONE' => [
                                'target' => 'completed',
                                'guards' => 'isReadyGuard',
                            ],
                        ],
                        'states' => [
                            'a' => [
                                'initial' => 'a1',
                                'states'  => [
                                    'a1' => ['on' => ['A_NEXT' => 'a2']],
                                    'a2' => ['type' => 'final'],
                                ],
                            ],
                            'b' => [
                                'initial' => 'b1',
                                'states'  => [
                                    'b1' => ['on' => ['B_NEXT' => 'b2']],
                                    'b2' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'isReadyGuard' => fn (State $state): bool => $state->matches('work.a.a2')
                        && $state->matches('work.b.b2'),
                ],
            ],
        );
    }
}
