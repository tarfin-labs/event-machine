<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Support\CompressionManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ============================================================
// Persistence Fidelity Tests
// ============================================================
// Verify that state, context, event history, payload types,
// triggering events, and context diffs survive persist/restore
// and archive/restore cycles with full fidelity.

// region Stub Machines

class PersistenceFidelityAlwaysChainMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_always_chain',
                'initial' => 'idle',
                'context' => [
                    'captured_triggering_type' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'processing',
                                'actions' => 'captureAction',
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => [
                            '@always' => 'done',
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureAction' => function (ContextManager $context, \Tarfinlabs\EventMachine\Behavior\EventBehavior $event): void {
                        $context->set('captured_triggering_type', $event->type);
                    },
                ],
            ],
        );
    }
}

class PersistenceFidelityPayloadMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_payload',
                'initial' => 'idle',
                'context' => [
                    'received_payload' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                'target'  => 'done',
                                'actions' => 'capturePayloadAction',
                            ],
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'capturePayloadAction' => function (ContextManager $context, \Tarfinlabs\EventMachine\Behavior\EventBehavior $event): void {
                        $context->set('received_payload', $event->payload);
                    },
                ],
            ],
        );
    }
}

class PersistenceFidelityMultiEventMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_multi_event',
                'initial' => 'step1',
                'context' => [
                    'counter' => 0,
                ],
                'states' => [
                    'step1' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step2',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step2' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step3',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step3' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step4',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step4' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step5',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step5' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step6',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step6' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => function (ContextManager $context): void {
                        $context->set('counter', $context->get('counter') + 1);
                    },
                ],
            ],
        );
    }
}

class PersistenceFidelityMultiActionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_multi_action',
                'initial' => 'idle',
                'context' => [
                    'alpha'   => null,
                    'beta'    => null,
                    'gamma'   => null,
                    'counter' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'TRIGGER' => [
                                'target'  => 'done',
                                'actions' => [
                                    'setAlphaAction',
                                    'setBetaAction',
                                    'setGammaAndIncrementAction',
                                ],
                            ],
                        ],
                    ],
                    'done' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'setAlphaAction' => function (ContextManager $context): void {
                        $context->set('alpha', 'value_a');
                    },
                    'setBetaAction' => function (ContextManager $context): void {
                        $context->set('beta', 'value_b');
                    },
                    'setGammaAndIncrementAction' => function (ContextManager $context): void {
                        $context->set('gamma', 'value_g');
                        $context->set('counter', 1);
                    },
                ],
            ],
        );
    }
}

// endregion

// ============================================================
// Test 1: Archive Full Equivalence
// ============================================================

it('preserves state, context, and event history identically after archive and restore', function (): void {
    CompressionManager::clearCache();
    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.restore_cooldown_hours' => 24,
    ]);

    // Create machine and advance through some states
    $machine = PersistenceFidelityMultiEventMachine::create();
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);

    // Capture state before archive
    $rootEventId     = $machine->state->history->first()->root_event_id;
    $stateBefore     = $machine->state->value;
    $contextBefore   = $machine->state->context->toArray();
    $historyBefore   = $machine->state->history->map(fn (MachineEvent $e): array => [
        'type'            => $e->type,
        'sequence_number' => $e->sequence_number,
        'payload'         => $e->payload,
        'machine_value'   => $e->machine_value,
    ])->toArray();

    // Archive the machine
    $archiveService = new ArchiveService();
    $archive        = $archiveService->archiveMachine($rootEventId);

    expect($archive)->not->toBeNull();
    expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

    // Restore and compare
    $restoredMachine = PersistenceFidelityMultiEventMachine::create(state: $rootEventId);

    expect($restoredMachine->state->value)->toBe($stateBefore);
    expect($restoredMachine->state->context->toArray())->toBe($contextBefore);

    $historyAfter = $restoredMachine->state->history->map(fn (MachineEvent $e): array => [
        'type'            => $e->type,
        'sequence_number' => $e->sequence_number,
        'payload'         => $e->payload,
        'machine_value'   => $e->machine_value,
    ])->toArray();

    expect($historyAfter)->toBe($historyBefore);
});

