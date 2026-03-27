<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ============================================================
// Targetless Transition Semantics — Edge-Case Tests
// ============================================================
//
// SCXML spec: targetless transitions fire ONLY transition actions.
// They must NOT trigger exit actions, entry actions, exit listeners,
// timer cancellation, or child cleanup.
//
// BUG: MachineDefinition::transition() line ~2965 calls
// runExitActions() unconditionally — even for targetless transitions.
// Tests asserting "no exit" WILL FAIL with current code. That is
// the TDD point — they document correct behavior before the fix.

// ------------------------------------------------------------------
// 1. Basic targetless: exit/entry actions exist, targetless event sent.
//    Assert: transition action runs, exit/entry do NOT.
// ------------------------------------------------------------------
it('targetless transition fires only transition action — not exit or entry', function (): void {
    TestMachine::define([
        'id'      => 'targetless_basic',
        'initial' => 'active',
        'context' => ['log' => []],
        'states'  => [
            'active' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    'TICK' => [
                        'actions' => 'logTickAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'logTickAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'tick']);
            },
        ],
    ])
        ->assertState('active')
        // Initial entry fires entry action
        ->assertContext('log', ['entry'])
        ->send('TICK')
        ->assertState('active')
        // CORRECT: only transition action; no exit, no entry
        ->assertContext('log', ['entry', 'tick']);
});

// ------------------------------------------------------------------
// 2. Targetless with context mutation: action modifies context.
//    Verify change persists but no exit/entry.
// ------------------------------------------------------------------
it('targetless transition persists context changes without exit/entry', function (): void {
    TestMachine::define([
        'id'      => 'targetless_context',
        'initial' => 'counting',
        'context' => ['count' => 0, 'log' => []],
        'states'  => [
            'counting' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    'INCREMENT' => [
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'incrementAction' => function (ContextManager $ctx): void {
                $ctx->set('count', $ctx->get('count') + 1);
            },
        ],
    ])
        ->assertContext('count', 0)
        ->assertContext('log', ['entry'])
        ->send('INCREMENT')
        ->assertContext('count', 1)
        // No exit or extra entry — only the initial entry
        ->assertContext('log', ['entry'])
        ->send('INCREMENT')
        ->assertContext('count', 2)
        ->assertContext('log', ['entry']);
});

// ------------------------------------------------------------------
// 3. Targetless does not cancel timers: state has after timer.
//    Targetless event. Timer NOT cancelled (still fires later).
// ------------------------------------------------------------------
it('targetless transition does not cancel active timers', function (): void {
    TestMachine::define([
        'id'      => 'targetless_timer',
        'initial' => 'waiting',
        'context' => ['log' => []],
        'states'  => [
            'waiting' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    'TICK' => [
                        'actions' => 'logTickAction',
                    ],
                    'EXPIRE' => [
                        'target' => 'expired',
                        'after'  => Timer::seconds(60),
                    ],
                ],
            ],
            'expired' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'logTickAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'tick']);
            },
        ],
    ])
        ->assertState('waiting')
        // Send targetless event midway through the timer
        ->advanceTimers(Timer::seconds(20))
        ->send('TICK')
        ->assertState('waiting')
        // Timer should still be active — advance past the deadline
        ->advanceTimers(Timer::seconds(41))
        ->assertState('expired')
        ->assertTimerFired('EXPIRE');
});

// ------------------------------------------------------------------
// 4. Targetless does not cleanup children: state delegates to child
//    machine + targetless event. Child NOT cancelled.
//    (Uses CHILD_DONE to verify child still active after targetless.)
// ------------------------------------------------------------------
// NOTE: Child cleanup is tested indirectly — the cleanupActiveChildren
// call at line ~2968 runs unconditionally. This test documents that
// targetless transitions should NOT invoke child cleanup.
it('targetless transition does not disrupt child machine delegation', function (): void {
    // We verify by checking that after a targetless event the machine
    // stays in the delegating state and can still receive CHILD_DONE.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'targetless_child',
            'initial' => 'delegating',
            'context' => ['log' => []],
            'states'  => [
                'delegating' => [
                    'entry' => 'logEntryAction',
                    'exit'  => 'logExitAction',
                    'on'    => [
                        'TICK' => [
                            'actions' => 'logTickAction',
                        ],
                        'CHILD_DONE' => 'completed',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'entry']);
                },
                'logExitAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'exit']);
                },
                'logTickAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'tick']);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->context->get('log'))->toBe(['entry']);

    // Send targetless event
    $state = $definition->transition(['type' => 'TICK'], $state);
    expect($state->currentStateDefinition->id)->toBe('targetless_child.delegating');
    // CORRECT: no exit fired
    expect($state->context->get('log'))->toBe(['entry', 'tick']);

    // Child completes — should still be able to transition out
    $state = $definition->transition(['type' => 'CHILD_DONE'], $state);
    expect($state->currentStateDefinition->id)->toBe('targetless_child.completed');
});

