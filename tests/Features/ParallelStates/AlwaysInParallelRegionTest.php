<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\AlwaysInParallelRegionMachine;

// ═══════════════════════════════════════════════════════════════
//  @always transition in parallel region — context persistence
//
//  Reproduces CarSalesMachine bug report:
//  awaiting_input → DATA_SUBMITTED → calculating → @always(calculateAction) → awaiting_options
//  calculateAction writes to context. The data must survive persist + restore.
// ═══════════════════════════════════════════════════════════════

it('context written by @always action in parallel region survives persist and restore', function (): void {
    $machine = AlwaysInParallelRegionMachine::create();
    $machine->send(['type' => 'START']);

    // Send event that triggers: awaiting_input → calculating → @always → awaiting_options
    $machine->send(['type' => 'DATA_SUBMITTED']);

    // Verify: calculateAction ran during @always (in-memory)
    expect($machine->state->context->get('inputData'))->toBe('vehicle-vin-123')
        ->and($machine->state->context->get('calculatedData'))->toBe([
            'basePrice'      => 100000,
            'vatAmount'      => 18000,
            'totalPrice'     => 118000,
            'monthlyPayment' => 3277,
        ]);

    // Verify: machine is at awaiting_options (not stuck at calculating)
    $retailerState = collect($machine->state->value)->first(
        fn ($v) => str_contains($v, 'retailer')
    );
    expect($retailerState)->toContain('awaiting_options');

    // Persist to DB
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from DB — simulates what an endpoint does
    $restored = AlwaysInParallelRegionMachine::create(state: $rootEventId);

    // THE CRITICAL ASSERTION: calculatedData must survive restore
    expect($restored->state->context->get('calculatedData'))->toBe([
        'basePrice'      => 100000,
        'vatAmount'      => 18000,
        'totalPrice'     => 118000,
        'monthlyPayment' => 3277,
    ]);
    expect($restored->state->context->get('inputData'))->toBe('vehicle-vin-123');

    // Also verify state is correct after restore
    $restoredRetailerState = collect($restored->state->value)->first(
        fn ($v) => str_contains($v, 'retailer')
    );
    expect($restoredRetailerState)->toContain('awaiting_options');
});

it('context from @always action is available in subsequent event (OPTIONS_SELECTED)', function (): void {
    $machine = AlwaysInParallelRegionMachine::create();
    $machine->send(['type' => 'START']);
    $machine->send(['type' => 'DATA_SUBMITTED']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore and send another event — simulates second endpoint call
    $restored = AlwaysInParallelRegionMachine::create(state: $rootEventId);
    $restored->send(['type' => 'OPTIONS_SELECTED']);
    $restored->persist();

    // Restore again — all three action results must be present
    $final = AlwaysInParallelRegionMachine::create(state: $rootEventId);

    expect($final->state->context->get('inputData'))->toBe('vehicle-vin-123')
        ->and($final->state->context->get('calculatedData'))->toBe([
            'basePrice'      => 100000,
            'vatAmount'      => 18000,
            'totalPrice'     => 118000,
            'monthlyPayment' => 3277,
        ])
        ->and($final->state->context->get('selectedOption'))->toBe('36-months');

    // Retailer region should be at done (final)
    $retailerState = collect($final->state->value)->first(
        fn ($v) => str_contains($v, 'retailer')
    );
    expect($retailerState)->toContain('done');
});

it('toResponseArray does NOT include dynamic context keys — use typed ContextManager or get()', function (): void {
    // toResponseArray() delegates to Spatie Data::toArray() which only serializes
    // declared PHP properties. Inline context keys (from 'context' => [...] array)
    // are stored in the internal data array but NOT as declared properties.
    //
    // This is expected behavior, not a bug. For endpoint responses:
    // - Use a typed ContextManager subclass with declared properties, OR
    // - Use a ResultBehavior that reads via $context->get('key')

    $machine = AlwaysInParallelRegionMachine::create();
    $machine->send(['type' => 'START']);
    $machine->send(['type' => 'DATA_SUBMITTED']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = AlwaysInParallelRegionMachine::create(state: $rootEventId);

    // toResponseArray does NOT contain dynamic keys
    $response = $restored->state->context->toResponseArray();
    expect($response)->not->toHaveKey('calculatedData');

    // But get() works — data IS in context, just not in toArray() serialization
    expect($restored->state->context->get('calculatedData'))->toBe([
        'basePrice'      => 100000,
        'vatAmount'      => 18000,
        'totalPrice'     => 118000,
        'monthlyPayment' => 3277,
    ]);
});
