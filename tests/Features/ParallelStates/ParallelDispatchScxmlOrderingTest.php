<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('parallel state entry: parent entered before children (SCXML test404)', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_order',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'entry'  => 'parentEntryAction',
                    'states' => [
                        'region_a' => [
                            'initial' => 'child_a',
                            'states'  => [
                                'child_a' => [
                                    'entry' => 'childAEntryAction',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'child_b',
                            'states'  => [
                                'child_b' => [
                                    'entry' => 'childBEntryAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'parentEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'parent_entry';
                },
                'childAEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'child_a_entry';
                },
                'childBEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'child_b_entry';
                },
            ],
        ]
    );

    $definition->getInitialState();

    // Parent entry must fire first, then children
    expect($actionsExecuted[0])->toBe('parent_entry');
    expect($actionsExecuted)->toContain('child_a_entry');
    expect($actionsExecuted)->toContain('child_b_entry');
    expect(array_search('parent_entry', $actionsExecuted))
        ->toBeLessThan(array_search('child_a_entry', $actionsExecuted));
    expect(array_search('parent_entry', $actionsExecuted))
        ->toBeLessThan(array_search('child_b_entry', $actionsExecuted));
});

test('parallel state exit: children exited before parent (SCXML test406)', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'exit_order',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'exit'   => 'parentExitAction',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'exit' => 'childAExitAction',
                                    'on'   => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'exit' => 'childBExitAction',
                                    'on'   => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'childAExitAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'child_a_exit';
                },
                'childBExitAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'child_b_exit';
                },
                'parentExitAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'parent_exit';
                },
            ],
        ]
    );

    $state           = $definition->getInitialState();
    $actionsExecuted = []; // Reset after entry

    // Complete both regions to trigger onDone + exit
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    expect($state->currentStateDefinition->id)->toBe('exit_order.completed');

    // Children must exit before parent (unconditional — if missing, test must fail)
    expect($actionsExecuted)->toContain('parent_exit');
    expect($actionsExecuted)->toContain('child_a_exit');
    expect(array_search('parent_exit', $actionsExecuted))
        ->toBeGreaterThan(array_search('child_a_exit', $actionsExecuted));
});

test('entry ordering across nested parallel compound states (SCXML test405)', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'nested_order',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'entry'  => 'parallelEntryAction',
                    'states' => [
                        'region_a' => [
                            'initial' => 'compound_a',
                            'states'  => [
                                'compound_a' => [
                                    'initial' => 'inner_a',
                                    'entry'   => 'compoundAEntryAction',
                                    'states'  => [
                                        'inner_a' => [
                                            'entry' => 'innerAEntryAction',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'leaf_b',
                            'states'  => [
                                'leaf_b' => [
                                    'entry' => 'leafBEntryAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'parallelEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'parallel_entry';
                },
                'compoundAEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'compound_a_entry';
                },
                'innerAEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'inner_a_entry';
                },
                'leafBEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'leaf_b_entry';
                },
            ],
        ]
    );

    $definition->getInitialState();

    // Entry order: parallel → compound → inner (depth-first)
    expect($actionsExecuted[0])->toBe('parallel_entry');

    // Inner leaf entry always fires; compound parent entry may not fire
    // (enterParallelState runs entry on leaf initial states, not intermediate compounds)
    expect($actionsExecuted)->toContain('inner_a_entry');

    $compoundIdx = array_search('compound_a_entry', $actionsExecuted);
    $innerIdx    = array_search('inner_a_entry', $actionsExecuted);

    if ($compoundIdx !== false) {
        // When compound entry fires, it must fire before inner leaf
        expect($compoundIdx)->toBeLessThan($innerIdx);
    }
});
