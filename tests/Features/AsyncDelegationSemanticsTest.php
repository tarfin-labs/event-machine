<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildAMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\EntryCounterMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions\RaiseAndLogAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions\LogSecondEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions\LogRaisedHandledAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation\RaiseRedirectAction;

// ============================================================
// xa1: @always Skips Delegation
// ============================================================

test('xa1: raised event in entry exits delegating state — async child is never dispatched', function (): void {
    Queue::fake();

    // State 'delegating' has:
    //   - entry action that raises REDIRECT
    //   - async child machine delegation (queue)
    //   - on: REDIRECT -> 'redirected'
    //
    // Per SCXML invoker-05 macrostep semantics, internal events from entry
    // must complete BEFORE child machine invocation starts.
    // REDIRECT transitions parent out of 'delegating' — child never dispatched.
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'xa1_always_skips',
            'initial' => 'idle',
            'context' => [
                'executionOrder' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => ['START' => 'delegating'],
                ],
                'delegating' => [
                    'entry'   => RaiseRedirectAction::class,
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'child_completed',
                    'on'      => [
                        'REDIRECT' => 'redirected',
                    ],
                ],
                'redirected' => [
                    'type' => 'final',
                ],
                'child_completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'START']);

    // REDIRECT should win: machine exits 'delegating' before child machine runs.
    expect($state->matches('redirected'))->toBeTrue();

    // No child machine job was dispatched (sync child was never invoked)
    Queue::assertNotPushed(ChildMachineJob::class);
});

// ============================================================
// xa2: Late Child Event Discarded
// ============================================================

test('xa2: events from completed child are silently discarded', function (): void {
    Queue::fake();

    // Use AsyncParentMachine which has machineClass set properly.
    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Parent is at processing (async child dispatched to queue)
    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    $stateDefinition = $machine->definition->idMap['async_parent.processing'];

    // First @done: transitions from processing -> completed
    $doneEvent = ChildMachineDoneEvent::forChild([
        'output'        => ['status' => 'ok'],
        'machine_id'    => 'child-1',
        'machine_class' => SimpleChildMachine::class,
    ]);

    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);
    expect($machine->state->value)->toBe(['async_parent.completed']);

    // Second (late) @done from the same child — should be silently discarded.
    // No exception, state unchanged.
    $lateEvent = ChildMachineDoneEvent::forChild([
        'output'        => ['status' => 'late'],
        'machine_id'    => 'child-1',
        'machine_class' => SimpleChildMachine::class,
    ]);

    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $lateEvent);
    expect($machine->state->value)->toBe(['async_parent.completed']);
});

// ============================================================
// xa3: Sync Child Immediately Final via @always
// ============================================================

test('xa3: sync child that reaches final immediately — parent processes @done in same macrostep', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'xa3_sync_always_final',
            'initial' => 'idle',
            'context' => ['childDone' => false],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'markDoneAction',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'markDoneAction' => function (ContextManager $ctx): void {
                    $ctx->set('childDone', true);
                },
            ],
        ],
    );

    // ImmediateChildMachine starts directly in final state.
    // Parent should process @done in the same macrostep.
    $state = $machine->transition(event: ['type' => 'START']);

    expect($state->matches('completed'))->toBeTrue()
        ->and($state->context->get('childDone'))->toBeTrue();
});

// ============================================================
// xa4: @done Scoped to Region
// ============================================================

test('xa4: @done from child in parallel region is scoped to that region only', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'xa4_done_scoped',
            'initial' => 'verifying',
            'context' => ['regionADone' => false, 'regionBDone' => false],
            'states'  => [
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => ImmediateChildAMachine::class,
                                    '@done'   => [
                                        'target'  => 'done_a',
                                        'actions' => 'markRegionADoneAction',
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting_b',
                            'states'  => [
                                'waiting_b' => [
                                    'on' => ['COMPLETE_B' => [
                                        'target'  => 'done_b',
                                        'actions' => 'markRegionBDoneAction',
                                    ]],
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'all_done',
                ],
                'all_done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'markRegionADoneAction' => function (ContextManager $ctx): void {
                    $ctx->set('regionADone', true);
                },
                'markRegionBDoneAction' => function (ContextManager $ctx): void {
                    $ctx->set('regionBDone', true);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();

    // After initialization: region_a child completes immediately via @done.
    // region_b is still at waiting_b (not final).
    // So the parallel state is NOT done yet — @done only fired in region_a.
    expect($state->context->get('regionADone'))->toBeTrue()
        ->and($state->context->get('regionBDone'))->toBeFalse();

    // Machine should NOT be at all_done because region_b hasn't completed.
    expect(in_array('xa4_done_scoped.all_done', $state->value, true))->toBeFalse();

    // Now complete region_b explicitly.
    $state = $machine->transition(event: ['type' => 'COMPLETE_B'], state: $state);

    // Now both regions are final => parallel @done fires => all_done
    expect($state->value)->toBe(['xa4_done_scoped.all_done'])
        ->and($state->context->get('regionBDone'))->toBeTrue();
});

// ============================================================
// xa6: Entry Before Raise
// ============================================================

test('xa6: raised events are deferred until ALL entry actions complete', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'xa6_entry_before_raise',
            'initial' => 'idle',
            'context' => [
                'trace' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [
                    'entry' => [RaiseAndLogAction::class, LogSecondEntryAction::class],
                    'on'    => [
                        'RAISED_EVENT' => [
                            'target'  => 'handled',
                            'actions' => LogRaisedHandledAction::class,
                        ],
                    ],
                ],
                'handled' => [],
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'GO']);

    // Machine should end in 'handled' (raised event was processed).
    expect($state->matches('handled'))->toBeTrue();

    // Trace proves entry actions ran to completion BEFORE raised event was processed.
    expect($state->context->get('trace'))->toBe([
        'entry1_raise',    // 1. First entry action runs and raises RAISED_EVENT
        'entry2_log',      // 2. Second entry action runs (event still deferred)
        'raised_handled',  // 3. Only now is the raised event processed
    ]);
});

// ============================================================
// restore-no-replay-entry: Restore from DB Does NOT Replay Entry
// ============================================================

test('restore from DB does not replay entry actions', function (): void {
    // 1. Create machine, transition to 'active' (entry action increments counter)
    $machine = EntryCounterMachine::create();
    $machine->send(['type' => 'GO']);

    expect($machine->state->context->get('entryCount'))->toBe(1)
        ->and($machine->state->matches('active'))->toBeTrue();

    // 2. Persist to DB
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // 3. Restore from DB — this should NOT replay entry actions
    $restored = EntryCounterMachine::create(state: $rootEventId);

    expect($restored->state->context->get('entryCount'))->toBe(1)
        ->and($restored->state->matches('active'))->toBeTrue();
});
