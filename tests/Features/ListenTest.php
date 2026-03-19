<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// region Sync Listeners — Entry/Exit

it('fires entry listener on state entry', function (): void {
    TestMachine::define([
        'id'      => 'listen_entry',
        'initial' => 'idle',
        'context' => ['listened' => false],
        'listen'  => [
            'entry' => 'entryListenerAction',
        ],
        'states' => [
            'idle'   => ['on' => ['GO' => 'active']],
            'active' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'entryListenerAction' => function (ContextManager $context): void {
                $context->set('listened', true);
            },
        ],
    ])
        ->assertContext('listened', true);
});

it('fires exit listener before state exit', function (): void {
    TestMachine::define([
        'id'      => 'listen_exit',
        'initial' => 'idle',
        'context' => ['exit_listened' => false],
        'listen'  => [
            'exit' => 'exitListenerAction',
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'exitListenerAction' => function (ContextManager $context): void {
                $context->set('exit_listened', true);
            },
        ],
    ])
        ->assertContext('exit_listened', false)
        ->send('GO')
        ->assertContext('exit_listened', true);
});

it('runs multiple listeners in order', function (): void {
    TestMachine::define([
        'id'      => 'listen_multi',
        'initial' => 'idle',
        'context' => ['log' => []],
        'listen'  => [
            'entry' => ['firstAction', 'secondAction'],
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'firstAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'first']);
            },
            'secondAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'second']);
            },
        ],
    ])
        ->assertContext('log', ['first', 'second']);
});

it('skips listeners on transient states', function (): void {
    TestMachine::define([
        'id'      => 'listen_transient',
        'initial' => 'routing',
        'context' => ['entry_count' => 0],
        'listen'  => [
            'entry' => 'countEntryAction',
        ],
        'states' => [
            'routing' => [
                'on' => ['@always' => 'active'],
            ],
            'active' => ['on' => ['GO' => 'done']],
            'done'   => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'countEntryAction' => function (ContextManager $context): void {
                $context->set('entry_count', $context->get('entry_count') + 1);
            },
        ],
    ])
    // routing is transient — listener skipped. active gets listener = 1
        ->assertContext('entry_count', 1);
});

it('listener sees post-entry context', function (): void {
    TestMachine::define([
        'id'      => 'listen_post_entry',
        'initial' => 'idle',
        'context' => ['value' => 'initial', 'seen_by_listener' => ''],
        'listen'  => [
            'entry' => 'listenerAction',
        ],
        'states' => [
            'idle' => [
                'entry' => 'setValueAction',
                'on'    => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'setValueAction' => function (ContextManager $context): void {
                $context->set('value', 'modified');
            },
            'listenerAction' => function (ContextManager $context): void {
                $context->set('seen_by_listener', $context->get('value'));
            },
        ],
    ])
        ->assertContext('seen_by_listener', 'modified');
});

it('runs state entry actions before listener', function (): void {
    TestMachine::define([
        'id'      => 'listen_order',
        'initial' => 'idle',
        'context' => ['log' => []],
        'listen'  => [
            'entry' => 'listenerAction',
        ],
        'states' => [
            'idle' => [
                'entry' => 'stateEntryAction',
                'on'    => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'stateEntryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'state_entry']);
            },
            'listenerAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'listener']);
            },
        ],
    ])
        ->assertContext('log', ['state_entry', 'listener']);
});

it('fires listener on initial state', function (): void {
    TestMachine::define([
        'id'      => 'listen_init',
        'initial' => 'idle',
        'context' => ['init_listened' => false],
        'listen'  => [
            'entry' => 'listenerAction',
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'listenerAction' => function (ContextManager $context): void {
                $context->set('init_listened', true);
            },
        ],
    ])
        ->assertContext('init_listened', true);
});

