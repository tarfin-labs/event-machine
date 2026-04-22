<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\GrandchildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MiddleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ThreeLevelParentMachine;

// ============================================================
// Retry-Safe Propagation Recovery (v9.8.1 fix)
// ============================================================
// Bug: a ChildMachineCompletionJob's propagateChainCompletion previously
// marked the MachineChild terminal BEFORE dispatching the downstream
// completion job. If SIGTERM hit between the DB commit and the Redis push,
// the downstream job was lost and the queue retry of the original job
// silently returned at the pre-lock idempotency check (line 116) because
// the middle machine had already transitioned.
//
// Fix: reorder so markCompleted/markFailed runs AFTER dispatch, and add
// a recovery branch to handle() that detects orphaned-RUNNING MachineChild
// rows and re-triggers propagation on retry.

// ─── Test 1: happy path unchanged ─────────────────────────────

it('propagateChainCompletion still dispatches downstream job and marks terminal on happy path', function (): void {
    Queue::fake();

    $middle = MiddleChildMachine::create();
    $middle->send(['type' => 'START']);

    $middleId = $middle->state->history->first()->root_event_id;

    // Simulate the grandparent → middle MachineChild tracking row (RUNNING).
    $childRecord = MachineChild::create([
        'parent_root_event_id' => 'grandparent-root-id',
        'parent_state_id'      => 'grandparent.delegating',
        'parent_machine_class' => ThreeLevelParentMachine::class,
        'child_machine_class'  => MiddleChildMachine::class,
        'child_root_event_id'  => $middleId,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    // Force the middle machine to a final state (failed).
    $stateDef = $middle->definition->idMap['middle_child.delegating'];
    $middle->state->setInternalEventBehavior(
        type: InternalEvent::CHILD_MACHINE_FAIL,
        placeholder: GrandchildMachine::class,
    );
    $middle->definition->routeChildFailEvent(
        $middle->state,
        $stateDef,
        ChildMachineFailEvent::forChild([
            'error_message' => 'grandchild failed',
            'machine_id'    => 'gc-id',
            'machine_class' => GrandchildMachine::class,
        ]),
    );
    $middle->persist();

    expect($middle->state->currentStateDefinition->id)->toBe('middle_child.failed');

    // Instantiate the completion job representing "middle machine reached failed".
    // parentStateId here is middle's OWN invoking state (delegating), which middle
    // has already moved past. So this exercises the line 116 branch.
    $job = new ChildMachineCompletionJob(
        parentRootEventId: $middleId,
        parentMachineClass: MiddleChildMachine::class,
        parentStateId: 'middle_child.delegating',
        childMachineClass: GrandchildMachine::class,
        childRootEventId: 'gc-id',
        success: false,
    );

    $job->handle();

    // Recovery branch should have fired propagateChainCompletion →
    // downstream ChildMachineCompletionJob dispatched for the grandparent.
    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $downstream): bool {
        return $downstream->parentRootEventId === 'grandparent-root-id'
            && $downstream->parentStateId === 'grandparent.delegating'
            && $downstream->childMachineClass === MiddleChildMachine::class
            && $downstream->success === false;
    });

    // MachineChild row marked failed AFTER dispatch (new order).
    $childRecord->refresh();
    expect($childRecord->status)->toBe(MachineChild::STATUS_FAILED)
        ->and($childRecord->completed_at)->not->toBeNull();
});

// ─── Test 2: idempotent skip when no orphan row exists ────────

