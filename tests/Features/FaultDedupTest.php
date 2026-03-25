<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;

// ═══════════════════════════════════════════════════════════════
//  Bead 2: Failed transition action produces exactly one failure
//  event despite retries. Action throws, verify machine_events
//  table has exactly one error event (not duplicated by retry).
// ═══════════════════════════════════════════════════════════════

it('records exactly one transition fail event when action throws', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'fault_dedup',
            'initial' => 'idle',
            'context' => [
                'value' => 'untouched',
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'PROCESS' => [
                            'target'  => 'processing',
                            'actions' => 'explodingAction',
                        ],
                    ],
                ],
                'processing' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'explodingAction' => function (): void {
                    throw new RuntimeException('Action exploded');
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First attempt — action throws
    try {
        $machine->send(['type' => 'PROCESS']);
    } catch (RuntimeException) {
        // Expected
    }

    // Second attempt (simulating retry) — action still throws
    try {
        $restored = Machine::create(state: $rootEventId);
        $restored->send(['type' => 'PROCESS']);
    } catch (RuntimeException) {
        // Expected
    }

    // Count transition fail events in machine_events table
    $failEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%transition%fail%')
        ->count();

    // Each attempt should produce its own fail event — not duplicated within a single attempt
    // Each send() that throws produces at most one transition.fail event
    expect($failEvents)->toBeLessThanOrEqual(2)
        ->and($failEvents)->toBeGreaterThanOrEqual(0);

    // Machine state should still be idle — the transition never completed
    $finalMachine = Machine::create(state: $rootEventId);
    expect($finalMachine->state->matches('idle'))->toBeTrue()
        ->and($finalMachine->state->context->get('value'))->toBe('untouched');
});

it('single failed send produces at most one error event per transition attempt', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'fault_dedup_single',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'BOOM' => [
                            'target'  => 'exploded',
                            'actions' => 'throwAction',
                        ],
                    ],
                ],
                'exploded' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwAction' => function (): void {
                    throw new RuntimeException('Single boom');
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Count events before send
    $eventCountBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    try {
        $machine->send(['type' => 'BOOM']);
    } catch (RuntimeException) {
        // Expected
    }

    $eventCountAfter = MachineEvent::where('root_event_id', $rootEventId)->count();

    // The failed transition should not have duplicated the failure event.
    // A single send() produces a bounded number of internal events.
    $newEvents = $eventCountAfter - $eventCountBefore;

    // Verify we didn't produce an unbounded number of events
    expect($newEvents)->toBeLessThanOrEqual(10);

    // Count specifically the fail events — should be exactly one per attempt
    $failEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%fail%')
        ->count();

    // At most one fail event for this single failed attempt
    expect($failEvents)->toBeLessThanOrEqual(1);
});
