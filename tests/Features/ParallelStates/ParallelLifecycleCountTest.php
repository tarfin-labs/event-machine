<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ============================================================
// Parallel Entry/Exit Action Count Across Full Lifecycle
// ============================================================
// Tracks how many times entry/exit actions fire for a parallel
// state, its regions, and their children through the complete
// lifecycle: enter → transition within regions → exit parallel.

test('parallel lifecycle counts entry and exit actions correctly', function (): void {
    $counts = [
        'parallel_entry'    => 0,
        'region_a_a1_entry' => 0,
        'region_a_a2_entry' => 0,
        'region_b_b1_entry' => 0,
        'region_a_a1_exit'  => 0,
        'region_b_b1_exit'  => 0,
        'parallel_exit'     => 0,
    ];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'lifecycle_count',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'type'  => 'parallel',
                    'entry' => 'parallelEntryAction',
                    'exit'  => 'parallelExitAction',
                    'on'    => [
                        'ESCAPE' => 'finished',
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'entry' => 'regionAA1EntryAction',
                                    'exit'  => 'regionAA1ExitAction',
                                    'on'    => [
                                        'ADVANCE_A' => 'a2',
                                    ],
                                ],
                                'a2' => [
                                    'entry' => 'regionAA2EntryAction',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'entry' => 'regionBB1EntryAction',
                                    'exit'  => 'regionBB1ExitAction',
                                ],
                            ],
                        ],
                    ],
                ],
                'finished' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'parallelEntryAction' => function () use (&$counts): void {
                    $counts['parallel_entry']++;
                },
                'parallelExitAction' => function () use (&$counts): void {
                    $counts['parallel_exit']++;
                },
                'regionAA1EntryAction' => function () use (&$counts): void {
                    $counts['region_a_a1_entry']++;
                },
                'regionAA1ExitAction' => function () use (&$counts): void {
                    $counts['region_a_a1_exit']++;
                },
                'regionAA2EntryAction' => function () use (&$counts): void {
                    $counts['region_a_a2_entry']++;
                },
                'regionBB1EntryAction' => function () use (&$counts): void {
                    $counts['region_b_b1_entry']++;
                },
                'regionBB1ExitAction' => function () use (&$counts): void {
                    $counts['region_b_b1_exit']++;
                },
            ],
        ],
    );

    // Phase 1: Enter the parallel state
    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'START'], $state);

    // Parallel state entry action should fire once
    expect($counts['parallel_entry'])->toBe(1);
    // Both regions' initial states should have entry actions
    expect($counts['region_a_a1_entry'])->toBe(1);
    expect($counts['region_b_b1_entry'])->toBe(1);
    // No exits yet
    expect($counts['region_a_a1_exit'])->toBe(0);
    expect($counts['region_b_b1_exit'])->toBe(0);
    expect($counts['parallel_exit'])->toBe(0);

    // Phase 2: Transition within region_a (a1 -> a2)
    $state = $definition->transition(['type' => 'ADVANCE_A'], $state);

    // a1 exit and a2 entry should fire
    expect($counts['region_a_a1_exit'])->toBe(1);
    expect($counts['region_a_a2_entry'])->toBe(1);
    // region_b unchanged
    expect($counts['region_b_b1_entry'])->toBe(1);
    expect($counts['region_b_b1_exit'])->toBe(0);
    // Parallel entry/exit unchanged
    expect($counts['parallel_entry'])->toBe(1);
    expect($counts['parallel_exit'])->toBe(0);

    // Phase 3: Escape the parallel state entirely
    $state = $definition->transition(['type' => 'ESCAPE'], $state);

    // Parallel exit should fire once
    expect($counts['parallel_exit'])->toBe(1);
    // Region exits should fire for active states when escaping
    // (b1 exit fires because b1 is still active when escaping)
    expect($counts['region_b_b1_exit'])->toBe(1);

    // Final state
    expect($state->matches('finished'))->toBeTrue();

    // Verify no double-fires: each action fired exactly once per lifecycle event
    expect($counts['parallel_entry'])->toBe(1);
    expect($counts['region_a_a1_entry'])->toBe(1);
    expect($counts['region_a_a2_entry'])->toBe(1);
    expect($counts['region_b_b1_entry'])->toBe(1);
    expect($counts['region_a_a1_exit'])->toBe(1);
});

test('parallel @done lifecycle fires entry and exit for all regions reaching final', function (): void {
    $actionLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'done_lifecycle',
            'initial' => 'active',
            'context' => [],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'entry'  => 'activeEntryAction',
                    'exit'   => 'activeExitAction',
                    '@done'  => 'completed',
                    'states' => [
                        'region_x' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => 'xWorkingEntryAction',
                                    'exit'  => 'xWorkingExitAction',
                                    'on'    => ['DONE_X' => 'done_x'],
                                ],
                                'done_x' => [
                                    'type'  => 'final',
                                    'entry' => 'xDoneEntryAction',
                                ],
                            ],
                        ],
                        'region_y' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => 'yWorkingEntryAction',
                                    'exit'  => 'yWorkingExitAction',
                                    'on'    => ['DONE_Y' => 'done_y'],
                                ],
                                'done_y' => [
                                    'type'  => 'final',
                                    'entry' => 'yDoneEntryAction',
                                ],
                            ],
                        ],
                    ],
                ],
                'completed' => [
                    'type'  => 'final',
                    'entry' => 'completedEntryAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'activeEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'active_entry';
                },
                'activeExitAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'active_exit';
                },
                'xWorkingEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'x_working_entry';
                },
                'xWorkingExitAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'x_working_exit';
                },
                'xDoneEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'x_done_entry';
                },
                'yWorkingEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'y_working_entry';
                },
                'yWorkingExitAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'y_working_exit';
                },
                'yDoneEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'y_done_entry';
                },
                'completedEntryAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'completed_entry';
                },
            ],
        ],
    );

    // Enter parallel state
    $state = $definition->getInitialState();

    expect($actionLog)->toContain('active_entry');
    expect($actionLog)->toContain('x_working_entry');
    expect($actionLog)->toContain('y_working_entry');

    $actionLog = []; // Reset for next phase

    // Complete region_x
    $state = $definition->transition(['type' => 'DONE_X'], $state);

    expect($actionLog)->toContain('x_working_exit');
    expect($actionLog)->toContain('x_done_entry');
    expect($actionLog)->not->toContain('active_exit'); // Parallel not done yet

    $actionLog = [];

    // Complete region_y — triggers @done
    $state = $definition->transition(['type' => 'DONE_Y'], $state);

    expect($actionLog)->toContain('y_working_exit');
    expect($actionLog)->toContain('y_done_entry');
    expect($actionLog)->toContain('active_exit');
    expect($actionLog)->toContain('completed_entry');

    expect($state->matches('completed'))->toBeTrue();
});
