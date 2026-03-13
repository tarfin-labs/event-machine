<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;

// ============================================================
// Async Queue Dispatch
// ============================================================

it('dispatches ChildMachineJob when queue is configured', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Parent stays in processing (async — child runs on queue)
    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    // ChildMachineJob was dispatched
    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childMachineClass === SimpleChildMachine::class
            && $job->parentStateId === 'async_parent.processing';
    });
});

it('creates MachineChild tracking record in pending status', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    expect($childRecord)->not->toBeNull()
        ->and($childRecord->child_machine_class)->toBe(SimpleChildMachine::class)
        ->and($childRecord->parent_state_id)->toBe('async_parent.processing')
        ->and($childRecord->status)->toBe(MachineChild::STATUS_PENDING);
});

it('passes resolved child context to ChildMachineJob', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->state->context->set('order_id', 'ORD-789');
    $machine->send(['type' => 'START']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childContext === ['order_id' => 'ORD-789'];
    });
});

// ============================================================
// Completion Job Idempotency
// ============================================================

it('routeChildDoneEvent is safe when parent already transitioned', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Parent stays at processing (async child dispatched to queue)
    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    $stateDefinition = $machine->definition->idMap['async_parent.processing'];

    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => ['status' => 'ok'],
        'child_context' => [],
        'machine_id'    => '',
        'machine_class' => SimpleChildMachine::class,
    ]);

    // First call: transitions from processing → completed
    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);
    expect($machine->state->value)->toBe(['async_parent.completed']);

    // Second call: should be safe — no double transition, no exception
    $machine->definition->routeChildDoneEvent($machine->state, $stateDefinition, $doneEvent);
    expect($machine->state->value)->toBe(['async_parent.completed']);
});

// ============================================================
// Job Failure → @fail
// ============================================================

it('ChildMachineJob failed() dispatches completion job with error', function (): void {
    Queue::fake();

    $job = new ChildMachineJob(
        parentRootEventId: 'test-root-event-id',
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'async_parent.processing',
        childMachineClass: FailingChildMachine::class,
        machineChildId: 'test-child-id',
        childContext: [],
    );

    // Simulate job failure
    $exception = new RuntimeException('Child machine crashed');
    $job->failed($exception);

    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $completionJob): bool {
        return $completionJob->success === false
            && $completionJob->errorMessage === 'Child machine crashed'
            && $completionJob->childMachineClass === FailingChildMachine::class;
    });
});

it('ChildMachineJob sets MachineChild to failed on exception', function (): void {
    Queue::fake();

    // Create a tracking record
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root-event-id',
        'parent_state_id'      => 'async_parent.processing',
        'child_machine_class'  => FailingChildMachine::class,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    $job = new ChildMachineJob(
        parentRootEventId: 'test-root-event-id',
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'async_parent.processing',
        childMachineClass: FailingChildMachine::class,
        machineChildId: $childRecord->id,
        childContext: [],
    );

    $job->failed(new RuntimeException('boom'));

    $childRecord->refresh();

    expect($childRecord->status)->toBe(MachineChild::STATUS_FAILED)
        ->and($childRecord->completed_at)->not->toBeNull();
});
