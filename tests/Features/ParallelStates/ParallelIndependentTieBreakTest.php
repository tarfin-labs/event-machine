<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Apache Commons SCXML-inspired: parallel region independent document-order tie-breaking.
 *
 * When both regions handle the same event with multiple guarded transitions,
 * each region must independently resolve tie-breaking using its own document order.
 */

test('each parallel region resolves document-order tie-break independently', function (): void {
    // Track which guards were evaluated per region to verify short-circuit behavior
    $regionAGuardCalls = [];
    $regionBGuardCalls = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'independent_tiebreak',
            'initial' => 'active',
            'context' => ['score' => 75],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        // Region A: score >= 50 passes first, score >= 90 second
                        // With score=75, the FIRST guard passes → region_a goes to "passed"
                        'region_a' => [
                            'initial' => 'pending',
                            'states'  => [
                                'pending' => [
                                    'on' => [
                                        'EVALUATE' => [
                                            [
                                                'target' => 'passed',
                                                'guards' => 'regionAScoreAbove50Guard',
                                            ],
                                            [
                                                'target' => 'excellent',
                                                'guards' => 'regionAScoreAbove90Guard',
                                            ],
                                            [
                                                'target' => 'failed',
                                            ],
                                        ],
                                    ],
                                ],
                                'passed'    => [],
                                'excellent' => [],
                                'failed'    => [],
                            ],
                        ],
                        // Region B: score >= 90 first (fails), score >= 50 second (passes)
                        // With score=75, the first guard FAILS, second passes → region_b goes to "acceptable"
                        'region_b' => [
                            'initial' => 'pending',
                            'states'  => [
                                'pending' => [
                                    'on' => [
                                        'EVALUATE' => [
                                            [
                                                'target' => 'outstanding',
                                                'guards' => 'regionBScoreAbove90Guard',
                                            ],
                                            [
                                                'target' => 'acceptable',
                                                'guards' => 'regionBScoreAbove50Guard',
                                            ],
                                            [
                                                'target' => 'rejected',
                                            ],
                                        ],
                                    ],
                                ],
                                'outstanding' => [],
                                'acceptable'  => [],
                                'rejected'    => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'regionAScoreAbove50Guard' => function (ContextManager $ctx) use (&$regionAGuardCalls) {
                    $regionAGuardCalls[] = 'above50';

                    return $ctx->get('score') >= 50;
                },
                'regionAScoreAbove90Guard' => function (ContextManager $ctx) use (&$regionAGuardCalls) {
                    $regionAGuardCalls[] = 'above90';

                    return $ctx->get('score') >= 90;
                },
                'regionBScoreAbove90Guard' => function (ContextManager $ctx) use (&$regionBGuardCalls) {
                    $regionBGuardCalls[] = 'above90';

                    return $ctx->get('score') >= 90;
                },
                'regionBScoreAbove50Guard' => function (ContextManager $ctx) use (&$regionBGuardCalls) {
                    $regionBGuardCalls[] = 'above50';

                    return $ctx->get('score') >= 50;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    expect($state->matches('active.region_a.pending'))->toBeTrue()
        ->and($state->matches('active.region_b.pending'))->toBeTrue();

    // Act — send EVALUATE to both regions
    $state = $definition->transition(['type' => 'EVALUATE'], $state);

    // Assert — Region A picks first matching: "passed" (score >= 50 is true, short-circuits)
    expect($state->matches('active.region_a.passed'))->toBeTrue();

    // Assert — Region B picks first matching: first guard fails (score < 90),
    //          falls through to second → "acceptable" (score >= 50 is true)
    expect($state->matches('active.region_b.acceptable'))->toBeTrue();

    // Assert — Region A short-circuited: only evaluated the first (passing) guard
    expect($regionAGuardCalls)->toBe(['above50']);

    // Assert — Region B evaluated first (failing) guard, then second (passing) guard
    expect($regionBGuardCalls)->toBe(['above90', 'above50']);
});

test('parallel regions use independent document order with all guards failing falls to unguarded', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'fallthrough_tiebreak',
            'initial' => 'active',
            'context' => ['level' => 1],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        // Region A: all guards fail → falls through to unguarded "default_a"
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'CHECK' => [
                                            [
                                                'target' => 'high',
                                                'guards' => 'levelAbove10Guard',
                                            ],
                                            [
                                                'target' => 'medium',
                                                'guards' => 'levelAbove5Guard',
                                            ],
                                            [
                                                'target' => 'default_a',
                                            ],
                                        ],
                                    ],
                                ],
                                'high'      => [],
                                'medium'    => [],
                                'default_a' => [],
                            ],
                        ],
                        // Region B: first guard passes immediately → "priority"
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'CHECK' => [
                                            [
                                                'target' => 'priority',
                                                'guards' => 'levelAbove0Guard',
                                            ],
                                            [
                                                'target' => 'default_b',
                                            ],
                                        ],
                                    ],
                                ],
                                'priority'  => [],
                                'default_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'levelAbove10Guard' => fn (ContextManager $ctx) => $ctx->get('level') > 10,
                'levelAbove5Guard'  => fn (ContextManager $ctx) => $ctx->get('level') > 5,
                'levelAbove0Guard'  => fn (ContextManager $ctx) => $ctx->get('level') > 0,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'CHECK'], $state);

    // Region A: both guards fail → unguarded fallback "default_a"
    expect($state->matches('active.region_a.default_a'))->toBeTrue();

    // Region B: first guard passes → "priority"
    expect($state->matches('active.region_b.priority'))->toBeTrue();
});