// ============================================================
// Test 2: Triggering Event Persist Through @always Chain
// ============================================================

it('preserves triggering event type through @always chain after persist and restore', function (): void {
    $machine = PersistenceFidelityAlwaysChainMachine::create();

    $machine->send([
        'type'    => 'SUBMIT',
        'payload' => ['data' => 'test'],
    ]);

    // Machine should end in done (after @always chain: routing → processing → done)
    expect($machine->state->value)->toBe(['pf_always_chain.done']);

    // The action on the @always transition should have captured the original SUBMIT event
    expect($machine->state->context->get('captured_triggering_type'))->toBe('SUBMIT');

    // Now persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = PersistenceFidelityAlwaysChainMachine::create(state: $rootEventId);

    // Context should still show SUBMIT as the captured triggering event type
    expect($restored->state->context->get('captured_triggering_type'))->toBe('SUBMIT');

    // State should match
    expect($restored->state->value)->toBe(['pf_always_chain.done']);
});

// ============================================================
// Test 3: Payload Type Fidelity
// ============================================================

it('preserves complex payload types through persist and restore', function (): void {
    $complexPayload = [
        'int_value'      => 42,
        'string_value'   => 'hello world',
        'float_value'    => 3.14159,
        'bool_true'      => true,
        'bool_false'     => false,
        'null_value'     => null,
        'nested_array'   => [
            'level1' => [
                'level2' => [
                    'deep_int'    => 999,
                    'deep_string' => 'nested',
                ],
            ],
        ],
        'numeric_array'  => [1, 2, 3, 4, 5],
        'empty_array'    => [],
        'zero'           => 0,
        'empty_string'   => '',
        'negative_int'   => -100,
        'negative_float' => -2.5,
    ];

    $machine = PersistenceFidelityPayloadMachine::create();
    $machine->send([
        'type'    => 'SUBMIT',
        'payload' => $complexPayload,
    ]);

    // Verify payload was captured in context
    expect($machine->state->context->get('received_payload'))->toBe($complexPayload);

    // Persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = PersistenceFidelityPayloadMachine::create(state: $rootEventId);

    $restoredPayload = $restored->state->context->get('received_payload');

    // Strict type checks on each value
    expect($restoredPayload['int_value'])->toBe(42)->toBeInt();
    expect($restoredPayload['string_value'])->toBe('hello world')->toBeString();
    expect($restoredPayload['float_value'])->toBe(3.14159)->toBeFloat();
    expect($restoredPayload['bool_true'])->toBe(true)->toBeBool();
    expect($restoredPayload['bool_false'])->toBe(false)->toBeBool();
    expect($restoredPayload['null_value'])->toBeNull();
    expect($restoredPayload['nested_array'])->toBe($complexPayload['nested_array'])->toBeArray();
    expect($restoredPayload['numeric_array'])->toBe([1, 2, 3, 4, 5])->toBeArray();
    expect($restoredPayload['empty_array'])->toBe([])->toBeArray();
    expect($restoredPayload['zero'])->toBe(0)->toBeInt();
    expect($restoredPayload['empty_string'])->toBe('')->toBeString();
    expect($restoredPayload['negative_int'])->toBe(-100)->toBeInt();
    expect($restoredPayload['negative_float'])->toBe(-2.5)->toBeFloat();

    // Full round-trip equality
    expect($restoredPayload)->toBe($complexPayload);
});

// ============================================================
// Test 4: History Ordering After Persist and Restore
// ============================================================

