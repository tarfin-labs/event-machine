<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * These tests verify that all code examples in docs/advanced/parallel-states.md
 * actually work as documented.
 */

// =============================================================================
// EXAMPLE 1: Basic Syntax (lines 18-51)
// =============================================================================
test('docs example: basic parallel state syntax', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'editor',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'document' => [
                        'initial' => 'editing',
                        'states'  => [
                            'editing' => [
                                'on' => ['SAVE' => 'saving'],
                            ],
                            'saving' => [
                                'on' => ['SAVED' => 'editing'],
                            ],
                        ],
                    ],
                    'format' => [
                        'initial' => 'normal',
                        'states'  => [
                            'normal' => [
                                'on' => ['BOLD' => 'bold'],
                            ],
                            'bold' => [
                                'on' => ['NORMAL' => 'normal'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Verify initial state value (lines 76-85)
    expect($state->value)->toBe([
        'editor.active.document.editing',
        'editor.active.format.normal',
    ]);
});

// =============================================================================
// EXAMPLE 2: Checking Active States (lines 89-104)
// =============================================================================
test('docs example: checking active states with matches()', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'editor',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'document' => [
                        'initial' => 'editing',
                        'states'  => [
                            'editing' => ['on' => ['SAVE' => 'saving']],
                            'saving'  => [],
                        ],
                    ],
                    'format' => [
                        'initial' => 'normal',
                        'states'  => [
                            'normal' => ['on' => ['BOLD' => 'bold']],
                            'bold'   => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Check individual states (line 92-94)
    expect($state->matches('active.document.editing'))->toBeTrue();
    expect($state->matches('active.format.normal'))->toBeTrue();

    // Check multiple states at once (lines 97-100)
    expect($state->matchesAll([
        'active.document.editing',
        'active.format.bold',
    ]))->toBeFalse(); // format is in 'normal', not 'bold'

    // Check if in parallel state (line 103)
    expect($state->isInParallelState())->toBeTrue();
});

// =============================================================================
// EXAMPLE 3: Single Region Handling (lines 114-125)
// =============================================================================
test('docs example: single region handling', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'editor',
        'initial' => 'active',
        'states'  => [
            'active' => [
                'type'   => 'parallel',
                'states' => [
                    'document' => [
                        'initial' => 'editing',
                        'states'  => [
                            'editing' => ['on' => ['SAVE' => 'saving']],
                            'saving'  => [],
                        ],
                    ],
                    'format' => [
                        'initial' => 'normal',
                        'states'  => [
                            'normal' => ['on' => ['BOLD' => 'bold']],
                            'bold'   => ['on' => ['NORMAL' => 'normal']],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    // document: editing, format: normal

    $state = $definition->transition(['type' => 'BOLD'], $state);
    // document: editing (unchanged)
    // format: bold (transitioned)

    expect($state->matches('active.document.editing'))->toBeTrue();
    expect($state->matches('active.format.bold'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 4: Multiple Region Handling (lines 127-174)
// =============================================================================
test('docs example: multiple region handling - same event triggers both regions', function (): void {
    $definition = MachineDefinition::define([
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
                            'modified' => [],
                        ],
                    ],
                    'status' => [
                        'initial' => 'saved',
                        'states'  => [
                            'saved' => [
                                'on' => ['CHANGE' => 'unsaved'],
                            ],
                            'unsaved' => [
                                'on' => ['SAVE' => 'saved'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], [
        'actions' => [
            'updateValue' => function (ContextManager $ctx, EventBehavior $event): void {
                $ctx->set('value', $event->payload['value'] ?? 'changed');
            },
        ],
    ]);

    $state = $definition->getInitialState();

    // CHANGE event triggers transitions in BOTH regions
    $state = $definition->transition(['type' => 'CHANGE', 'payload' => ['value' => 'new']], $state);
    expect($state->matches('active.editing.modified'))->toBeTrue();
    expect($state->matches('active.status.unsaved'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 5: Entry Action Execution Order (lines 180-242)
// =============================================================================
test('docs example: entry action execution order', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'machine',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'entry'  => 'logParallelEntry',
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
                'logParallelEntry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '1. Entering parallel state';
                },
                'logRegion1Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '2. Entering region 1';
                },
                'logRegion2Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '3. Entering region 2';
                },
                'logRegion3Entry' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '4. Entering region 3';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Verify entry order: parallel -> region1 -> region2 -> region3
    expect($actionsExecuted)->toBe([
        '1. Entering parallel state',
        '2. Entering region 1',
        '3. Entering region 2',
        '4. Entering region 3',
    ]);
});

// =============================================================================
// EXAMPLE 6: Exit Action Execution Order (lines 244-302)
// =============================================================================
test('docs example: exit action execution order', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'machine',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'exit'   => 'logParallelExit',
                    'onDone' => 'inactive',
                    'states' => [
                        'region1' => [
                            'initial' => 'a',
                            'states'  => [
                                'a' => [
                                    'exit' => 'logStateAExit',
                                    'on'   => ['DONE1' => 'final1'],
                                ],
                                'final1' => ['type' => 'final'],
                            ],
                        ],
                        'region2' => [
                            'initial' => 'b',
                            'states'  => [
                                'b' => [
                                    'exit' => 'logStateBExit',
                                    'on'   => ['DONE2' => 'final2'],
                                ],
                                'final2' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'inactive' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'logStateAExit' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '1. Exiting state a';
                },
                'logStateBExit' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '2. Exiting state b';
                },
                'logParallelExit' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = '3. Exiting parallel state';
                },
            ],
        ]
    );

    $state           = $definition->getInitialState();
    $actionsExecuted = []; // Reset after entry actions

    // Complete both regions to trigger onDone
    $state = $definition->transition(['type' => 'DONE1'], $state);
    $state = $definition->transition(['type' => 'DONE2'], $state);

    // Verify exit order: leaf states -> parallel state
    expect($actionsExecuted)->toContain('1. Exiting state a');
    expect($actionsExecuted)->toContain('2. Exiting state b');
    expect($actionsExecuted)->toContain('3. Exiting parallel state');
    expect($state->matches('inactive'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 7: Shared Context (lines 311-365)
// =============================================================================
test('docs example: shared context across regions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'counter',
            'initial' => 'active',
            'context' => ['count' => 0],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'incrementer' => [
                            'initial' => 'ready',
                            'states'  => [
                                'ready' => [
                                    'on' => [
                                        'INCREMENT' => [
                                            'actions' => 'increment',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'decrementer' => [
                            'initial' => 'ready',
                            'states'  => [
                                'ready' => [
                                    'on' => [
                                        'DECREMENT' => [
                                            'actions' => 'decrement',
                                        ],
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
                'increment' => function (ContextManager $ctx): void {
                    $ctx->set('count', $ctx->get('count') + 1);
                },
                'decrement' => function (ContextManager $ctx): void {
                    $ctx->set('count', $ctx->get('count') - 1);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->context->get('count'))->toBe(0);

    $state = $definition->transition(['type' => 'INCREMENT'], $state);
    expect($state->context->get('count'))->toBe(1);

    $state = $definition->transition(['type' => 'INCREMENT'], $state);
    expect($state->context->get('count'))->toBe(2);

    $state = $definition->transition(['type' => 'DECREMENT'], $state);
    expect($state->context->get('count'))->toBe(1);
});

// =============================================================================
// EXAMPLE 8: onDone Transition (lines 367-408)
// =============================================================================
test('docs example: onDone transitions when all regions are final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'checkout',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'onDone' => 'complete',
                'states' => [
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => ['PAYMENT_SUCCESS' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => [
                                'on' => ['SHIPPED' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'complete' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    // processing.payment.pending, processing.shipping.preparing

    $state = $definition->transition(['type' => 'PAYMENT_SUCCESS'], $state);
    // processing.payment.done, processing.shipping.preparing
    // Still in processing - shipping not complete
    expect($state->matches('processing.payment.done'))->toBeTrue();
    expect($state->matches('processing.shipping.preparing'))->toBeTrue();

    $state = $definition->transition(['type' => 'SHIPPED'], $state);
    // Now both regions are final - automatically transitions to 'complete'
    expect($state->matches('complete'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 9: Transitioning Into Parallel States (lines 626-669)
// =============================================================================
test('docs example: transitioning from non-parallel to parallel', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'app',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => ['START' => 'processing'],
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

    $state = $definition->transition(['type' => 'START'], $state);
    // Both regions are automatically entered
    expect($state->matches('processing.task1.pending'))->toBeTrue();
    expect($state->matches('processing.task2.pending'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 10: Transitioning Into Nested Parallel (lines 671-741)
// =============================================================================
test('docs example: transitioning into nested parallel within parallel region', function (): void {
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
                                'on' => ['ACTIVATE' => 'on'],
                            ],
                            'on' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'inner1' => [
                                        'initial' => 'idle',
                                        'states'  => [
                                            'idle'    => ['on' => ['WORK1' => 'working']],
                                            'working' => [],
                                        ],
                                    ],
                                    'inner2' => [
                                        'initial' => 'idle',
                                        'states'  => [
                                            'idle'    => ['on' => ['WORK2' => 'working']],
                                            'working' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'outer2' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting' => ['on' => ['PROCEED' => 'done']],
                            'done'    => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    // Initial: outer1.off, outer2.waiting
    expect($state->value)->toBe([
        'nested.active.outer1.off',
        'nested.active.outer2.waiting',
    ]);

    // Transition to 'on' which is a parallel state
    $state = $definition->transition(['type' => 'ACTIVATE'], $state);

    // The nested parallel is fully expanded - both inner regions entered!
    expect($state->value)->toBe([
        'nested.active.outer1.on.inner1.idle',
        'nested.active.outer1.on.inner2.idle',
        'nested.active.outer2.waiting',
    ]);

    expect($state->matches('active.outer1.on.inner1.idle'))->toBeTrue();
    expect($state->matches('active.outer1.on.inner2.idle'))->toBeTrue();
    expect($state->matches('active.outer2.waiting'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 11: Deep Nesting (lines 474-598)
// =============================================================================
test('docs example: deep nesting (3+ levels)', function (): void {
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
                            'waiting'  => ['on' => ['FINISH' => 'finished']],
                            'finished' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // All leaf states should be in initial
    expect($state->value)->toBe([
        'deep.root.branch1.leaf.subleaf1.a',
        'deep.root.branch1.leaf.subleaf2.x',
        'deep.root.branch2.waiting',
    ]);

    // Verify matches with full paths (partial paths don't work!)
    expect($state->matches('root.branch1.leaf.subleaf1.a'))->toBeTrue();
    expect($state->matches('root.branch1.leaf.subleaf2.x'))->toBeTrue();
    expect($state->matches('root.branch2.waiting'))->toBeTrue();

    // Intermediate paths do NOT match (this is correct behavior)
    expect($state->matches('root.branch1.leaf'))->toBeFalse();
    expect($state->matches('root.branch1'))->toBeFalse();
    expect($state->matches('root'))->toBeFalse();
    expect($state->matches('branch2.waiting'))->toBeFalse();
    expect($state->matches('subleaf1.a'))->toBeFalse();

    // Transition in nested parallel
    $state = $definition->transition(['type' => 'GO1'], $state);
    expect($state->matches('root.branch1.leaf.subleaf1.b'))->toBeTrue();
    expect($state->matches('root.branch1.leaf.subleaf2.x'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 12: Guards Checking Cross-Region State (lines 891-928)
// =============================================================================
test('docs example: guards checking cross-region state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'workflow',
            'initial' => 'parallel',
            'states'  => [
                'parallel' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region1' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'PROCEED' => [
                                            'target' => 'done',
                                            'guards' => 'isRegion2Ready',
                                        ],
                                    ],
                                ],
                                'done' => [],
                            ],
                        ],
                        'region2' => [
                            'initial' => 'preparing',
                            'states'  => [
                                'preparing' => [
                                    'on' => ['READY' => 'ready'],
                                ],
                                'ready' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isRegion2Ready' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('parallel.region2.ready'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Try to PROCEED when region2 is not ready - should fail
    try {
        $state = $definition->transition(['type' => 'PROCEED'], $state);
        // Guard should block this
        expect($state->matches('parallel.region1.waiting'))->toBeTrue();
    } catch (\Exception $e) {
        // Guard blocked the transition - this is expected
    }

    // Make region2 ready
    $state = $definition->transition(['type' => 'READY'], $state);
    expect($state->matches('parallel.region2.ready'))->toBeTrue();

    // Now PROCEED should work
    $state = $definition->transition(['type' => 'PROCEED'], $state);
    expect($state->matches('parallel.region1.done'))->toBeTrue();
});

// =============================================================================
// EXAMPLE 13: Text Formatting (Multiple Toggles) (lines 766-842)
// =============================================================================
test('docs example: text formatting with multiple toggles', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'formatting',
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
                    'italic' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => ['on' => ['TOGGLE_ITALIC' => 'on']],
                            'on'  => ['on' => ['TOGGLE_ITALIC' => 'off']],
                        ],
                    ],
                    'underline' => [
                        'initial' => 'off',
                        'states'  => [
                            'off' => ['on' => ['TOGGLE_UNDERLINE' => 'on']],
                            'on'  => ['on' => ['TOGGLE_UNDERLINE' => 'off']],
                        ],
                    ],
                    'list' => [
                        'initial' => 'none',
                        'states'  => [
                            'none' => [
                                'on' => [
                                    'BULLETS' => 'bullets',
                                    'NUMBERS' => 'numbers',
                                ],
                            ],
                            'bullets' => [
                                'on' => [
                                    'NONE'    => 'none',
                                    'NUMBERS' => 'numbers',
                                ],
                            ],
                            'numbers' => [
                                'on' => [
                                    'BULLETS' => 'bullets',
                                    'NONE'    => 'none',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();
    // All formatting off, no list

    $state = $definition->transition(['type' => 'TOGGLE_BOLD'], $state);
    $state = $definition->transition(['type' => 'TOGGLE_ITALIC'], $state);
    $state = $definition->transition(['type' => 'BULLETS'], $state);

    expect($state->matches('editing.bold.on'))->toBeTrue();
    expect($state->matches('editing.italic.on'))->toBeTrue();
    expect($state->matches('editing.underline.off'))->toBeTrue();
    expect($state->matches('editing.list.bullets'))->toBeTrue();
});