it('logs idempotent skip and does not dispatch when parent transitioned AND MachineChild is not RUNNING', function (): void {
    Queue::fake();
    Log::spy();

    $middle = MiddleChildMachine::create();
    $middle->send(['type' => 'START']);
    $middleId = $middle->state->history->first()->root_event_id;

    // Force middle to failed final state.
    $stateDef = $middle->definition->idMap['middle_child.delegating'];
    $middle->state->setInternalEventBehavior(
        type: InternalEvent::CHILD_MACHINE_FAIL,
        placeholder: GrandchildMachine::class,
    );
    $middle->definition->routeChildFailEvent(
        $middle->state,
        $stateDef,
        ChildMachineFailEvent::forChild([
            'error_message' => 'grandchild failed',
            'machine_id'    => 'gc-id',
            'machine_class' => GrandchildMachine::class,
        ]),
    );
    $middle->persist();

    // Create the MachineChild row but mark it already FAILED (no orphan — prior attempt succeeded).
    MachineChild::create([
        'parent_root_event_id' => 'grandparent-root-id',
        'parent_state_id'      => 'grandparent.delegating',
        'parent_machine_class' => ThreeLevelParentMachine::class,
        'child_machine_class'  => MiddleChildMachine::class,
        'child_root_event_id'  => $middleId,
        'status'               => MachineChild::STATUS_FAILED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    $job = new ChildMachineCompletionJob(
        parentRootEventId: $middleId,
        parentMachineClass: MiddleChildMachine::class,
        parentStateId: 'middle_child.delegating',
        childMachineClass: GrandchildMachine::class,
        childRootEventId: 'gc-id',
        success: false,
    );

    $job->handle();

    // Recovery called propagateChainCompletion, which found no RUNNING row → early return. No dispatch.
    Queue::assertNotPushed(ChildMachineCompletionJob::class);

    // Structured idempotent-skip log was emitted.
    Log::shouldHaveReceived('info')->withArgs(function ($message, $context) use ($middleId): bool {
        return str_contains($message, 'parent already transitioned')
            && ($context['parent_root_event_id'] ?? null) === $middleId
            && ($context['parent_state_id'] ?? null) === 'middle_child.delegating';
    })->atLeast()->once();
});

// ─── Test 3: no recovery when parent is not in a FINAL state ──

it('does not attempt recovery when parent is past invoking state but NOT in a final state', function (): void {
    Queue::fake();

    // Middle starts at idle (non-final), then transitions to delegating, then stays.
    // Setup: pretend prior attempt for state 'middle_child.idle' succeeded — middle is now 'delegating'.
    $middle = MiddleChildMachine::create();
    $middle->send(['type' => 'START']);  // idle → delegating (not final)

    $middleId = $middle->state->history->first()->root_event_id;
    expect($middle->state->currentStateDefinition->id)->toBe('middle_child.delegating');

    // Orphan MachineChild row (shouldn't matter — middle isn't in final).
    MachineChild::create([
        'parent_root_event_id' => 'grandparent-root-id',
        'parent_state_id'      => 'grandparent.delegating',
        'parent_machine_class' => ThreeLevelParentMachine::class,
        'child_machine_class'  => MiddleChildMachine::class,
        'child_root_event_id'  => $middleId,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    $job = new ChildMachineCompletionJob(
        parentRootEventId: $middleId,
        parentMachineClass: MiddleChildMachine::class,
        parentStateId: 'middle_child.idle',  // stale — middle is now in 'delegating'
        childMachineClass: GrandchildMachine::class,
        childRootEventId: 'gc-id',
        success: false,
    );

    $job->handle();

    // No recovery: middle is not in final state. No downstream dispatch.
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

// ─── Test 4: under-lock idempotent skip logs ──────────────────

it('logs under-lock idempotent skip with success flag', function (): void {
    Log::spy();

    // This test is a behavioral sanity — we call handle() on a fresh machine
    // in the invoking state, but simulate it transitioning away between
    // pre-lock and under-lock checks. Since that race is hard to reproduce
    // deterministically in a unit test, we rely on the pre-lock branch
    // (test 2) to cover the log invariant and document the under-lock
    // path with a skip. Covered by LocalQA tests under concurrent load.
})->skip('Under-lock race is LocalQA territory — see tests/LocalQA/');