it('fires entry listener on final state entry', function (): void {
    TestMachine::define([
        'id'      => 'listen_final',
        'initial' => 'idle',
        'context' => ['final_entered' => false],
        'listen'  => [
            'entry' => 'listenerAction',
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'listenerAction' => function (ContextManager $context): void {
                $context->set('final_entered', true);
            },
        ],
    ])
        ->send('GO')
        ->assertContext('final_entered', true);
});

it('does not fire exit listener on final states', function (): void {
    TestMachine::define([
        'id'      => 'listen_no_final_exit',
        'initial' => 'idle',
        'context' => ['exit_count' => 0],
        'listen'  => [
            'exit' => 'exitListenerAction',
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'exitListenerAction' => function (ContextManager $context): void {
                $context->set('exit_count', $context->get('exit_count') + 1);
            },
        ],
    ])
        ->send('GO')
    // exit fires leaving idle (1), but NOT on final state done
        ->assertContext('exit_count', 1);
});

it('does not fire listeners on guard-blocked transitions', function (): void {
    TestMachine::define([
        'id'      => 'listen_guard_block',
        'initial' => 'idle',
        'context' => ['entry_count' => 0],
        'listen'  => [
            'entry' => 'countAction',
        ],
        'states' => [
            'idle' => [
                'on' => [
                    'GO' => [
                        'target' => 'active',
                        'guards' => 'alwaysFailGuard',
                    ],
                ],
            ],
            'active' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'countAction' => function (ContextManager $context): void {
                $context->set('entry_count', $context->get('entry_count') + 1);
            },
        ],
        'guards' => [
            'alwaysFailGuard' => function (): bool {
                return false;
            },
        ],
    ])
    // initial state fires listener (1), guard blocks GO, no more listeners
        ->assertContext('entry_count', 1)
        ->send('GO')
        ->assertContext('entry_count', 1);
});

it('works without any listeners defined', function (): void {
    TestMachine::define([
        'id'      => 'listen_none',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ])
        ->assertState('idle')
        ->send('GO')
        ->assertState('done');
});

it('fires entry and exit together in correct order', function (): void {
    TestMachine::define([
        'id'      => 'listen_both',
        'initial' => 'a',
        'context' => ['log' => []],
        'listen'  => [
            'entry' => 'entryAction',
            'exit'  => 'exitAction',
        ],
        'states' => [
            'a' => ['on' => ['GO' => 'b']],
            'b' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'entryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'entry']);
            },
            'exitAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'exit']);
            },
        ],
    ])
    // Init: entry on a
        ->assertContext('log', ['entry'])
        ->send('GO')
    // Transition a→b: exit a, entry b
        ->assertContext('log', ['entry', 'exit', 'entry']);
});

it('fires listener on each non-transient state in multiple transitions', function (): void {
    TestMachine::define([
        'id'      => 'listen_multi_trans',
        'initial' => 'a',
        'context' => ['entry_count' => 0],
        'listen'  => [
            'entry' => 'countAction',
        ],
        'states' => [
            'a' => ['on' => ['NEXT' => 'b']],
            'b' => ['on' => ['NEXT' => 'c']],
            'c' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'countAction' => function (ContextManager $context): void {
                $context->set('entry_count', $context->get('entry_count') + 1);
            },
        ],
    ])
        ->assertContext('entry_count', 1) // a
        ->send('NEXT')
        ->assertContext('entry_count', 2) // b
        ->send('NEXT')
        ->assertContext('entry_count', 3); // c
});

// endregion

// region Sync Listeners — Transition

it('fires transition listener after successful transition', function (): void {
    TestMachine::define([
        'id'      => 'listen_transition',
        'initial' => 'a',
        'context' => ['transition_count' => 0],
        'listen'  => [
            'transition' => 'transitionAction',
        ],
        'states' => [
            'a' => ['on' => ['GO' => 'b']],
            'b' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'transitionAction' => function (ContextManager $context): void {
                $context->set('transition_count', $context->get('transition_count') + 1);
            },
        ],
    ])
    // Init: no transition listener (no event triggered)
        ->assertContext('transition_count', 0)
        ->send('GO')
    // a→b: transition listener fires
        ->assertContext('transition_count', 1);
});

