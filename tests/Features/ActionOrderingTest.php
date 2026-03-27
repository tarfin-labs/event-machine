<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

// ═══════════════════════════════════════════════════════════════════════════════
//  SCXML-correct action ordering tests (W3C §3.13)
//
//  The SCXML specification mandates: exit(source) → transition → entry(target).
//  These tests define the CORRECT ordering. Tests that fail against current code
//  are expected — they serve as TDD anchors for the fix.
//
//  @see https://www.w3.org/TR/scxml/#SelectingTransitions
// ═══════════════════════════════════════════════════════════════════════════════

// ---------------------------------------------------------------------------
// 1. Basic A→B: exit → transition → entry (SCXML canonical order)
// ---------------------------------------------------------------------------
it('follows SCXML ordering: exit:A → transition:A->B → entry:B', function (): void {
    TestMachine::define([
        'id'      => 'scxml_basic_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => []],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:state_a->state_b']);
            },
            'entryBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:state_b']);
            },
        ],
    ])
        ->assertState('state_a')
        ->assertContext('actionLog', [])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('actionLog', [
            'exit:state_a',
            'transition:state_a->state_b',
            'entry:state_b',
        ]);
});

// ---------------------------------------------------------------------------
// 2. Deep hierarchy (3 levels): A.A1.A1a → B
//    Exit order must be inner-to-outer: A1a, A1, A, then transition, then entry:B
// ---------------------------------------------------------------------------
// TODO: Pending — hierarchical exit requires LCA-aware recursive exit walking up to
//       the Lowest Common Ancestor. Current single-level exit is correct for flat
//       transitions; deep hierarchy needs a dedicated spec.
it('exits deeply nested hierarchy inner-to-outer before transition action', function (): void {
    TestMachine::define([
        'id'      => 'deep_hierarchy_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => []],
        'states'  => [
            'state_a' => [
                'initial' => 'state_a1',
                'exit'    => 'exitAAction',
                'on'      => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionAction',
                    ],
                ],
                'states' => [
                    'state_a1' => [
                        'initial' => 'state_a1a',
                        'exit'    => 'exitA1Action',
                        'states'  => [
                            'state_a1a' => [
                                'exit' => 'exitA1aAction',
                            ],
                        ],
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitA1aAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a1a']);
            },
            'exitA1Action' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a1']);
            },
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:A->B']);
            },
            'entryBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:state_b']);
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('actionLog', [
            // Inner-to-outer exit, then transition, then entry
            'exit:state_a1a',
            'exit:state_a1',
            'exit:state_a',
            'transition:A->B',
            'entry:state_b',
        ]);
})->skip('Pending: hierarchical exit requires LCA-aware recursive exit — see spec');

// ---------------------------------------------------------------------------
// 3. Sibling transition: P.A → P.B
//    Only the siblings exit/enter; parent P is NOT exited or re-entered.
// ---------------------------------------------------------------------------
// TODO: Pending — sibling transitions need LCA computation to avoid exiting/re-entering
//       the common parent. Current code exits and re-enters parent; needs dedicated spec.
it('does not exit or re-enter parent during sibling transition', function (): void {
    TestMachine::define([
        'id'      => 'sibling_ordering',
        'initial' => 'parent',
        'context' => ['actionLog' => []],
        'states'  => [
            'parent' => [
                'initial' => 'child_a',
                'entry'   => 'entryParentAction',
                'exit'    => 'exitParentAction',
                'states'  => [
                    'child_a' => [
                        'exit' => 'exitChildAAction',
                        'on'   => [
                            'GO' => [
                                'target'  => 'child_b',
                                'actions' => 'transitionAction',
                            ],
                        ],
                    ],
                    'child_b' => [
                        'entry' => 'entryChildBAction',
                        'type'  => 'final',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryParentAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:parent']);
            },
            'exitParentAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:parent']);
            },
            'exitChildAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:child_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:child_a->child_b']);
            },
            'entryChildBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:child_b']);
            },
        ],
    ])
        ->assertContext('actionLog', ['entry:parent'])
        ->send('GO')
        ->assertContext('actionLog', [
            'entry:parent',
            // Parent NOT exited — only child_a exits
            'exit:child_a',
            'transition:child_a->child_b',
            'entry:child_b',
            // Parent NOT re-entered
        ]);
})->skip('Pending: hierarchical exit requires LCA-aware recursive exit — see spec');

