<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('same event transitions multiple regions simultaneously', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'simultaneous',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle_a',
                            'states'  => [
                                'idle_a' => [
                                    'on' => ['GO' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [
                                    'on' => ['GO' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->value)->toContain('simultaneous.parallel_parent.region_a.idle_a');
    expect($state->value)->toContain('simultaneous.parallel_parent.region_b.idle_b');

    // Single GO event transitions BOTH regions simultaneously
    $state = $definition->transition(['type' => 'GO'], $state);

    // Both regions should have transitioned + onDone should fire
    expect($state->currentStateDefinition->id)->toBe('simultaneous.completed');
});

test('targetless transition in parallel region fires actions without state change', function (): void {
    $actionFired = false;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'targetless',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle_a',
                            'states'  => [
                                'idle_a' => [
                                    'on' => [
                                        'PING' => [
                                            'actions' => 'pingAction',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'pingAction' => function () use (&$actionFired): void {
                    $actionFired = true;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    $state = $definition->transition(['type' => 'PING'], $state);

    expect($actionFired)->toBeTrue();
    // State should remain the same
    expect($state->value)->toContain('targetless.parallel_parent.region_a.idle_a');
    expect($state->value)->toContain('targetless.parallel_parent.region_b.idle_b');
});

test('cross-region event does NOT re-enter parallel state', function (): void {
    $parallelEntryCount = 0;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'entry'  => 'parallelEntryAction',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_1_a',
                            'states'  => [
                                'step_1_a' => [
                                    'on' => ['ADVANCE_A' => 'step_2_a'],
                                ],
                                'step_2_a' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'step_1_b',
                            'states'  => [
                                'step_1_b' => [
                                    'on' => ['ADVANCE_B' => 'step_2_b'],
                                ],
                                'step_2_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'parallelEntryAction' => function () use (&$parallelEntryCount): void {
                    $parallelEntryCount++;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($parallelEntryCount)->toBe(1);

    // Advance region A — should NOT re-enter parallel state
    $state = $definition->transition(['type' => 'ADVANCE_A'], $state);
    expect($parallelEntryCount)->toBe(1);

    // Advance region B — should NOT re-enter parallel state
    $state = $definition->transition(['type' => 'ADVANCE_B'], $state);
    expect($parallelEntryCount)->toBe(1);

    expect($state->value)->toContain('cross_region.parallel_parent.region_a.step_2_a');
    expect($state->value)->toContain('cross_region.parallel_parent.region_b.step_2_b');
});

test('re-entering transition fires exit then entry actions', function (): void {
    $exitCount  = 0;
    $entryCount = 0;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'reenter',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'active_a',
                            'states'  => [
                                'active_a' => [
                                    'entry' => 'entryAction',
                                    'exit'  => 'exitAction',
                                    'on'    => [
                                        'RESET_A' => 'active_a',
                                    ],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'entryAction' => function () use (&$entryCount): void {
                    $entryCount++;
                },
                'exitAction' => function () use (&$exitCount): void {
                    $exitCount++;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($entryCount)->toBe(1); // Initial entry
    expect($exitCount)->toBe(0);

    // Self-transition: exit then entry
    $state = $definition->transition(['type' => 'RESET_A'], $state);
    expect($exitCount)->toBe(1);
    expect($entryCount)->toBe(2);

    expect($state->value)->toContain('reenter.parallel_parent.region_a.active_a');
});
