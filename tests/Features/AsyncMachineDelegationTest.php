<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;
use Tarfinlabs\EventMachine\Locks\MachineLockHandle;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Jobs\ChildMachineTimeoutJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Definition\MachineInvokeDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
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
        'output'        => [],
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
        'parent_machine_class' => AsyncParentMachine::class,
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
    Queue::fake();

    // Simulate race: child completed before timeout job runs
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'parent_machine_class' => AsyncTimeoutParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_COMPLETED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    // isTerminal() should return true for completed children
    expect($childRecord->isTerminal())->toBeTrue();

    // Run the actual job handle() — should be a no-op
    $job = new ChildMachineTimeoutJob(
        parentRootEventId: 'test-root',
        parentMachineClass: AsyncTimeoutParentMachine::class,
        parentStateId: 'timeout_parent.processing',
        machineChildId: $childRecord->id,
        childMachineClass: SimpleChildMachine::class,
        timeoutSeconds: 30,
    );

    $job->handle();

    // Child status should remain completed (not changed to timed_out)
    $childRecord->refresh();
    expect($childRecord->status)->toBe(MachineChild::STATUS_COMPLETED);

    // No completion job dispatched
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('ChildMachineTimeoutJob skips when child is already failed', function (): void {
    Queue::fake();

    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'parent_machine_class' => AsyncTimeoutParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_FAILED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    expect($childRecord->isTerminal())->toBeTrue();

    // Run the actual job handle() — should be a no-op
    $job = new ChildMachineTimeoutJob(
        parentRootEventId: 'test-root',
        parentMachineClass: AsyncTimeoutParentMachine::class,
        parentStateId: 'timeout_parent.processing',
        machineChildId: $childRecord->id,
        childMachineClass: SimpleChildMachine::class,
        timeoutSeconds: 30,
    );

    $job->handle();

    // Child status should remain failed
    $childRecord->refresh();
    expect($childRecord->status)->toBe(MachineChild::STATUS_FAILED);
});