// ---------------------------------------------------------------------------
// 4. Self-transition: A → A — exit, transition, then re-enter same state.
// ---------------------------------------------------------------------------
it('runs exit then transition then entry on self-transition', function (): void {
    TestMachine::define([
        'id'      => 'self_transition_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => [], 'count' => 0],
        'states'  => [
            'state_a' => [
                'entry' => 'entryAAction',
                'exit'  => 'exitAAction',
                'on'    => [
                    'LOOP' => [
                        'target'  => 'state_a',
                        'actions' => 'transitionAction',
                    ],
                    'DONE' => 'state_b',
                ],
            ],
            'state_b' => [
                'type' => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:state_a']);
            },
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:state_a->state_a']);
            },
        ],
    ])
        ->assertContext('actionLog', ['entry:state_a'])
        ->send('LOOP')
        ->assertState('state_a')
        ->assertContext('actionLog', [
            'entry:state_a',       // initial entry
            'exit:state_a',        // exit before transition
            'transition:state_a->state_a',
            'entry:state_a',       // re-entry after transition
        ]);
});

// ---------------------------------------------------------------------------
// 5. Calculator runs before exit (calculators are pre-guard, before any
//    exit/transition/entry actions).
// ---------------------------------------------------------------------------
it('runs calculator before exit actions', function (): void {
    TestMachine::define([
        'id'      => 'calculator_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => [], 'computed' => null],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'      => 'state_b',
                        'calculators' => 'computeValueCalculator',
                        'actions'     => 'transitionAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:A->B']);
            },
            'entryBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:state_b']);
            },
        ],
        'calculators' => [
            'computeValueCalculator' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'calculator']);
                $context->set('computed', 42);
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('computed', 42)
        ->assertContext('actionLog', [
            // Calculator runs FIRST (before guard evaluation, before any exit/transition/entry)
            'calculator',
            'exit:state_a',
            'transition:A->B',
            'entry:state_b',
        ]);
});

// ---------------------------------------------------------------------------
// 6. Guard evaluates before exit: guard sees the source state context
//    (the state has not been exited yet when the guard runs).
// ---------------------------------------------------------------------------
it('evaluates guard before exit so guard sees source state context', function (): void {
    TestMachine::define([
        'id'      => 'guard_before_exit_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => [], 'sourceValue' => 'original'],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'guards'  => 'checkSourceValueGuard',
                        'actions' => 'transitionAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                // Exit modifies context — guard should NOT see this
                $context->set('sourceValue', 'modified_by_exit');
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:A->B']);
            },
            'entryBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:state_b']);
            },
        ],
        'guards' => [
            'checkSourceValueGuard' => function (ContextManager $context): bool {
                // Guard runs BEFORE exit — should see 'original', not 'modified_by_exit'
                $context->set('actionLog', [...$context->get('actionLog'), 'guard:saw:'.$context->get('sourceValue')]);

                return true;
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('actionLog', [
            'guard:saw:original',  // Guard ran before exit, sees original value
            'exit:state_a',
            'transition:A->B',
            'entry:state_b',
        ]);
});

// ---------------------------------------------------------------------------
// 7. @always chain: action ordering through the chain.
//    A →(GO) B →(@always) C →(@always) D
//    Each hop follows exit → transition → entry independently.
// ---------------------------------------------------------------------------
it('follows exit → transition → entry at each hop in @always chain', function (): void {
    TestMachine::define([
        'id'      => 'always_chain_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => []],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionABAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'exit'  => 'exitBAction',
                'on'    => [
                    '@always' => [
                        'target'  => 'state_c',
                        'actions' => 'transitionBCAction',
                    ],
                ],
            ],
            'state_c' => [
                'entry' => 'entryCAction',
                'exit'  => 'exitCAction',
                'on'    => [
                    '@always' => [
                        'target'  => 'state_d',
                        'actions' => 'transitionCDAction',
                    ],
                ],
            ],
            'state_d' => [
                'entry' => 'entryDAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:A']);
            },
            'transitionABAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:A->B']);
            },
            'entryBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:B']);
            },
            'exitBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:B']);
            },
            'transitionBCAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:B->C']);
            },
            'entryCAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:C']);
            },
            'exitCAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:C']);
            },
            'transitionCDAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:C->D']);
            },
            'entryDAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:D']);
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_d')
        ->assertContext('actionLog', [
            // Hop 1: A → B
            'exit:A',
            'transition:A->B',
            'entry:B',
            // Hop 2: B → C (@always)
            'exit:B',
            'transition:B->C',
            'entry:C',
            // Hop 3: C → D (@always)
            'exit:C',
            'transition:C->D',
            'entry:D',
        ]);
});

