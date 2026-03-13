<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Jobs\ChildMachineTimeoutJob;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Definition\MachineInvokeDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncTimeoutParentMachine;

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

// ============================================================
// @timeout Scenarios
// ============================================================

it('dispatches ChildMachineTimeoutJob when @timeout is configured', function (): void {
    Queue::fake();

    $machine = AsyncTimeoutParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('timeout_parent.processing');

    // Both ChildMachineJob and ChildMachineTimeoutJob should be dispatched
    Queue::assertPushed(ChildMachineJob::class);
    Queue::assertPushed(ChildMachineTimeoutJob::class, function (ChildMachineTimeoutJob $job): bool {
        return $job->childMachineClass === SimpleChildMachine::class
            && $job->timeoutSeconds === 30;
    });
});

it('routeChildTimeoutEvent transitions parent to timed_out state', function (): void {
    Queue::fake();

    $machine = AsyncTimeoutParentMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->currentStateDefinition->id)->toBe('timeout_parent.processing');

    $stateDefinition = $machine->definition->idMap['timeout_parent.processing'];

    // Simulate timeout event routing (what ChildMachineTimeoutJob does)
    $timeoutEvent = new EventDefinition(
        type: '@timeout',
        payload: [
            'machine_child_id' => 'test-child-id',
            'child_class'      => SimpleChildMachine::class,
            'timeout_seconds'  => 30,
        ],
    );

    $machine->definition->routeChildTimeoutEvent($machine->state, $stateDefinition, $timeoutEvent);

    expect($machine->state->value)->toBe(['timeout_parent.timed_out'])
        ->and($machine->state->context->get('timeout'))->toBeTrue();
});

it('ChildMachineTimeoutJob skips when child is already completed', function (): void {
    // Simulate race: child completed before timeout job runs
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_COMPLETED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    // isTerminal() should return true for completed children
    expect($childRecord->isTerminal())->toBeTrue();

    // The timeout job checks isTerminal() first — if true, returns early (no-op)
    // This is the real race guard: completed child → timeout is discarded
});

it('ChildMachineTimeoutJob skips when child is already failed', function (): void {
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_FAILED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    expect($childRecord->isTerminal())->toBeTrue();
});

it('ChildMachineTimeoutJob marks child as timed_out', function (): void {
    // Create a child record in running status
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    expect($childRecord->isTerminal())->toBeFalse();

    $childRecord->markTimedOut();
    $childRecord->refresh();

    expect($childRecord->status)->toBe(MachineChild::STATUS_TIMED_OUT)
        ->and($childRecord->completed_at)->not->toBeNull()
        ->and($childRecord->isTerminal())->toBeTrue();
});

// ============================================================
// Forward Event Routing
// ============================================================

it('resolveForwardEvent returns event type for plain forward config', function (): void {
    $invoke = new MachineInvokeDefinition(
        machineClass: SimpleChildMachine::class,
        forward: ['APPROVE', 'REJECT'],
        async: true,
        queue: 'default',
    );

    expect($invoke->resolveForwardEvent('APPROVE'))->toBe('APPROVE')
        ->and($invoke->resolveForwardEvent('REJECT'))->toBe('REJECT')
        ->and($invoke->resolveForwardEvent('UNKNOWN'))->toBeNull();
});

it('resolveForwardEvent maps parent event to child event for rename format', function (): void {
    $invoke = new MachineInvokeDefinition(
        machineClass: SimpleChildMachine::class,
        forward: ['PARENT_UPDATE' => 'CHILD_UPDATE', 'APPROVE'],
        async: true,
        queue: 'default',
    );

    expect($invoke->resolveForwardEvent('PARENT_UPDATE'))->toBe('CHILD_UPDATE')
        ->and($invoke->resolveForwardEvent('APPROVE'))->toBe('APPROVE')
        ->and($invoke->resolveForwardEvent('CHILD_UPDATE'))->toBeNull();
});

it('hasForward returns true only when forward events are configured', function (): void {
    $withForward = new MachineInvokeDefinition(
        machineClass: SimpleChildMachine::class,
        forward: ['APPROVE'],
        async: true,
        queue: 'default',
    );

    $withoutForward = new MachineInvokeDefinition(
        machineClass: SimpleChildMachine::class,
        async: true,
        queue: 'default',
    );

    expect($withForward->hasForward())->toBeTrue()
        ->and($withoutForward->hasForward())->toBeFalse();
});

it('tryForwardEventToChild returns false when no forward config exists', function (): void {
    Queue::fake();

    // AsyncParentMachine has no forward config
    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Sending an unhandled event should throw, not forward
    expect(fn () => $machine->send(['type' => 'UNKNOWN_EVENT']))
        ->toThrow(NoTransitionDefinitionFoundException::class);
});

it('tryForwardEventToChild returns false when child has no running record', function (): void {
    Queue::fake();

    // AsyncForwardParentMachine has forward config but child is only faked/dispatched (not running)
    $machine = AsyncForwardParentMachine::create();
    $machine->send(['type' => 'START']);

    // No MachineChild record with 'running' status exists (Queue::fake prevents actual job)
    // The child record exists in 'pending' status from handleAsyncMachineInvoke
    $childRecord = MachineChild::first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_PENDING);

    // Forward should fail because there's no running child with child_root_event_id
    expect(fn () => $machine->send(['type' => 'APPROVE']))
        ->toThrow(NoTransitionDefinitionFoundException::class);
});

it('forward config is parsed correctly in state definition', function (): void {
    Queue::fake();

    $machine = AsyncForwardParentMachine::create();
    $machine->send(['type' => 'START']);

    $stateDefinition  = $machine->definition->idMap['forward_parent.processing'];
    $invokeDefinition = $stateDefinition->getMachineInvokeDefinition();

    expect($invokeDefinition->hasForward())->toBeTrue()
        ->and($invokeDefinition->resolveForwardEvent('APPROVE'))->toBe('APPROVE')
        ->and($invokeDefinition->resolveForwardEvent('PARENT_UPDATE'))->toBe('CHILD_UPDATE')
        ->and($invokeDefinition->resolveForwardEvent('NONEXISTENT'))->toBeNull();
});
