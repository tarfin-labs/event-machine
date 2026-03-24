<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysRtcEdgeCases\Actions\LogEntryCompleteAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysRtcEdgeCases\Actions\LogRaisedEventHandledAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysRtcEdgeCases\Actions\LogAndRaiseOnInitialEntryAction;

// ============================================================
// 1. always-chain-compound-done
//    @always chain in compound child cascades to final child,
//    triggering compound @done on the parent.
// ============================================================

it('@always chain cascading to compound final triggers parent @done', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'always_chain_compound_done',
            'initial' => 'wrapper',
            'context' => [
                'action_log' => [],
            ],
            'states' => [
                'wrapper' => [
                    'initial' => 'step_one',
                    'states'  => [
                        'step_one' => [
                            'entry' => 'logStepOneAction',
                            'on'    => [
                                '@always' => 'step_two',
                            ],
                        ],
                        'step_two' => [
                            'entry' => 'logStepTwoAction',
                            'on'    => [
                                '@always' => 'step_final',
                            ],
                        ],
                        'step_final' => [
                            'type'  => 'final',
                            'entry' => 'logStepFinalAction',
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => [
                    'type'  => 'final',
                    'entry' => 'logCompletedAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logStepOneAction' => function (ContextManager $context): void {
                    $context->set('action_log', [...$context->get('action_log'), 'entry:step_one']);
                },
                'logStepTwoAction' => function (ContextManager $context): void {
                    $context->set('action_log', [...$context->get('action_log'), 'entry:step_two']);
                },
                'logStepFinalAction' => function (ContextManager $context): void {
                    $context->set('action_log', [...$context->get('action_log'), 'entry:step_final']);
                },
                'logCompletedAction' => function (ContextManager $context): void {
                    $context->set('action_log', [...$context->get('action_log'), 'entry:completed']);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // The @always chain should cascade: step_one -> step_two -> step_final (final)
    // Then compound @done fires, transitioning to completed.
    expect($state->value)->toBe(['always_chain_compound_done.completed']);

    // All entry actions should have fired in order
    expect($state->context->get('action_log'))->toBe([
        'entry:step_one',
        'entry:step_two',
        'entry:step_final',
        'entry:completed',
    ]);
});

// ============================================================
// 2. initial-entry-raise-order
//    Event raised during initial state entry action is
//    processed in RTC order (after entry completes).
// ============================================================

it('processes event raised during initial state entry in RTC order', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'initial_entry_raise',
            'initial' => 'start',
            'context' => [
                'execution_order' => [],
            ],
            'states' => [
                'start' => [
                    'entry' => [
                        LogAndRaiseOnInitialEntryAction::class,
                        LogEntryCompleteAction::class,
                    ],
                    'on' => [
                        'RAISED_FROM_ENTRY' => [
                            'target'  => 'handled',
                            'actions' => LogRaisedEventHandledAction::class,
                        ],
                    ],
                ],
                'handled' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Machine should have processed the raised event and ended in 'handled'
    expect($state->value)->toBe(['initial_entry_raise.handled']);

    // RTC order: both entry actions complete BEFORE the raised event is processed
    expect($state->context->get('execution_order'))->toBe([
        'initial_entry',           // 1. First entry action runs and raises event
        'initial_entry_complete',  // 2. Second entry action completes (entry finishes)
        'raised_event_handled',    // 3. Raised event processed AFTER entry completes
    ]);
});

// ============================================================
// 3. parallel-escape-clears-all
//    Sending an escape event from a parallel state clears ALL
//    region state paths from the value array.
// ============================================================

it('clears all region state paths when escaping a parallel state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_escape_clear',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type' => 'parallel',
                    'on'   => [
                        'ESCAPE' => 'escaped',
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'working_a',
                            'states'  => [
                                'working_a' => [
                                    'on' => ['ADVANCE_A' => 'progressed_a'],
                                ],
                                'progressed_a' => [
                                    'on' => ['DONE_A' => 'finished_a'],
                                ],
                                'finished_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working_b',
                            'states'  => [
                                'working_b' => [
                                    'on' => ['DONE_B' => 'finished_b'],
                                ],
                                'finished_b' => ['type' => 'final'],
                            ],
                        ],
                        'region_c' => [
                            'initial' => 'working_c',
                            'states'  => [
                                'working_c' => [
                                    'on' => ['DONE_C' => 'finished_c'],
                                ],
                                'finished_c' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'escaped' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // All three regions should be active
    expect($state->value)->toBe([
        'parallel_escape_clear.processing.region_a.working_a',
        'parallel_escape_clear.processing.region_b.working_b',
        'parallel_escape_clear.processing.region_c.working_c',
    ]);

    // Advance region_a and complete region_b to have mixed states
    $state = $definition->transition(['type' => 'ADVANCE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    expect($state->value)->toBe([
        'parallel_escape_clear.processing.region_a.progressed_a',
        'parallel_escape_clear.processing.region_b.finished_b',
        'parallel_escape_clear.processing.region_c.working_c',
    ]);

    // Escape — ALL region paths must be cleared
    $state = $definition->transition(['type' => 'ESCAPE'], $state);

    expect($state->value)->toBe(['parallel_escape_clear.escaped']);

    // Verify no stale region paths remain
    foreach ($state->value as $path) {
        expect($path)->not->toContain('region_a');
        expect($path)->not->toContain('region_b');
        expect($path)->not->toContain('region_c');
    }
});

// ============================================================
// 4. self-transition-polling-cycle
//    Condition-based exit from a polling cycle: a state with
//    a self-transition increments a counter, and an @always
//    guard checks the counter to exit when threshold is reached.
// ============================================================

it('exits polling cycle via @always guard when counter reaches threshold', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'polling_cycle',
            'initial' => 'polling',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'polling' => [
                    'on' => [
                        'TICK' => [
                            'target'  => 'polling',
                            'actions' => 'incrementCounterAction',
                        ],
                        '@always' => [
                            'target' => 'completed',
                            'guards' => 'thresholdReachedGuard',
                        ],
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementCounterAction' => function (ContextManager $context): void {
                    $context->set('counter', $context->get('counter') + 1);
                },
            ],
            'guards' => [
                'thresholdReachedGuard' => function (ContextManager $context): bool {
                    return $context->get('counter') >= 3;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Initially counter is 0, guard fails, stays in polling
    expect($state->value)->toBe(['polling_cycle.polling']);
    expect($state->context->get('counter'))->toBe(0);

    // First TICK: counter becomes 1, @always guard still false
    $state = $definition->transition(['type' => 'TICK'], $state);
    expect($state->value)->toBe(['polling_cycle.polling']);
    expect($state->context->get('counter'))->toBe(1);

    // Second TICK: counter becomes 2, @always guard still false
    $state = $definition->transition(['type' => 'TICK'], $state);
    expect($state->value)->toBe(['polling_cycle.polling']);
    expect($state->context->get('counter'))->toBe(2);

    // Third TICK: counter becomes 3, @always guard fires, exits to completed
    $state = $definition->transition(['type' => 'TICK'], $state);
    expect($state->value)->toBe(['polling_cycle.completed']);
    expect($state->context->get('counter'))->toBe(3);
});
