<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\IncrementalContext\IncrementalContextDiffMachine;

it('applies incremental context diffs in chronological order on restore', function (): void {
    // Create machine and send events that modify context incrementally
    $machine = IncrementalContextDiffMachine::create();

    // GO1: sets key_a = 'updated_a_1'
    $machine->send(['type' => 'GO1']);

    // GO2: sets key_b = 'updated_b', key_a = 'updated_a_2' (overwrites previous)
    $machine->send(['type' => 'GO2']);

    // Capture the final state before restore
    $finalState = $machine->state;

    // Get root event ID for restoration
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from persisted events
    $restoredMachine = IncrementalContextDiffMachine::create(state: $rootEventId);
    $restoredContext = $restoredMachine->state->context;

    // key_a was set to 'updated_a_1' by GO1, then overwritten to 'updated_a_2' by GO2
    expect($restoredContext->get('keyA'))->toBe('updated_a_2');

    // key_b was set to 'updated_b' by GO2
    expect($restoredContext->get('keyB'))->toBe('updated_b');

    // key_c was never modified — should retain initial value
    expect($restoredContext->get('keyC'))->toBe('initial_c');

    // Restored state value should match original
    expect($restoredMachine->state->value)->toEqual($finalState->value);

    // Full context should match
    expect($restoredContext->toArray())->toEqual($finalState->context->toArray());
});
