<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ParallelInternalTransitionMachine;

it('context set by action survives internal transition within parallel region', function (): void {
    $machine = ParallelInternalTransitionMachine::create();

    // Send GO — triggers transition within region_a: step_1 → step_2
    // setValueAction should set valueFromAction = 42
    $machine->send(['type' => 'GO']);

    // THE CRITICAL ASSERTION: context value must survive the transition
    expect($machine->state->context->get('valueFromAction'))->toBe(42);

    // Region states should be: region_a.step_2, region_b.idle
    $regionA = collect($machine->state->value)->first(fn ($v) => str_contains($v, 'region_a'));
    expect($regionA)->toContain('step_2');
});

it('context set by action survives persist and restore after internal parallel transition', function (): void {
    $machine = ParallelInternalTransitionMachine::create();
    $machine->send(['type' => 'GO']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = ParallelInternalTransitionMachine::create(state: $rootEventId);

    expect($restored->state->context->get('valueFromAction'))->toBe(42);
});

// Known issue: parallel state fires spurious exit/entry for internal region transitions.
// Event naming uses currentStateDefinition->route (parallel state) instead of the actual
// source state's route. Does NOT cause context loss but produces misleading event names.
it('parallel state does NOT exit/enter for internal region transitions', function (): void {
    $machine = ParallelInternalTransitionMachine::create();
    $machine->send(['type' => 'GO']);

    // Check event history — processing (parallel state) should NOT have exit/entry events
    // during an internal region transition
    $events = $machine->state->history->pluck('type')->toArray();

    $parallelExitEvents = collect($events)->filter(
        fn ($e) => str_contains($e, 'processing.exit')
    );

    // If the parallel state exits and re-enters during internal transition, this is the bug
    expect($parallelExitEvents)->toBeEmpty(
        'Parallel state should NOT exit/enter for transitions within a child region'
    );
});
