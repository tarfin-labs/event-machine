<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

/*
 * Deep hierarchy entry/exit action ordering verification.
 *
 * Tests that entry and exit actions fire in the correct order when
 * transitioning into and out of 3+ level deep nested state hierarchies.
 *
 * EventMachine behavior:
 * - Exit: only the transition's source state (where the transition is declared)
 *   runs exit actions. Ancestor exit actions are NOT automatically invoked.
 * - Entry: the main transition() method pre-resolves compound targets to the
 *   deepest initial leaf, so only the leaf's entry actions run. Ancestor and
 *   intermediate entry actions are NOT invoked during transitions.
 * - This differs from SCXML which exits all states bottom-up and enters all
 *   states top-down along the hierarchy path.
 */

// region Deep hierarchy exit ordering

it('fires only the source state exit actions when transitioning out of a 3-level deep hierarchy', function (): void {
    TestMachine::define([
        'id'      => 'deep_exit_ordering',
        'initial' => 'level_one.level_two.level_three',
        'context' => ['actionLog' => []],
        'states'  => [
            'level_one' => [
                'exit'   => 'exitLevelOneAction',
                'states' => [
                    'level_two' => [
                        'exit'   => 'exitLevelTwoAction',
                        'states' => [
                            'level_three' => [
                                'exit' => 'exitLevelThreeAction',
                                'on'   => [
                                    'GO' => 'target_state',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'target_state' => [
                'entry' => 'entryTargetAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_one']);
            },
            'exitLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_two']);
            },
            'exitLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_three']);
            },
            'entryTargetAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:target_state']);
            },
        ],
    ])
        ->assertState('level_one.level_two.level_three')
        ->assertContext('actionLog', [])
        ->send('GO')
        ->assertState('target_state')
        ->assertContext('actionLog', [
            // EventMachine only exits the source state (where the transition is defined).
            // Ancestor states (level_two, level_one) do NOT receive exit actions.
            'exit:level_three',
            'entry:target_state',
        ]);
});

// endregion

// region Deep hierarchy entry ordering — compound target

it('fires entry actions only on the resolved leaf when entering a deep hierarchy via compound target', function (): void {
    TestMachine::define([
        'id'      => 'deep_entry_compound',
        'initial' => 'start',
        'context' => ['actionLog' => []],
        'states'  => [
            'start' => [
                'on' => [
                    // Target the compound parent — transition() pre-resolves to deepest initial leaf
                    'GO' => 'level_one',
                ],
            ],
            'level_one' => [
                'entry'  => 'entryLevelOneAction',
                'states' => [
                    'level_two' => [
                        'entry'  => 'entryLevelTwoAction',
                        'states' => [
                            'level_three' => [
                                'entry' => 'entryLevelThreeAction',
                                'type'  => 'final',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_one']);
            },
            'entryLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_two']);
            },
            'entryLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_three']);
            },
        ],
    ])
        ->assertState('start')
        ->assertContext('actionLog', [])
        ->send('GO')
        ->assertState('level_one.level_two.level_three')
        ->assertContext('actionLog', [
            // The main transition() method pre-resolves compound targets to the deepest
            // initial leaf via findInitialStateDefinition(). enterState() then receives
            // the leaf directly, so only the leaf's entry actions run.
            // Ancestor entry actions (level_one, level_two) are NOT invoked.
            'entry:level_three',
        ]);
});

// endregion

// region Deep hierarchy entry ordering — explicit leaf target

it('fires entry actions only on the leaf when targeting a deeply nested state directly', function (): void {
    TestMachine::define([
        'id'      => 'deep_entry_leaf',
        'initial' => 'start',
        'context' => ['actionLog' => []],
        'states'  => [
            'start' => [
                'on' => [
                    // Target the leaf state explicitly
                    'GO' => 'level_one.level_two.level_three',
                ],
            ],
            'level_one' => [
                'entry'  => 'entryLevelOneAction',
                'states' => [
                    'level_two' => [
                        'entry'  => 'entryLevelTwoAction',
                        'states' => [
                            'level_three' => [
                                'entry' => 'entryLevelThreeAction',
                                'type'  => 'final',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_one']);
            },
            'entryLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_two']);
            },
            'entryLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_three']);
            },
        ],
    ])
        ->assertState('start')
        ->assertContext('actionLog', [])
        ->send('GO')
        ->assertState('level_one.level_two.level_three')
        ->assertContext('actionLog', [
            // Explicit leaf target resolves directly to the leaf state definition.
            // Only the leaf's entry actions run — same behavior as compound target.
            'entry:level_three',
        ]);
});