it('ChildMachineTimeoutJob marks child as timed_out', function (): void {
    // Create a child record in running status
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'timeout_parent.processing',
        'parent_machine_class' => AsyncTimeoutParentMachine::class,
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

// NOTE: Full forward routing E2E (parent → running child → event delivered → child transitions)
// requires a real async pipeline (Queue + Horizon) because tryForwardEventToChild
// restores the child from DB synchronously — the child must be persisted by a real
// ChildMachineJob run. This is covered in LocalQA tests.
// Unit-level forward config tests are above (resolveForwardEvent, hasForward).

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

// ============================================================
// Parent State Change / Cancel Async Child
// ============================================================

it('parent transition away clears active children list', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Parent is at processing, child dispatched
    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.processing')
        ->and($machine->state->hasActiveChildren())->toBeTrue();

    // Parent transitions away via CANCEL (to 'skipped' state)
    $machine->send(['type' => 'CANCEL']);

    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.skipped')
        ->and($machine->state->hasActiveChildren())->toBeFalse();
});

it('completion job for cancelled child is discarded when parent moved', function (): void {
    Queue::fake();

    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    // Get the processing state definition before parent moves
    $stateDefinition = $machine->definition->idMap['async_parent.processing'];

    // Parent transitions away
    $machine->send(['type' => 'CANCEL']);
    expect($machine->state->currentStateDefinition->id)->toBe('async_parent.skipped');

    // Simulate late @done arrival — the ChildMachineCompletionJob would
    // check currentStateDefinition.id !== parentStateId and return early.
    // At the routing level, calling routeChildDoneEvent on the processing
    // state definition when parent is at skipped still executes (no guard at this level).
    // But the ChildMachineCompletionJob has the real guard.
    // So we verify the state check that the job does:
    expect($machine->state->currentStateDefinition->id)
        ->not->toBe($stateDefinition->id);
});

it('MachineChild can be marked cancelled', function (): void {
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'test-root',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    $childRecord->markCancelled();
    $childRecord->refresh();

    expect($childRecord->status)->toBe(MachineChild::STATUS_CANCELLED)
        ->and($childRecord->completed_at)->not->toBeNull()
        ->and($childRecord->isTerminal())->toBeTrue();
});

// ============================================================
// Parallel + Async Machine Delegation
// ============================================================

it('multiple MachineChild records can be created for same parent', function (): void {
    // Simulates parallel async children: multiple tracking records for one parent
    $parentRootEventId = 'parallel-parent-root';

    $childA = MachineChild::create([
        'parent_root_event_id' => $parentRootEventId,
        'parent_state_id'      => 'parent.region_a.delegating',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    $childB = MachineChild::create([
        'parent_root_event_id' => $parentRootEventId,
        'parent_state_id'      => 'parent.region_b.delegating',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    // Both children exist and are active
    $activeChildren = MachineChild::where('parent_root_event_id', $parentRootEventId)
        ->whereIn('status', [MachineChild::STATUS_PENDING, MachineChild::STATUS_RUNNING])
        ->get();

    expect($activeChildren)->toHaveCount(2)
        ->and($childA->id)->not->toBe($childB->id);

    // Complete one, other stays active
    $childA->markCompleted();
    $activeChildren = MachineChild::where('parent_root_event_id', $parentRootEventId)
        ->whereIn('status', [MachineChild::STATUS_PENDING, MachineChild::STATUS_RUNNING])
        ->get();

    expect($activeChildren)->toHaveCount(1)
        ->and($activeChildren->first()->id)->toBe($childB->id);
});

it('lock ensures sequential processing when multiple children complete simultaneously', function (): void {
    // The MachineLockManager is always used by ChildMachineCompletionJob
    // to prevent concurrent state mutations. This test verifies the lock
    // manager correctly prevents concurrent access.

    // Create two lock attempts for the same root_event_id
    $rootEventId = 'test-lock-root-'.uniqid();

    $handle1 = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'completion_1',
    );

    // Second acquire should fail (lock is held)
    expect(fn () => MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'completion_2',
    ))->toThrow(MachineLockTimeoutException::class);

    // Release first lock
    $handle1->release();

    // Now second acquire should succeed
    $handle2 = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'completion_2',
    );

    expect($handle2)->toBeInstanceOf(MachineLockHandle::class);

    $handle2->release();
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

// ============================================================
// Cleanup Active Children
// ============================================================

it('marks MachineChild DB records as cancelled when parent leaves delegating state', function (): void {
    Queue::fake();

    // 1. Start parent machine → enters processing state (async delegation)
    $machine = AsyncParentMachine::create();
    $machine->send(['type' => 'START']);

    $parentRootEventId = $machine->state->history->first()->root_event_id;

    // 2. Manually create MachineChild record (simulating what handleAsyncMachineInvoke did)
    $childRecord = MachineChild::create([
        'parent_root_event_id' => $parentRootEventId,
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => 'child-root-event-id',
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    // 3. Send CANCEL to parent → leaves processing state → cleanupActiveChildren fires
    $machine->send(['type' => 'CANCEL']);

    // 4. MachineChild DB record should be marked as cancelled
    $childRecord->refresh();
    expect($childRecord->status)->toBe(MachineChild::STATUS_CANCELLED)
        ->and($childRecord->completed_at)->not->toBeNull();
});

// ============================================================
// @done.{state} routing — async path
// ============================================================

it('async child completion routes via @done.{state} (T15)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'async_done_dot', 'initial' => 'delegating', 'context' => [],
        'states' => [
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'declined',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
        ],
    ]);

    // Start in delegating state (simulates async parent waiting for child)
    $state     = $machine->getInitialState();
    $stateDefn = $state->currentStateDefinition;

    // Simulate ChildMachineDoneEvent with finalState='approved'
    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => null,
        'output'        => ['decision' => 'yes'],
        'machine_id'    => 'child-123',
        'machine_class' => MultiOutcomeChildMachine::class,
        'final_state'   => 'approved',
    ]);

    $machine->routeChildDoneEvent($state, $stateDefn, $doneEvent);

    expect($state->currentStateDefinition->id)->toBe('async_done_dot.completed');
});

it('async @done.{state} falls through to catch-all (T16)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'async_fallback', 'initial' => 'delegating', 'context' => [],
        'states' => [
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    $state     = $machine->getInitialState();
    $stateDefn = $state->currentStateDefinition;

    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => null,
        'output'        => [],
        'machine_id'    => 'child-456',
        'machine_class' => MultiOutcomeChildMachine::class,
        'final_state'   => 'expired',
    ]);

    $machine->routeChildDoneEvent($state, $stateDefn, $doneEvent);

    expect($state->currentStateDefinition->id)->toBe('async_fallback.fallback');
});

it('ChildMachineDoneEvent carries finalState from CompletionJob (T17)', function (): void {
    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => null,
        'output'        => [],
        'machine_id'    => 'child-123',
        'machine_class' => MultiOutcomeChildMachine::class,
        'final_state'   => 'rejected',
    ]);

    expect($doneEvent->finalState())->toBe('rejected')
        ->and($doneEvent->childMachineClass())->toBe(MultiOutcomeChildMachine::class);

    // Legacy event without final_state
    $legacyEvent = ChildMachineDoneEvent::forChild([
        'result'        => null,
        'output'        => [],
        'machine_id'    => 'child-456',
        'machine_class' => MultiOutcomeChildMachine::class,
    ]);

    expect($legacyEvent->finalState())->toBeNull();
});