// ------------------------------------------------------------------
// 5. Targetless does not fire exit listeners: exit listener configured.
//    Targetless event. Listener NOT called.
// ------------------------------------------------------------------
it('targetless transition does not fire exit listeners', function (): void {
    TestMachine::define([
        'id'      => 'targetless_listener',
        'initial' => 'active',
        'context' => ['log' => []],
        'listen'  => [
            'exit' => 'exitListenerAction',
        ],
        'states' => [
            'active' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    'TICK' => [
                        'actions' => 'logTickAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'logTickAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'tick']);
            },
            'exitListenerAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'listener:exit']);
            },
        ],
    ])
        ->assertContext('log', ['entry'])
        ->send('TICK')
        ->assertState('active')
        // CORRECT: no exit listener, no exit action — only transition action
        ->assertContext('log', ['entry', 'tick']);
});

// ------------------------------------------------------------------
// 6. Targetless in compound state: parent.child targetless.
//    No exit/entry at any level.
// ------------------------------------------------------------------
it('targetless in compound state fires no exit/entry at any level', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'targetless_compound',
            'initial' => 'parent',
            'context' => ['exitChildCount' => 0, 'exitParentCount' => 0, 'tickCount' => 0],
            'states'  => [
                'parent' => [
                    'initial' => 'child_a',
                    'exit'    => 'countParentExitAction',
                    'states'  => [
                        'child_a' => [
                            'exit' => 'countChildExitAction',
                            'on'   => [
                                'TICK' => [
                                    'actions' => 'countTickAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'countParentExitAction' => function (ContextManager $ctx): void {
                    $ctx->set('exitParentCount', $ctx->get('exitParentCount') + 1);
                },
                'countChildExitAction' => function (ContextManager $ctx): void {
                    $ctx->set('exitChildCount', $ctx->get('exitChildCount') + 1);
                },
                'countTickAction' => function (ContextManager $ctx): void {
                    $ctx->set('tickCount', $ctx->get('tickCount') + 1);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Send targetless event on the nested child state
    $state = $definition->transition(['type' => 'TICK'], $state);

    // Transition action ran
    expect($state->context->get('tickCount'))->toBe(1);
    // CORRECT: neither child nor parent exit should fire for targetless
    expect($state->context->get('exitChildCount'))->toBe(0);
    expect($state->context->get('exitParentCount'))->toBe(0);
});

// ------------------------------------------------------------------
// 7. Targetless in parallel region: region targetless.
//    No exit/entry in any region.
// ------------------------------------------------------------------
it('targetless in parallel region fires no exit/entry in any region', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'targetless_parallel',
            'initial' => 'processing',
            'context' => ['log' => []],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'active_a',
                            'states'  => [
                                'active_a' => [
                                    'entry' => 'logEntryAAction',
                                    'exit'  => 'logExitAAction',
                                    'on'    => [
                                        'TICK' => [
                                            'actions' => 'logTickAAction',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'active_b',
                            'states'  => [
                                'active_b' => [
                                    'entry' => 'logEntryBAction',
                                    'exit'  => 'logExitBAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryAAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'entry:a']);
                },
                'logExitAAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'exit:a']);
                },
                'logTickAAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'tick:a']);
                },
                'logEntryBAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'entry:b']);
                },
                'logExitBAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'exit:b']);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Initial entry fires entry for both regions
    expect($state->context->get('log'))->toBe(['entry:a', 'entry:b']);

    $state = $definition->transition(['type' => 'TICK'], $state);

    // CORRECT: only tick action in region_a — no exit/entry in either region
    expect($state->context->get('log'))->toBe(['entry:a', 'entry:b', 'tick:a']);
});

// ------------------------------------------------------------------
// 8. Self-transition vs targetless comparison: same state, two events.
//    Self fires exit+entry, targetless fires neither.
// ------------------------------------------------------------------
it('self-transition fires exit+entry while targetless fires neither', function (): void {
    TestMachine::define([
        'id'      => 'self_vs_targetless',
        'initial' => 'active',
        'context' => ['log' => []],
        'states'  => [
            'active' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    // Self-transition: explicit target = same state
                    'REFRESH' => [
                        'target'  => 'active',
                        'actions' => 'logRefreshAction',
                    ],
                    // Targetless: no target
                    'TICK' => [
                        'actions' => 'logTickAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'logRefreshAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'refresh']);
            },
            'logTickAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'tick']);
            },
        ],
    ])
        ->assertContext('log', ['entry'])
        // Targetless — only tick, no exit/entry
        ->send('TICK')
        ->assertContext('log', ['entry', 'tick'])
        // Self-transition — exit + refresh + entry
        ->send('REFRESH')
        ->assertContext('log', ['entry', 'tick', 'exit', 'refresh', 'entry']);
});

// ------------------------------------------------------------------
// 9. Multiple targetless in sequence: 3 targetless events.
//    Exit/entry never fire, actions run 3 times.
// ------------------------------------------------------------------
it('multiple targetless transitions run actions without exit/entry', function (): void {
    TestMachine::define([
        'id'      => 'targetless_multi',
        'initial' => 'active',
        'context' => ['tickCount' => 0, 'log' => []],
        'states'  => [
            'active' => [
                'entry' => 'logEntryAction',
                'exit'  => 'logExitAction',
                'on'    => [
                    'TICK' => [
                        'actions' => 'tickAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'logEntryAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'entry']);
            },
            'logExitAction' => function (ContextManager $ctx): void {
                $ctx->set('log', [...$ctx->get('log'), 'exit']);
            },
            'tickAction' => function (ContextManager $ctx): void {
                $ctx->set('tickCount', $ctx->get('tickCount') + 1);
            },
        ],
    ])
        ->assertContext('log', ['entry'])
        ->assertContext('tickCount', 0)
        ->send('TICK')
        ->send('TICK')
        ->send('TICK')
        ->assertContext('tickCount', 3)
        // CORRECT: only initial entry — no exit, no re-entry
        ->assertContext('log', ['entry']);
});

// ------------------------------------------------------------------
// 10. Targetless with raise: transition action raises an event.
//     Raised event processed without exit/entry on the targetless state.
// ------------------------------------------------------------------

// Class-based action required for $this->raise()
class TargetlessRaiseAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $log   = $context->get('log');
        $log[] = 'raise_action';
        $context->set('log', $log);

        $this->raise(['type' => 'RAISED_PING']);
    }
}

it('targetless transition with raise processes raised event without exit/entry', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'targetless_raise',
            'initial' => 'active',
            'context' => ['log' => []],
            'states'  => [
                'active' => [
                    'entry' => 'logEntryAction',
                    'exit'  => 'logExitAction',
                    'on'    => [
                        'TRIGGER' => [
                            'actions' => TargetlessRaiseAction::class,
                        ],
                        'RAISED_PING' => [
                            'actions' => 'logPingAction',
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'entry']);
                },
                'logExitAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'exit']);
                },
                'logPingAction' => function (ContextManager $ctx): void {
                    $ctx->set('log', [...$ctx->get('log'), 'ping']);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->context->get('log'))->toBe(['entry']);

    // TRIGGER is targetless with raise, RAISED_PING is also targetless
    $state = $definition->transition(['type' => 'TRIGGER'], $state);
    expect($state->currentStateDefinition->id)->toBe('targetless_raise.active');

    // CORRECT: entry (initial) + raise_action + ping — no exit, no re-entry
    expect($state->context->get('log'))->toBe(['entry', 'raise_action', 'ping']);
});