it('fires transition listener on targetless transitions but not entry/exit', function (): void {
    TestMachine::define([
        'id'      => 'listen_targetless',
        'initial' => 'idle',
        'context' => ['entry_count' => 0, 'exit_count' => 0, 'transition_count' => 0],
        'listen'  => [
            'entry'      => 'entryAction',
            'exit'       => 'exitAction',
            'transition' => 'transitionAction',
        ],
        'states' => [
            'idle' => [
                'on' => [
                    'UPDATE' => [
                        'actions' => 'noopAction',
                    ],
                    'GO' => 'done',
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'entryAction' => function (ContextManager $context): void {
                $context->set('entry_count', $context->get('entry_count') + 1);
            },
            'exitAction' => function (ContextManager $context): void {
                $context->set('exit_count', $context->get('exit_count') + 1);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('transition_count', $context->get('transition_count') + 1);
            },
            'noopAction' => function (): void {},
        ],
    ])
        ->assertContext('entry_count', 1)      // init
        ->assertContext('transition_count', 0)  // no transition on init
        ->send('UPDATE')
        ->assertContext('entry_count', 1)      // no entry (targetless)
        ->assertContext('exit_count', 0)       // no exit (targetless)
        ->assertContext('transition_count', 1); // transition fires!
});

it('fires all three listeners on self-transition', function (): void {
    TestMachine::define([
        'id'      => 'listen_self',
        'initial' => 'a',
        'context' => ['log' => []],
        'listen'  => [
            'entry'      => 'entryAction',
            'exit'       => 'exitAction',
            'transition' => 'transitionAction',
        ],
        'states' => [
            'a'    => ['on' => ['REFRESH' => 'a', 'GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'entryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'entry']);
            },
            'exitAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'exit']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'transition']);
            },
        ],
    ])
        ->assertContext('log', ['entry'])   // init: entry only
        ->send('REFRESH')
    // self-transition: exit, entry, transition
        ->assertContext('log', ['entry', 'exit', 'entry', 'transition']);
});

it('does not fire transition listener on transient states', function (): void {
    TestMachine::define([
        'id'      => 'listen_trans_transient',
        'initial' => 'a',
        'context' => ['transition_count' => 0],
        'listen'  => [
            'transition' => 'transitionAction',
        ],
        'states' => [
            'a'       => ['on' => ['GO' => 'routing']],
            'routing' => ['on' => ['@always' => 'b']],
            'b'       => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'transitionAction' => function (ContextManager $context): void {
                $context->set('transition_count', $context->get('transition_count') + 1);
            },
        ],
    ])
        ->send('GO')
    // routing is transient — transition listener fires on b (final resting state)
        ->assertContext('transition_count', 1);
});

it('fires entry before transition listener', function (): void {
    TestMachine::define([
        'id'      => 'listen_entry_before_trans',
        'initial' => 'a',
        'context' => ['log' => []],
        'listen'  => [
            'entry'      => 'entryAction',
            'transition' => 'transitionAction',
        ],
        'states' => [
            'a' => ['on' => ['GO' => 'b']],
            'b' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'entryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'entry']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'transition']);
            },
        ],
    ])
        ->send('GO')
    // entry fires before transition
        ->assertContext('log', ['entry', 'entry', 'transition']);
});

it('does not fire transition listener on init', function (): void {
    TestMachine::define([
        'id'      => 'listen_no_trans_init',
        'initial' => 'idle',
        'context' => ['transition_count' => 0],
        'listen'  => [
            'transition' => 'transitionAction',
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'transitionAction' => function (ContextManager $context): void {
                $context->set('transition_count', $context->get('transition_count') + 1);
            },
        ],
    ])
        ->assertContext('transition_count', 0);
});

// endregion

// region Edge Cases — Parallel States

