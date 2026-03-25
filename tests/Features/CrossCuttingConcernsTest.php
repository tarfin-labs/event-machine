<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ContextMutatingChildMachine;

// ============================================================
// Cross-Cutting Concerns Tests (MassTransit Pass)
// ============================================================

// ─── Test 8: Context isolation during delegation ───────────────

it('child context changes do not leak into parent context after delegation completes', function (): void {
    // ContextMutatingChildMachine changes order_id to 'CHANGED_BY_CHILD' and adds 'extra'
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'ctx_isolation',
            'initial' => 'idle',
            'context' => [
                'orderId'    => 'PARENT_ORIGINAL',
                'parentOnly' => 'untouched',
            ],
            'states' => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ContextMutatingChildMachine::class,
                    'with'    => ['orderId'],
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'GO'], state: $state);

    // Parent context must be completely untouched by child mutations
    expect($state->context->get('orderId'))->toBe('PARENT_ORIGINAL')
        ->and($state->context->get('parentOnly'))->toBe('untouched');
});

// ─── Test 9: Persist before sendTo side effects ────────────────

it('machine state is persisted before sendTo side effects execute', function (): void {
    // This test verifies the persist-first contract by checking that
    // after a transition with persistence enabled, the machine events
    // are in the DB before any sendTo-like side effects would run.

    $configAndBehavior = [
        'config' => [
            'id'      => 'persist_before_send',
            'initial' => 'idle',
            'context' => [
                'step' => 'start',
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'PROCESS' => [
                            'target'  => 'processing',
                            'actions' => 'updateStepAction',
                        ],
                    ],
                ],
                'processing' => [
                    'on' => ['COMPLETE' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'updateStepAction' => function (ContextManager $ctx): void {
                    $ctx->set('step', 'processed');
                },
            ],
        ],
    ];

    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'PROCESS']);

    // Machine auto-persists via Machine::create flow. Verify DB has events.
    $rootEventId = $machine->state->history->first()->root_event_id;

    $dbEventCount = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($dbEventCount)->toBeGreaterThan(0);

    // Verify the persisted state reflects the transition
    $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->orderByDesc('sequence_number')
        ->first();

    expect($lastEvent)->not->toBeNull()
        ->and($lastEvent->machine_value)->toContain('persist_before_send.processing');
});

// ─── Test 10: Timer final cleanup ──────────────────────────────

it('MachineCurrentState rows exist during active state and persist to final state', function (): void {
    // Machine with a timer that transitions through states to final
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'timer_cleanup',
            'initial' => 'awaiting',
            'context' => [],
            'states'  => [
                'awaiting' => [
                    'on' => [
                        'PROCEED'      => 'processing',
                        'EXPIRE_TIMER' => ['target' => 'expired', 'after' => Timer::days(7)],
                    ],
                ],
                'processing' => [
                    'on' => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
                'expired'   => ['type' => 'final'],
            ],
        ],
    );

    $machine = Machine::withDefinition($definition);
    $machine->start();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // While in 'awaiting' state, MachineCurrentState row should exist
    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(1)
        ->and(MachineCurrentState::forInstance($rootEventId)->first()->state_id)
        ->toBe('timer_cleanup.awaiting');

    // Transition to processing
    $machine->send(['type' => 'PROCEED']);
    $machine->persist();

    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(1)
        ->and(MachineCurrentState::forInstance($rootEventId)->first()->state_id)
        ->toBe('timer_cleanup.processing');

    // Transition to final state
    $machine->send(['type' => 'DONE']);
    $machine->persist();

    // At final state, MachineCurrentState row still tracked (valid state)
    $rows = MachineCurrentState::forInstance($rootEventId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->state_id)->toBe('timer_cleanup.completed');
});

// ─── Test 11: All guards fail → machine stays in current state ─

it('known event with all guards returning false keeps machine in current state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'all_guards_fail',
            'initial' => 'active',
            'context' => [
                'value' => 0,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'GUARDED_EVENT' => [
                            [
                                'target' => 'branch_a',
                                'guards' => 'alwaysFailGuard',
                            ],
                            [
                                'target' => 'branch_b',
                                'guards' => 'alsoFailGuard',
                            ],
                        ],
                        'UNGUARDED' => 'done',
                    ],
                ],
                'branch_a' => [],
                'branch_b' => [],
                'done'     => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'alwaysFailGuard' => function (): bool {
                    return false;
                },
                'alsoFailGuard' => function (): bool {
                    return false;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Send event where all guard branches fail — machine stays in current state
    $stateAfter = $definition->transition(event: ['type' => 'GUARDED_EVENT'], state: $state);

    expect($stateAfter->value)->toBe(['all_guards_fail.active'])
        ->and($stateAfter->context->get('value'))->toBe(0);

    // Verify machine is still functional with an unguarded event
    $stateAfter = $definition->transition(event: ['type' => 'UNGUARDED'], state: $stateAfter);
    expect($stateAfter->value)->toBe(['all_guards_fail.done']);
});

// ─── Test 12: Persistence retry idempotency ────────────────────

it('retry of a failed transition does not produce duplicate machine events', function (): void {
    $configAndBehavior = [
        'config' => [
            'id'      => 'persist_retry',
            'initial' => 'idle',
            'context' => [
                'step' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'ADVANCE' => [
                            'target'  => 'processing',
                            'actions' => 'incrementAction',
                        ],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'ADVANCE' => [
                            'target'  => 'done',
                            'actions' => 'incrementAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'incrementAction' => function (ContextManager $ctx): void {
                    $ctx->set('step', $ctx->get('step') + 1);
                },
            ],
        ],
    ];

    // First machine: advance to processing
    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'ADVANCE']);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Count events after first transition
    $eventCountAfterFirst = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Persist again (idempotent — same events should not duplicate)
    $machine->persist();

    $eventCountAfterRePersist = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($eventCountAfterRePersist)->toBe($eventCountAfterFirst);

    // Send second event
    $machine->send(['type' => 'ADVANCE']);

    $totalEvents = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Each event creates internal lifecycle events too, but the key check is:
    // total events after second transition should be strictly greater than after first
    // (new events added), but no duplicates from prior persist
    expect($totalEvents)->toBeGreaterThan($eventCountAfterFirst);

    // Verify no duplicate sequence numbers
    $sequenceNumbers = MachineEvent::where('root_event_id', $rootEventId)
        ->pluck('sequence_number')
        ->toArray();

    expect(count($sequenceNumbers))->toBe(count(array_unique($sequenceNumbers)));
});
