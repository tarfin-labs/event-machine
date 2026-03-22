<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\RaiseOnDoneParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\RaiseOnFailParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AlwaysOnDoneParentMachine;

// ============================================================
// Event Queue Processing After Child Completion
// ============================================================

it('processes raised events from entry actions after @done child transition', function (): void {
    Queue::fake();

    $machine = RaiseOnDoneParentMachine::create();
    $machine->send(['type' => 'START']);

    // Parent stays at processing (async child dispatched to queue)
    expect($machine->state->currentStateDefinition->id)->toBe('raise_on_done_parent.processing');

    $stateDefinition = $machine->definition->idMap['raise_on_done_parent.processing'];

    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => ['status' => 'ok'],
        'output'        => [],
        'machine_id'    => '',
        'machine_class' => SimpleChildMachine::class,
    ]);

    // Route @done — entry action raises NEXT, which should be processed
    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);

    // Should be in 'completed', NOT stuck in 'received'
    expect($machine->state->value)->toBe(['raise_on_done_parent.completed']);
});

it('follows @always transitions on @done target state after child completion', function (): void {
    Queue::fake();

    $machine = AlwaysOnDoneParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('always_on_done_parent.processing');

    $stateDefinition = $machine->definition->idMap['always_on_done_parent.processing'];

    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => ['status' => 'ok'],
        'output'        => [],
        'machine_id'    => '',
        'machine_class' => SimpleChildMachine::class,
    ]);

    // Route @done — target state has @always → completed
    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);

    // Should follow @always to 'completed', NOT stuck in 'routing'
    expect($machine->state->value)->toBe(['always_on_done_parent.completed']);
});

it('processes raised events from entry actions after @fail child transition', function (): void {
    Queue::fake();

    $machine = RaiseOnFailParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('raise_on_fail_parent.processing');

    $stateDefinition = $machine->definition->idMap['raise_on_fail_parent.processing'];

    $failEvent = ChildMachineFailEvent::forChild([
        'error_message' => 'Child failed',
        'machine_id'    => '',
        'machine_class' => SimpleChildMachine::class,
        'output'        => [],
    ]);

    // Route @fail — entry action raises HANDLE_ERROR, which should be processed
    $machine->definition->routeChildFailEvent($machine->state, $stateDefinition, $failEvent);

    // Should be in 'handled', NOT stuck in 'error_received'
    expect($machine->state->value)->toBe(['raise_on_fail_parent.handled']);
});

it('existing async child @done delegation still works without raise or @always', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    $stateDefinition = $machine->definition->idMap['async_parent.processing'];

    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => ['status' => 'ok'],
        'output'        => [],
        'machine_id'    => '',
        'machine_class' => SimpleChildMachine::class,
    ]);

    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);

    expect($machine->state->value)->toBe(['async_parent.completed']);
});