// ---------------------------------------------------------------------------
// 8. Parallel region entry ordering: regions entered in document order
//    AFTER the parallel state's own entry action runs.
// ---------------------------------------------------------------------------
it('enters parallel regions in document order after parallel state entry', function (): void {
    TestMachine::define([
        'id'      => 'parallel_entry_ordering',
        'initial' => 'idle',
        'context' => ['actionLog' => []],
        'states'  => [
            'idle' => [
                'on' => [
                    'START' => 'processing',
                ],
            ],
            'processing' => [
                'type'   => 'parallel',
                'entry'  => 'entryProcessingAction',
                'states' => [
                    'region_alpha' => [
                        'initial' => 'alpha_active',
                        'states'  => [
                            'alpha_active' => [
                                'entry' => 'entryAlphaAction',
                            ],
                        ],
                    ],
                    'region_beta' => [
                        'initial' => 'beta_active',
                        'states'  => [
                            'beta_active' => [
                                'entry' => 'entryBetaAction',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryProcessingAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:processing']);
            },
            'entryAlphaAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:alpha_active']);
            },
            'entryBetaAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:beta_active']);
            },
        ],
    ])
        ->send('START')
        ->assertContext('actionLog', [
            // Parallel state entry first, then regions in document order
            'entry:processing',
            'entry:alpha_active',
            'entry:beta_active',
        ]);
});

// ---------------------------------------------------------------------------
// 9. Exit action modifies context, transition action reads modified value.
//    Proves exit runs before transition action in SCXML order.
// ---------------------------------------------------------------------------
it('allows transition action to read context modified by exit action', function (): void {
    TestMachine::define([
        'id'      => 'exit_modifies_context_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => [], 'counter' => 0],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                // Exit increments counter
                $context->set('counter', $context->get('counter') + 10);
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:counter='.$context->get('counter')]);
            },
            'transitionAction' => function (ContextManager $context): void {
                // Transition should see counter=10 (set by exit) in SCXML order
                $context->set('counter', $context->get('counter') + 5);
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:counter='.$context->get('counter')]);
            },
            'entryBAction' => function (ContextManager $context): void {
                // Entry should see counter=15 (exit+transition)
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:counter='.$context->get('counter')]);
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('counter', 15)
        ->assertContext('actionLog', [
            'exit:counter=10',       // Exit runs first, sets counter to 10
            'transition:counter=15', // Transition reads 10, adds 5 = 15
            'entry:counter=15',      // Entry sees final value 15
        ]);
});

// ---------------------------------------------------------------------------
// 10. Entry action raises event: raised event processed AFTER full entry
//     completes, not during the transition phase.
// ---------------------------------------------------------------------------
it('processes raised events from entry action after entry phase completes', function (): void {
    TestMachine::define([
        'id'      => 'entry_raise_ordering',
        'initial' => 'state_a',
        'context' => ['actionLog' => []],
        'states'  => [
            'state_a' => [
                'exit' => 'exitAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionABAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryBAndRaiseAction',
                'exit'  => 'exitBAction',
                'on'    => [
                    'AUTO_ADVANCE' => [
                        'target'  => 'state_c',
                        'actions' => 'transitionBCAction',
                    ],
                ],
            ],
            'state_c' => [
                'entry' => 'entryCAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:A']);
            },
            'transitionABAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:A->B']);
            },
            'entryBAndRaiseAction' => EntryBAndRaiseAutoAdvanceAction::class,
            'exitBAction'          => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:B']);
            },
            'transitionBCAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'transition:B->C']);
            },
            'entryCAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:C']);
            },
        ],
    ])
        ->send('GO')
        ->assertState('state_c')
        ->assertContext('actionLog', [
            // First transition: A → B (SCXML order)
            'exit:A',
            'transition:A->B',
            'entry:B',
            // Raised event processed AFTER B's entry completed
            // Second transition: B → C (SCXML order)
            'exit:B',
            'transition:B->C',
            'entry:C',
        ]);
});

// ═══════════════════════════════════════════════════════════════════════════════
//  Stub Action Classes
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Entry action that logs and raises AUTO_ADVANCE event.
 * Used by test #10 to verify raise is processed after entry completes.
 */
class EntryBAndRaiseAutoAdvanceAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('actionLog', [...$context->get('actionLog'), 'entry:B']);
        $this->raise(['type' => 'AUTO_ADVANCE']);
    }
}