it('preserves event history in correct chronological order after persist and restore', function (): void {
    $machine = PersistenceFidelityMultiEventMachine::create();

    // Send 5 events
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);
    $machine->send(['type' => 'GO']);

    // Capture original sequence numbers and event ordering
    $originalSequenceNumbers = $machine->state->history
        ->pluck('sequence_number')
        ->toArray();

    $originalTypes = $machine->state->history
        ->pluck('type')
        ->toArray();

    // Verify sequence numbers are strictly ascending
    for ($i = 1; $i < count($originalSequenceNumbers); $i++) {
        expect($originalSequenceNumbers[$i])->toBeGreaterThan($originalSequenceNumbers[$i - 1]);
    }

    // Persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = PersistenceFidelityMultiEventMachine::create(state: $rootEventId);

    $restoredSequenceNumbers = $restored->state->history
        ->pluck('sequence_number')
        ->toArray();

    $restoredTypes = $restored->state->history
        ->pluck('type')
        ->toArray();

    // Sequence numbers should be identical and in the same order
    expect($restoredSequenceNumbers)->toBe($originalSequenceNumbers);

    // Event types should be in the same order
    expect($restoredTypes)->toBe($originalTypes);

    // Restored sequence numbers must still be strictly ascending
    for ($i = 1; $i < count($restoredSequenceNumbers); $i++) {
        expect($restoredSequenceNumbers[$i])->toBeGreaterThan($restoredSequenceNumbers[$i - 1]);
    }

    // Final state should match
    expect($restored->state->value)->toBe(['pf_multi_event.step6']);
    expect($restored->state->context->get('counter'))->toBe(5);
});

// ============================================================
// Test 5: Multi-Action Atomic Context Diff
// ============================================================

it('persists a single context diff containing all mutations from multiple actions in one transition', function (): void {
    $machine = PersistenceFidelityMultiActionMachine::create();

    $machine->send(['type' => 'TRIGGER']);

    // Machine should be in 'done' with all 3 actions having modified context
    expect($machine->state->value)->toBe(['pf_multi_action.done']);
    expect($machine->state->context->get('alpha'))->toBe('value_a');
    expect($machine->state->context->get('beta'))->toBe('value_b');
    expect($machine->state->context->get('gamma'))->toBe('value_g');
    expect($machine->state->context->get('counter'))->toBe(1);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Read persisted events from DB
    $persistedEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->orderBy('sequence_number')
        ->get();

    // Find the external TRIGGER event in persisted history
    $triggerEvent = $persistedEvents->first(fn (MachineEvent $e): bool => $e->type === 'TRIGGER');

    expect($triggerEvent)->not->toBeNull();

    // The persist() method stores incremental context diffs on intermediate events
    // and the FULL context snapshot on the last event. Verify this by checking
    // that context mutations from all 3 actions are recoverable from the persisted events.

    // Collect all context keys that appear across ALL persisted events' context diffs
    $allPersistedContextKeys = [];
    foreach ($persistedEvents as $e) {
        foreach (array_keys($e->context) as $key) {
            $allPersistedContextKeys[$key] = true;
        }
    }

    // DEBUG: dump all persisted events context
    foreach ($persistedEvents as $idx => $e) {
        dump("Event #{$idx}: type={$e->type} seq={$e->sequence_number} context=" . json_encode($e->context));
    }

    // All 4 context keys modified by the 3 actions must appear in the persisted diffs
    expect($allPersistedContextKeys)->toHaveKeys(['alpha', 'beta', 'gamma', 'counter']);

    // Restore and verify all mutations survived as a coherent unit
    $restored = PersistenceFidelityMultiActionMachine::create(state: $rootEventId);

    expect($restored->state->context->get('alpha'))->toBe('value_a');
    expect($restored->state->context->get('beta'))->toBe('value_b');
    expect($restored->state->context->get('gamma'))->toBe('value_g');
    expect($restored->state->context->get('counter'))->toBe(1);

    // Verify there is NOT a separate persisted event for each action
    // (actions don't create their own external events — they all run within one transition)
    $externalTriggerEvents = $persistedEvents->filter(
        fn (MachineEvent $e): bool => $e->type === 'TRIGGER'
    );
    expect($externalTriggerEvents)->toHaveCount(1);
});
