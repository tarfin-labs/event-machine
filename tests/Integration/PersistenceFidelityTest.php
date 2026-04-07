<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Support\CompressionManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\PersistenceFidelityPayloadMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\PersistenceFidelityMultiEventMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\PersistenceFidelityAlwaysChainMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\PersistenceFidelityMultiActionMachine;

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
    $rootEventId   = $machine->state->history->first()->root_event_id;
    $stateBefore   = $machine->state->value;
    $contextBefore = $machine->state->context->toArray();
    $historyBefore = $machine->state->history->map(fn (MachineEvent $e): array => [
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
    expect($machine->state->context->get('capturedTriggeringType'))->toBe('SUBMIT');

    // Now persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = PersistenceFidelityAlwaysChainMachine::create(state: $rootEventId);

    // Context should still show SUBMIT as the captured triggering event type
    expect($restored->state->context->get('capturedTriggeringType'))->toBe('SUBMIT');

    // State should match
    expect($restored->state->value)->toBe(['pf_always_chain.done']);
});

// ============================================================
// Test 3: Payload Type Fidelity
// ============================================================

it('preserves complex payload types through persist and restore', function (): void {
    $complexPayload = [
        'int_value'    => 42,
        'string_value' => 'hello world',
        'float_value'  => 3.14159,
        'bool_true'    => true,
        'bool_false'   => false,
        'null_value'   => null,
        'nested_array' => [
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
    expect($machine->state->context->get('receivedPayload'))->toBe($complexPayload);

    // Persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = PersistenceFidelityPayloadMachine::create(state: $rootEventId);

    $restoredPayload = $restored->state->context->get('receivedPayload');

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

    // Persist stores incremental context diffs per event. Each action's mutation
    // is captured in its action.finish event. The last event always has the full
    // context snapshot. Verify that ALL 3 mutations are captured in the event stream
    // and that the last event contains the complete final context.

    $lastEvent = $persistedEvents->last();

    // The last event (being the final event) should have the full context snapshot
    // Context is wrapped under 'data' key by ContextManager
    expect($lastEvent->context)->toHaveKey('data');
    expect($lastEvent->context['data'])->toHaveKeys(['alpha', 'beta', 'gamma', 'counter']);
    expect($lastEvent->context['data']['alpha'])->toBe('value_a');
    expect($lastEvent->context['data']['beta'])->toBe('value_b');
    expect($lastEvent->context['data']['gamma'])->toBe('value_g');
    expect($lastEvent->context['data']['counter'])->toBe(1);

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
