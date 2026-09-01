<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Actions\ProcessAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * Shapes for the seeding rules of a single, shared frontier.
 *
 *   DIRECT  entry --> goal                      the branch target IS the resolution target
 *   PARTLY  entry --> {hop | (targetless)}      one branch has no target at all
 *   CYCLE   entry --> loop_a --> entry --> goal the source is re-entered as an ordinary step
 *   FORK    entry --> fork, whose @always has an expensive first branch and a cheap second
 *
 * FORK is the one that separates a shared frontier from a per-branch search: the cheap route
 * lives in the SECOND branch of `fork`'s @always, and the expensive one in the first.
 */
class ScenarioFrontierMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_frontier',
                'initial' => 'entry',
                'states'  => [
                    'entry' => [
                        'on' => [
                            'DIRECT' => 'goal',
                            'PARTLY' => [
                                [
                                    'target' => 'hop',
                                    'guards' => IsEligibleGuard::class,
                                ],
                                [
                                    'actions' => ProcessAction::class,
                                ],
                            ],
                            'CYCLE' => 'loop_a',
                            'FORK'  => 'fork',
                        ],
                    ],
                    'hop' => [
                        'on' => [
                            'NEXT' => 'goal',
                        ],
                    ],
                    'loop_a' => [
                        'on' => [
                            'BACK' => 'entry',
                        ],
                    ],
                    'fork' => [
                        'on' => [
                            '@always' => [
                                [
                                    'target' => 'costly',
                                    'guards' => IsEligibleGuard::class,
                                ],
                                [
                                    'target' => 'thrifty',
                                ],
                            ],
                        ],
                    ],
                    'costly' => [
                        'type'   => 'parallel',
                        '@done'  => 'goal',
                        'states' => [
                            'region_a' => [
                                'initial' => 'busy_a',
                                'states'  => [
                                    'busy_a' => ['on' => ['A_OK' => 'done_a']],
                                    'done_a' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'busy_b',
                                'states'  => [
                                    'busy_b' => ['on' => ['B_OK' => 'done_b']],
                                    'done_b' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'thrifty' => [
                        'on' => [
                            'CONFIRM' => 'goal',
                        ],
                    ],
                    'goal' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards'  => ['IsEligibleGuard' => IsEligibleGuard::class],
                'actions' => ['ProcessAction' => ProcessAction::class],
            ],
        );
    }
}