it('fires entry listeners on parallel state and each region', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'listen_parallel',
            'initial' => 'processing',
            'context' => ['entry_count' => 0],
            'listen'  => [
                'entry' => 'countAction',
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'a_idle',
                            'states'  => [
                                'a_idle' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'b_idle',
                            'states'  => [
                                'b_idle' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'countAction' => function (ContextManager $context): void {
                    $context->set('entry_count', $context->get('entry_count') + 1);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // parallel state entry + 2 regions = 3
    expect($state->context->get('entry_count'))->toBe(3);
});

// endregion

// region Edge Cases — Queued Listeners

it('records dispatched internal event for queued entry listener', function (): void {
    $machine = TestMachine::define([
        'id'      => 'listen_queued',
        'initial' => 'idle',
        'context' => [],
        'listen'  => [
            'entry' => [
                'queuedAction' => ['queue' => true],
            ],
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'queuedAction' => function (): void {},
        ],
    ]);

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    expect($types)->toContain('listen_queued.listen.queue.queuedAction.dispatched');
});

it('records dispatched internal event for queued transition listener', function (): void {
    $machine = TestMachine::define([
        'id'      => 'listen_queued_trans',
        'initial' => 'idle',
        'context' => [],
        'listen'  => [
            'transition' => [
                'queuedAction' => ['queue' => true],
            ],
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'queuedAction' => function (): void {},
        ],
    ])->send('GO');

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    expect($types)->toContain('listen_queued_trans.listen.queue.queuedAction.dispatched');
});

it('runs sync listener inline and records queued dispatch event', function (): void {
    $machine = TestMachine::define([
        'id'      => 'listen_mixed',
        'initial' => 'idle',
        'context' => ['sync_ran' => false],
        'listen'  => [
            'entry' => [
                'syncAction',
                'queuedAction' => ['queue' => true],
            ],
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'syncAction' => function (ContextManager $context): void {
                $context->set('sync_ran', true);
            },
            'queuedAction' => function (): void {},
        ],
    ]);

    $machine->assertContext('sync_ran', true);

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    expect($types)->toContain('listen_mixed.listen.queue.queuedAction.dispatched');
});

it('does not record queued dispatch for transient states', function (): void {
    $machine = TestMachine::define([
        'id'      => 'listen_queued_transient',
        'initial' => 'routing',
        'context' => [],
        'listen'  => [
            'entry' => [
                'queuedAction' => ['queue' => true],
            ],
        ],
        'states' => [
            'routing' => ['on' => ['@always' => 'active']],
            'active'  => [],
        ],
    ], behavior: [
        'actions' => [
            'queuedAction' => function (): void {},
        ],
    ]);

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    // Only 1 dispatched (active), routing is transient — skipped
    $dispatchedCount = count(array_filter($types, fn ($t) => str_contains($t, 'dispatched')));

    expect($dispatchedCount)->toBe(1);
});

it('queued and sync dispatched events appear in correct listener block', function (): void {
    $machine = TestMachine::define([
        'id'      => 'listen_block_order',
        'initial' => 'idle',
        'context' => [],
        'listen'  => [
            'entry' => [
                'syncAction',
                'queuedAction' => ['queue' => true],
            ],
        ],
        'states' => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'syncAction'   => function (): void {},
            'queuedAction' => function (): void {},
        ],
    ]);

    $types = $machine->machine()->state->history->pluck('type')->toArray();

    // dispatched should be between listen.entry.start and listen.entry.finish
    $startIdx      = array_search('listen_block_order.listen.entry.start', $types);
    $dispatchedIdx = array_search('listen_block_order.listen.queue.queuedAction.dispatched', $types);
    $finishIdx     = array_search('listen_block_order.listen.entry.finish', $types);

    expect($startIdx)->toBeLessThan($dispatchedIdx)
        ->and($dispatchedIdx)->toBeLessThan($finishIdx);
});

// endregion