// endregion

// region Full round-trip: entry into deep hierarchy then exit

it('maintains correct action ordering for a full round-trip through a deep hierarchy', function (): void {
    TestMachine::define([
        'id'      => 'deep_roundtrip',
        'initial' => 'outside',
        'context' => ['actionLog' => []],
        'states'  => [
            'outside' => [
                'exit' => 'exitOutsideAction',
                'on'   => [
                    'ENTER_DEEP' => 'level_one',
                ],
            ],
            'level_one' => [
                'entry'  => 'entryLevelOneAction',
                'exit'   => 'exitLevelOneAction',
                'states' => [
                    'level_two' => [
                        'entry'  => 'entryLevelTwoAction',
                        'exit'   => 'exitLevelTwoAction',
                        'states' => [
                            'level_three' => [
                                'entry' => 'entryLevelThreeAction',
                                'exit'  => 'exitLevelThreeAction',
                                'on'    => [
                                    'LEAVE_DEEP' => 'done',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'done' => [
                'entry' => 'entryDoneAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitOutsideAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:outside']);
            },
            'entryLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_one']);
            },
            'exitLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_one']);
            },
            'entryLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_two']);
            },
            'exitLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_two']);
            },
            'entryLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:level_three']);
            },
            'exitLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_three']);
            },
            'entryDoneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:done']);
            },
        ],
    ])
        ->assertState('outside')
        ->assertContext('actionLog', [])
        ->send('ENTER_DEEP')
        ->assertState('level_one.level_two.level_three')
        ->assertContext('actionLog', [
            // Exit: only source state (outside) exit runs
            // Entry: only resolved leaf (level_three) entry runs
            'exit:outside',
            'entry:level_three',
        ])
        ->send('LEAVE_DEEP')
        ->assertState('done')
        ->assertContext('actionLog', [
            // Previous actions preserved
            'exit:outside',
            'entry:level_three',
            // Exit: only source state (level_three) exit runs
            // Entry: only target leaf (done) entry runs
            'exit:level_three',
            'entry:done',
        ]);
});

// endregion

// region Event bubbling: ancestor-defined transition fires ancestor exit

it('fires ancestor exit actions when the transition is defined on an ancestor state', function (): void {
    TestMachine::define([
        'id'      => 'deep_ancestor_exit',
        'initial' => 'level_one.level_two.level_three',
        'context' => ['actionLog' => []],
        'states'  => [
            'level_one' => [
                'exit' => 'exitLevelOneAction',
                // Transition defined on ancestor — event bubbles up from level_three
                'on' => [
                    'GO' => 'target_state',
                ],
                'states' => [
                    'level_two' => [
                        'exit'   => 'exitLevelTwoAction',
                        'states' => [
                            'level_three' => [
                                'exit' => 'exitLevelThreeAction',
                            ],
                        ],
                    ],
                ],
            ],
            'target_state' => [
                'entry' => 'entryTargetAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitLevelOneAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_one']);
            },
            'exitLevelTwoAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_two']);
            },
            'exitLevelThreeAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:level_three']);
            },
            'entryTargetAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:target_state']);
            },
        ],
    ])
        ->assertState('level_one.level_two.level_three')
        ->assertContext('actionLog', [])
        ->send('GO')
        ->assertState('target_state')
        ->assertContext('actionLog', [
            // When the event bubbles up and the transition is defined on level_one,
            // the source of the transition is level_one, so only level_one's exit runs.
            // Descendant exit actions (level_two, level_three) are NOT invoked.
            'exit:level_one',
            'entry:target_state',
        ]);
});

// endregion
