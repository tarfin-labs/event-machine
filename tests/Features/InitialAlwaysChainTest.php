<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\InitialAlwaysChainMachine;

// ── Tests ─────────────────────────────────────────────────────

it('resolves initial @always chain to final stable state in a single macrostep', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    // Machine must have resolved through the entire @always chain
    // and be sitting at step_three, not step_one or step_two.
    expect($machine->state->value)
        ->toBe(['initial_always_chain.workflow.step_three']);
});

it('executes all entry actions through the @always chain', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    $actionLog = $machine->state->context->get('actionLog');

    expect($actionLog)->toBe([
        'entry:step_one',
        'entry:step_two',
        'entry:step_three',
    ]);
});

it('does not remain in any transient intermediate state', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    // Verify we are NOT stuck in step_one or step_two
    expect($machine->state->value)
        ->not->toBe(['initial_always_chain.workflow.step_one'])
        ->not->toBe(['initial_always_chain.workflow.step_two']);

    // Confirm the final stable state
    expect($machine->state->currentStateDefinition->id)
        ->toContain('step_three');
});
