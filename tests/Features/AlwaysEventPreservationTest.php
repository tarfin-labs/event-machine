<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\InitAlwaysMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\AlwaysChainMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\RaiseAlwaysMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\TimerAfterAlwaysMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\TimerEveryAlwaysMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation\CompoundDoneAlwaysMachine;

// === Core — Event Preservation ===

it('preserves event payload in @always action', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send([
        'type'    => 'SUBMIT',
        'payload' => ['tckn' => '12345678901'],
    ]);

    expect($machine->state->context->get('captured_payload'))
        ->toBe(['tckn' => '12345678901']);
});

it('preserves event payload through chained @always states', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send([
        'type'    => 'SUBMIT',
        'payload' => ['tckn' => '12345678901', 'phone' => '5551234567'],
    ]);

    // Machine should end in verification (3rd state in chain)
    expect($machine->state->value)->toBe(['always_chain.verification']);

    // Payload should be captured by action on first @always transition
    expect($machine->state->context->get('captured_payload'))
        ->toBe(['tckn' => '12345678901', 'phone' => '5551234567']);
});

it('provides original event to guard on @always transition', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT', 'payload' => ['data' => 'test']]);

    expect($machine->state->context->get('guard_event_type'))
        ->toBe('SUBMIT');
});

it('provides original event to calculator on @always transition', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT', 'payload' => ['amount' => 100]]);

    expect($machine->state->context->get('calculator_payload'))
        ->toBe(['amount' => 100]);
});

it('provides original event to entry action of @always target state', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    // Entry action on eligibility (target of first @always) captures event type
    expect($machine->state->context->get('entry_event_type'))
        ->toBe('SUBMIT');
});

it('preserves actor from original event through @always chain', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send([
        'type'    => 'SUBMIT',
        'payload' => ['data' => 'test'],
        'actor'   => 42,
    ]);

    expect($machine->state->context->get('captured_actor'))
        ->toBe(42);
});

it('preserves event type as original type, not @always', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    expect($machine->state->context->get('captured_event_type'))
        ->toBe('SUBMIT');
});

it('provides triggering event via parameter injection, not via currentEventBehavior', function (): void {
    // $state->currentEventBehavior is overwritten by internal event logging (action start/end markers).
    // Parameter injection is the correct and only reliable access path for the triggering event.
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    // Parameter injection provides the original event
    expect($machine->state->context->get('captured_event_type'))->toBe('SUBMIT');

    // $state->currentEventBehavior is NOT guaranteed to be the triggering event —
    // it tracks internal events during action execution. This is by design.
});

it('still records @always transitions in internal event history', function (): void {
    $machine = AlwaysChainMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    $historyTypes = $machine->state->history->pluck('type')->toArray();

    // History should contain @always-related internal event entries
    $alwaysEntries = array_filter($historyTypes, fn (string $type): bool => str_contains($type, '@always'));

    expect($alwaysEntries)->not->toBeEmpty();
});

// === Edge Cases — Init, raise, Timer, Compound @done ===

it('handles init @always with no triggering event — behaviors receive @always event', function (): void {
    $machine = InitAlwaysMachine::create();

    // Machine should auto-transition from routing → active via @always on create
    expect($machine->state->value)->toBe(['init_always.active']);

    // No triggering event exists at init, so the synthetic @always event is injected
    // (triggeringEvent is null → effectiveEvent falls back to the @always event)
    expect($machine->state->context->get('init_event_type'))->toBe('@always');
});

it('preserves raised event through @always chain', function (): void {
    $machine = RaiseAlwaysMachine::create();

    $machine->send(['type' => 'START']);

    // Machine should end in done: idle → raising → (raise PROCEED) → routing(@always) → done
    expect($machine->state->value)->toBe(['raise_always.done']);

    // @always action should receive the PROCEED event (raised by entry action)
    expect($machine->state->context->get('raised_event_type'))->toBe('PROCEED');
});

it('preserves timer @after event through @always chain', function (): void {
    $machine = TimerAfterAlwaysMachine::create();

    // Simulate timer dispatch — timer sends TIMEOUT as a normal event
    $machine->send([
        'type'    => 'TIMEOUT',
        'payload' => ['expired_at' => '2026-03-20'],
    ]);

    expect($machine->state->value)->toBe(['timer_after_always.done']);

    // @always action should receive the original TIMEOUT event
    expect($machine->state->context->get('timer_event_type'))->toBe('TIMEOUT');
    expect($machine->state->context->get('timer_event_payload'))
        ->toBe(['expired_at' => '2026-03-20']);
});

it('preserves timer @every event through @always chain', function (): void {
    $machine = TimerEveryAlwaysMachine::create();

    // Simulate recurring timer dispatch
    $machine->send([
        'type'    => 'BILLING',
        'payload' => ['cycle' => 1, 'amount' => 99],
    ]);

    expect($machine->state->value)->toBe(['timer_every_always.billed']);

    // @always action should receive the original BILLING event
    expect($machine->state->context->get('billing_event_type'))->toBe('BILLING');
    expect($machine->state->context->get('billing_event_payload'))
        ->toBe(['cycle' => 1, 'amount' => 99]);
});

it('fires @always after compound @done — fixed', function (): void {
    $machine = CompoundDoneAlwaysMachine::create();

    // Send event that causes child to reach final → @done routes to 'routing'
    // → @always on routing fires → transitions to 'completed'
    $machine->send([
        'type'    => 'CHECK_COMPLETED',
        'payload' => ['result' => 'passed'],
    ]);

    // @always after compound @done now works — machine reaches 'completed'
    expect($machine->state->value)->toBe(['compound_done_always.completed']);

    // @always action received the original event
    expect($machine->state->context->get('done_event_type'))->not->toBeNull();
});
