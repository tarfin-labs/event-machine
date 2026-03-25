<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ═══════════════════════════════════════════════════════════════
//  Cross-Region LCA Reentry
//
//  A transition that exits one parallel region and targets a state
//  in the same parallel block. Verifies correct region exit/entry.
// ═══════════════════════════════════════════════════════════════

it('transitions within a region without affecting sibling regions', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'lca_reentry',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'step_one',
                        'states'  => [
                            'step_one' => [
                                'on' => ['ADVANCE_A' => 'step_two'],
                            ],
                            'step_two' => [
                                'on' => ['FINISH_A' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'step_one',
                        'states'  => [
                            'step_one' => [
                                'on' => ['ADVANCE_B' => 'step_two'],
                            ],
                            'step_two' => [
                                'on' => ['FINISH_B' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Both regions start at step_one
    expect($state->matches('processing.region_a.step_one'))->toBeTrue();
    expect($state->matches('processing.region_b.step_one'))->toBeTrue();

    // Advance region_a to step_two — region_b stays at step_one
    $state = $definition->transition(['type' => 'ADVANCE_A'], $state);

    expect($state->matches('processing.region_a.step_two'))->toBeTrue();
    expect($state->matches('processing.region_b.step_one'))->toBeTrue();
    expect($state->value)->toHaveCount(2);

    // Advance region_b to step_two — region_a stays at step_two
    $state = $definition->transition(['type' => 'ADVANCE_B'], $state);

    expect($state->matches('processing.region_a.step_two'))->toBeTrue();
    expect($state->matches('processing.region_b.step_two'))->toBeTrue();
});

it('exit actions fire only for the region that transitions, not siblings', function (): void {
    $exitLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'lca_exit_actions',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on'   => ['FINISH_A' => 'done'],
                                    'exit' => 'exitRegionAWorkingAction',
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on'   => ['FINISH_B' => 'done'],
                                    'exit' => 'exitRegionBWorkingAction',
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'exitRegionAWorkingAction' => function () use (&$exitLog): void {
                    $exitLog[] = 'region_a_exit';
                },
                'exitRegionBWorkingAction' => function () use (&$exitLog): void {
                    $exitLog[] = 'region_b_exit';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Finish region_a only — only region_a exit should fire
    $state = $definition->transition(['type' => 'FINISH_A'], $state);

    expect($exitLog)->toBe(['region_a_exit']);
    expect($state->matches('processing.region_a.done'))->toBeTrue();
    expect($state->matches('processing.region_b.working'))->toBeTrue();

    // Finish region_b — only region_b exit should fire
    $state = $definition->transition(['type' => 'FINISH_B'], $state);

    expect($exitLog)->toBe(['region_a_exit', 'region_b_exit']);
    expect($state->matches('completed'))->toBeTrue();
});

it('entry actions fire only for the region that transitions, not siblings', function (): void {
    $entryLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'lca_entry_actions',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => ['START_A' => 'active'],
                                ],
                                'active' => [
                                    'entry' => 'enterRegionAActiveAction',
                                    'on'    => ['FINISH_A' => 'done'],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => ['START_B' => 'active'],
                                ],
                                'active' => [
                                    'entry' => 'enterRegionBActiveAction',
                                    'on'    => ['FINISH_B' => 'done'],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'enterRegionAActiveAction' => function () use (&$entryLog): void {
                    $entryLog[] = 'region_a_entry';
                },
                'enterRegionBActiveAction' => function () use (&$entryLog): void {
                    $entryLog[] = 'region_b_entry';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Start region_a — only region_a entry should fire
    $state = $definition->transition(['type' => 'START_A'], $state);

    expect($entryLog)->toBe(['region_a_entry']);
    expect($state->matches('processing.region_a.active'))->toBeTrue();
    expect($state->matches('processing.region_b.idle'))->toBeTrue();

    // Start region_b — only region_b entry should fire
    $state = $definition->transition(['type' => 'START_B'], $state);

    expect($entryLog)->toBe(['region_a_entry', 'region_b_entry']);
});

it('same event name handled by multiple regions transitions all matching regions', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'lca_broadcast',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['CANCEL' => 'cancelled'],
                            ],
                            'cancelled' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['CANCEL' => 'cancelled'],
                            ],
                            'cancelled' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // CANCEL is handled by both regions — both should transition
    $state = $definition->transition(['type' => 'CANCEL'], $state);

    // Both regions should reach final, triggering @done
    expect($state->matches('completed'))->toBeTrue();
});

it('escape transition from parallel state exits all regions correctly', function (): void {
    $exitLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'lca_escape',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'  => 'parallel',
                    '@done' => 'completed',
                    'on'    => [
                        'ABORT' => 'aborted',
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_one',
                            'states'  => [
                                'step_one' => [
                                    'on'   => ['ADVANCE_A' => 'step_two'],
                                    'exit' => 'exitAStep1Action',
                                ],
                                'step_two' => [
                                    'on'   => ['FINISH_A' => 'done'],
                                    'exit' => 'exitAStep2Action',
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on'   => ['FINISH_B' => 'done'],
                                    'exit' => 'exitBWorkingAction',
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
                'aborted'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'exitAStep1Action' => function () use (&$exitLog): void {
                    $exitLog[] = 'a_step_one_exit';
                },
                'exitAStep2Action' => function () use (&$exitLog): void {
                    $exitLog[] = 'a_step_two_exit';
                },
                'exitBWorkingAction' => function () use (&$exitLog): void {
                    $exitLog[] = 'b_working_exit';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Advance region_a to step_two
    $state   = $definition->transition(['type' => 'ADVANCE_A'], $state);
    $exitLog = []; // reset log — only interested in escape exits

    expect($state->matches('processing.region_a.step_two'))->toBeTrue();
    expect($state->matches('processing.region_b.working'))->toBeTrue();

    // ABORT escapes the entire parallel block
    $state = $definition->transition(['type' => 'ABORT'], $state);

    expect($state->matches('aborted'))->toBeTrue();
    // Both active leaf states should have run exit actions
    expect($exitLog)->toContain('a_step_two_exit');
    expect($exitLog)->toContain('b_working_exit');
    // step_one exit should NOT fire (region_a was at step_two)
    expect($exitLog)->not->toContain('a_step_one_exit');
});
