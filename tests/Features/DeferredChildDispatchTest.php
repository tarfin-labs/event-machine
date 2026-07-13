<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

// ─── Sync-queue end-to-end completion ─────────────────────────────
//
// Regression tests for the silent completion loss on QUEUE_CONNECTION=sync:
// child jobs used to be dispatched mid-transition, before the parent's new
// state was persisted. The inline completion job then restored the parent at
// its PRE-transition state, skipped as "already transitioned", and the parent
// hung in the invoking state forever. Dispatch is now deferred to persist().

it('completes a managed job actor end-to-end on the sync queue', function (): void {
    // No Queue::fake() — the real sync driver runs the whole chain inline:
    // persist → ChildJobJob → ChildMachineCompletionJob → @done routing.
    $machine = JobActorParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->value)->toBe(['job_actor_parent.completed'])
        ->and($machine->state->context->get('paymentId'))->toBe('pay_test_123');

    // The persisted view agrees with the in-memory view
    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = JobActorParentMachine::create(state: $rootEventId);

    expect($restored->state->value)->toBe(['job_actor_parent.completed']);
});

it('completes an async child machine end-to-end on the sync queue', function (): void {
    $machine = AsyncAutoCompleteParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->value)->toBe(['async_auto_parent.completed']);

    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = AsyncAutoCompleteParentMachine::create(state: $rootEventId);

    expect($restored->state->value)->toBe(['async_auto_parent.completed']);
});

it('keeps the same instance usable for further sends after an inline sync chain', function (): void {
    $machine = JobActorParentMachine::create();
    $machine->send(['type' => 'START']);

    // The inline chain advanced the persisted state; the instance reloaded
    // instead of continuing from the stale pre-completion snapshot.
    expect($machine->state->history->count())
        ->toBe($machine->state->persistedEventCount);
});

// ─── Dispatch deferral ────────────────────────────────────────────

it('defers child job dispatch until persist', function (): void {
    Queue::fake();

    $definition = MachineDefinition::define(config: [
        'id'      => 'defer_initial_job',
        'initial' => 'processing',
        'context' => [],
        'states'  => [
            'processing' => [
                'job'   => SuccessfulTestJob::class,
                '@done' => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $machine = Machine::withDefinition($definition);
    $machine->start();

    // The initial state invoked the job actor, but nothing may hit the queue
    // before the machine is persisted — the job restores the parent from DB.
    Queue::assertNothingPushed();

    $machine->persist();

    Queue::assertPushed(ChildJobJob::class, function (ChildJobJob $job): bool {
        return $job->jobClass === SuccessfulTestJob::class;
    });
});

it('clears pending child dispatches when the transition fails', function (): void {
    Queue::fake();

    $machine = JobActorParentMachine::create();

    // A failed send must not leave a stale buffer behind for a later persist to flush
    try {
        $machine->send(['type' => 'NONEXISTENT_EVENT']);
    } catch (Throwable) {
        // expected — no transition defined
    }

    expect($machine->definition->pendingChildDispatches)->toBe([]);

    $machine->persist();
    Queue::assertNotPushed(ChildJobJob::class);
});
