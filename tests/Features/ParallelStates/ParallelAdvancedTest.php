<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Advanced parallel state tests covering edge cases and complex scenarios.
 */

test('same event can trigger transitions in multiple regions simultaneously', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'editor',
            'initial' => 'active',
            'context' => ['value' => ''],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'editing' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        'CHANGE' => [
                                            'target'  => 'modified',
                                            'actions' => 'updateValue',
                                        ],
                                    ],
                                ],
                                'modified' => [
                                    'on' => [
                                        'CHANGE' => [
                                            'actions' => 'updateValue',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'status' => [
                            'initial' => 'saved',
                            'states'  => [
                                'saved' => [
                                    'on' => [
                                        'CHANGE' => 'unsaved',
                                    ],
                                ],
                                'unsaved' => [
                                    'on' => [
                                        'SAVE' => 'saved',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'updateValue' => function (ContextManager $ctx): void {
                    $ctx->set('value', 'changed');
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('active.editing.idle'))->toBeTrue();
    expect($state->matches('active.status.saved'))->toBeTrue();

    // CHANGE event should trigger transitions in BOTH regions
    $state = $definition->transition(['type' => 'CHANGE'], $state);

    expect($state->matches('active.editing.modified'))->toBeTrue();
    expect($state->matches('active.status.unsaved'))->toBeTrue();
    expect($state->context->get('value'))->toBe('changed');
});

test('nested parallel states work correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'nested',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'outer1' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => [
                                'on' => [
                                    'ACTIVATE' => 'on',
                                ],
                            ],
                            'on' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'inner1' => [
                                        'initial' => 'idle',
                                        'states'  => [
                                            'idle' => [
                                                'on' => [
                                                    'WORK1' => 'working',
                                                ],
                                            ],
                                            'working' => [
                                                'on' => [
                                                    'IDLE1' => 'idle',
                                                ],
                                            ],
                                        ],
                                    ],
                                    'inner2' => [
                                        'initial' => 'idle',
                                        'states'  => [
                                            'idle' => [
                                                'on' => [
                                                    'WORK2' => 'working',
                                                ],
                                            ],
                                            'working' => [
                                                'on' => [
                                                    'IDLE2' => 'idle',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'outer2' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting' => [
                                'on' => [
                                    'PROCEED' => 'done',
                                ],
                            ],
                            'done' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('active.outer1.off'))->toBeTrue();
    expect($state->matches('active.outer2.waiting'))->toBeTrue();

    // Activate outer1 which enters nested parallel
    $state = $definition->transition(['type' => 'ACTIVATE'], $state);
    expect($state->matches('active.outer1.on.inner1.idle'))->toBeTrue();
    expect($state->matches('active.outer1.on.inner2.idle'))->toBeTrue();
    expect($state->matches('active.outer2.waiting'))->toBeTrue();

    // Transition in nested parallel region
    $state = $definition->transition(['type' => 'WORK1'], $state);
    expect($state->matches('active.outer1.on.inner1.working'))->toBeTrue();
    expect($state->matches('active.outer1.on.inner2.idle'))->toBeTrue();

    // Transition in outer region shouldn't affect inner
    $state = $definition->transition(['type' => 'PROCEED'], $state);
    expect($state->matches('active.outer1.on.inner1.working'))->toBeTrue();
    expect($state->matches('active.outer2.done'))->toBeTrue();
});

test('entry actions fire in all parallel regions on initial state', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entryTest',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region1' => [
                            'initial' => 'a',
                            'states'  => [
                                'a' => [
                                    'entry' => 'logRegion1Entry',
                                ],
                            ],
                        ],
                        'region2' => [
                            'initial' => 'b',
                            'states'  => [
                                'b' => [
                                    'entry' => 'logRegion2Entry',
                                ],
                            ],
                        ],
                        'region3' => [
                            'initial' => 'c',
                            'states'  => [
                                'c' => [
                                    'entry' => 'logRegion3Entry',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logRegion1Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'region1';
                },
                'logRegion2Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'region2';
                },
                'logRegion3Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'region3';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // All three regions should have fired their entry actions
    expect($actionsExecuted)->toContain('region1');
    expect($actionsExecuted)->toContain('region2');
    expect($actionsExecuted)->toContain('region3');
    expect(count($actionsExecuted))->toBe(3);
});

test('four regions word processor style machine', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'word',
        'initial' => 'editing',
        'states'  => [
            'editing' => [
                'type'   => 'parallel',
                'states' => [
                    'bold' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => ['on' => ['TOGGLE_BOLD' => 'on']],
                            'on'  => ['on' => ['TOGGLE_BOLD' => 'off']],
                        ],
                    ],
                    'underline' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => ['on' => ['TOGGLE_UNDERLINE' => 'on']],
                            'on'  => ['on' => ['TOGGLE_UNDERLINE' => 'off']],
                        ],
                    ],
                    'italics' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => ['on' => ['TOGGLE_ITALICS' => 'on']],
                            'on'  => ['on' => ['TOGGLE_ITALICS' => 'off']],
                        ],
                    ],
                    'list' => [
                        'initial' => 'none',
                        'states'  => [
                            'none'    => ['on' => ['BULLETS' => 'bullets', 'NUMBERS' => 'numbers']],
                            'bullets' => ['on' => ['NONE' => 'none', 'NUMBERS' => 'numbers']],
                            'numbers' => ['on' => ['BULLETS' => 'bullets', 'NONE' => 'none']],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Initial state - all off
    expect($state->matches('editing.bold.off'))->toBeTrue();
    expect($state->matches('editing.underline.off'))->toBeTrue();
    expect($state->matches('editing.italics.off'))->toBeTrue();
    expect($state->matches('editing.list.none'))->toBeTrue();

    // Toggle bold on
    $state = $definition->transition(['type' => 'TOGGLE_BOLD'], $state);
    expect($state->matches('editing.bold.on'))->toBeTrue();
    expect($state->matches('editing.underline.off'))->toBeTrue();

    // Toggle italics on
    $state = $definition->transition(['type' => 'TOGGLE_ITALICS'], $state);
    expect($state->matches('editing.bold.on'))->toBeTrue();
    expect($state->matches('editing.italics.on'))->toBeTrue();

    // Set bullets
    $state = $definition->transition(['type' => 'BULLETS'], $state);
    expect($state->matches('editing.list.bullets'))->toBeTrue();

    // Toggle bold off
    $state = $definition->transition(['type' => 'TOGGLE_BOLD'], $state);
    expect($state->matches('editing.bold.off'))->toBeTrue();
    expect($state->matches('editing.italics.on'))->toBeTrue();
    expect($state->matches('editing.list.bullets'))->toBeTrue();
});

test('deep nested parallel with multiple levels', function (): void {
    // Test deeply nested structure: parallel -> compound -> parallel
    $definition = MachineDefinition::define([
        'id'      => 'deep',
        'initial' => 'root',
        'states'  => [
            'root' => [
                'type'   => 'parallel',
                'states' => [
                    'branch1' => [
                        'initial' => 'leaf',
                        'states'  => [
                            'leaf' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'subleaf1' => [
                                        'initial' => 'a',
                                        'states'  => [
                                            'a' => ['on' => ['GO1' => 'b']],
                                            'b' => [],
                                        ],
                                    ],
                                    'subleaf2' => [
                                        'initial' => 'x',
                                        'states'  => [
                                            'x' => ['on' => ['GO2' => 'y']],
                                            'y' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'branch2' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'  => ['on' => ['DONE' => 'finished']],
                            'finished' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Check initial nested state
    expect($state->matches('root.branch1.leaf.subleaf1.a'))->toBeTrue();
    expect($state->matches('root.branch1.leaf.subleaf2.x'))->toBeTrue();
    expect($state->matches('root.branch2.waiting'))->toBeTrue();

    // Transition in deeply nested parallel
    $state = $definition->transition(['type' => 'GO1'], $state);
    expect($state->matches('root.branch1.leaf.subleaf1.b'))->toBeTrue();
    expect($state->matches('root.branch1.leaf.subleaf2.x'))->toBeTrue();

    // Another nested transition
    $state = $definition->transition(['type' => 'GO2'], $state);
    expect($state->matches('root.branch1.leaf.subleaf1.b'))->toBeTrue();
    expect($state->matches('root.branch1.leaf.subleaf2.y'))->toBeTrue();

    // Outer region transition
    $state = $definition->transition(['type' => 'DONE'], $state);
    expect($state->matches('root.branch1.leaf.subleaf1.b'))->toBeTrue();
    expect($state->matches('root.branch2.finished'))->toBeTrue();
});

test('transition entering parallel state initializes all nested parallel regions', function (): void {
    // When transitioning INTO a state that contains a nested parallel,
    // all regions of that nested parallel should be entered
    $definition = MachineDefinition::define([
        'id'      => 'transitionToParallel',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'START' => 'processing',
                ],
            ],
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'task1' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending'  => [],
                            'complete' => [],
                        ],
                    ],
                    'task2' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending'  => [],
                            'complete' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('idle'))->toBeTrue();

    // Transition to parallel state
    $state = $definition->transition(['type' => 'START'], $state);

    // Both regions should be active
    expect($state->matches('processing.task1.pending'))->toBeTrue();
    expect($state->matches('processing.task2.pending'))->toBeTrue();
    expect($state->isInParallelState())->toBeTrue();
});
