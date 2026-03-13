<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Routing\MachineController;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

// ─── Webhook → Final → Parent @done ────────────────────────────

it('auto-dispatches completion job when child reaches final state via endpoint', function (): void {
    Queue::fake();

    // 1. Create parent machine (async delegation started)
    $parentMachine = AsyncParentMachine::create();
    $parentMachine->persist();
    $parentRootEventId = $parentMachine->state->history->first()->root_event_id;

    // 2. Create child machine (simulates async child that was spawned)
    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // 3. Create MachineChild tracking record (as handleAsyncMachineInvoke would)
    MachineChild::create([
        'parent_root_event_id' => $parentRootEventId,
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    // 4. Simulate webhook: send COMPLETE event to child → reaches final state
    $state = $childMachine->send(['type' => 'COMPLETE']);

    // 5. Call dispatchChildCompletionIfFinal (what MachineController does after endpoint event)
    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // 6. Verify: ChildMachineCompletionJob dispatched with correct parent details
    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $job) use ($parentRootEventId): bool {
        return $job->parentRootEventId === $parentRootEventId
            && $job->parentMachineClass === AsyncParentMachine::class
            && $job->parentStateId === 'async_parent.processing'
            && $job->childMachineClass === SimpleChildMachine::class
            && $job->success === true;
    });

    // 7. Verify: MachineChild record marked as completed
    $childRecord = MachineChild::first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_COMPLETED)
        ->and($childRecord->completed_at)->not->toBeNull();
});

it('does not dispatch completion when child does not reach final state', function (): void {
    Queue::fake();

    // Child machine stays in idle (non-final state)
    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $childMachine->state);

    // No completion job should be dispatched
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('does not dispatch completion when no MachineChild tracking record exists', function (): void {
    Queue::fake();

    // Child machine reaches final state, but has no tracking record (standalone machine)
    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $state = $childMachine->send(['type' => 'COMPLETE']);

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // No completion job — this machine is not a tracked child
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

// ─── Webhook after cancelled → discarded ────────────────────────

it('does not dispatch completion when MachineChild is already completed', function (): void {
    Queue::fake();

    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // Tracking record already completed (race condition: child completed before webhook)
    MachineChild::create([
        'parent_root_event_id' => 'parent-root-id',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_COMPLETED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    $state = $childMachine->send(['type' => 'COMPLETE']);

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // Discarded — already completed
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('does not dispatch completion when MachineChild is cancelled', function (): void {
    Queue::fake();

    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // Tracking record cancelled (parent moved on)
    MachineChild::create([
        'parent_root_event_id' => 'parent-root-id',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_CANCELLED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    $state = $childMachine->send(['type' => 'COMPLETE']);

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // Discarded — child was cancelled
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('does not dispatch completion when MachineChild is timed out', function (): void {
    Queue::fake();

    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // Tracking record timed out
    MachineChild::create([
        'parent_root_event_id' => 'parent-root-id',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_TIMED_OUT,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    $state = $childMachine->send(['type' => 'COMPLETE']);

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // Discarded — child timed out
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

it('does not dispatch completion when MachineChild has already failed', function (): void {
    Queue::fake();

    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // Tracking record already failed
    MachineChild::create([
        'parent_root_event_id' => 'parent-root-id',
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_FAILED,
        'created_at'           => now(),
        'completed_at'         => now(),
    ]);

    $state = $childMachine->send(['type' => 'COMPLETE']);

    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // Discarded — child already failed (parent already routed @fail)
    Queue::assertNotPushed(ChildMachineCompletionJob::class);
});

// ─── End-to-End: webhook → completion → parent transition ───────

it('full webhook flow: child endpoint → final → completion job → parent @done', function (): void {
    // 1. Create and persist parent machine, move to processing state
    $parentMachine = AsyncParentMachine::create();
    $parentMachine->send(['type' => 'ADVANCE']); // Go to idle → skipped? No, need processing.

    // Actually: let's use Queue::fake to prevent actual job dispatch during START,
    // then handle completion manually.
    Queue::fake();
    $parentMachine = AsyncParentMachine::create();
    $parentMachine->send(['type' => 'START']);
    $parentMachine->persist();
    $parentRootEventId = $parentMachine->state->history->first()->root_event_id;

    expect($parentMachine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    // 2. Create child machine (as if spawned by ChildMachineJob)
    $childMachine = SimpleChildMachine::create();
    $childMachine->persist();
    $childRootEventId = $childMachine->state->history->first()->root_event_id;

    // 3. Create tracking record
    MachineChild::create([
        'parent_root_event_id' => $parentRootEventId,
        'parent_state_id'      => 'async_parent.processing',
        'parent_machine_class' => AsyncParentMachine::class,
        'child_machine_class'  => SimpleChildMachine::class,
        'child_root_event_id'  => $childRootEventId,
        'status'               => MachineChild::STATUS_RUNNING,
        'created_at'           => now(),
    ]);

    // Stop faking queue for the completion job dispatch
    Queue::swap(new QueueFake(app()));

    // 4. Simulate webhook: child receives COMPLETE → reaches final
    $state = $childMachine->send(['type' => 'COMPLETE']);
    $childMachine->persist();

    // 5. MachineController auto-dispatches completion
    $controller = new MachineController();
    $reflection = new ReflectionMethod($controller, 'dispatchChildCompletionIfFinal');
    $reflection->invoke($controller, $childMachine, $state);

    // 6. Verify completion job was dispatched
    Queue::assertPushed(ChildMachineCompletionJob::class);

    // 7. Now manually handle the completion job (simulating queue worker)
    $completionJob = null;
    Queue::assertPushed(ChildMachineCompletionJob::class, function (ChildMachineCompletionJob $job) use (&$completionJob): bool {
        $completionJob = $job;

        return true;
    });

    $completionJob->handle();

    // 8. Verify: parent transitioned from processing → completed
    $restoredParent = AsyncParentMachine::create(state: $parentRootEventId);
    expect($restoredParent->state->currentStateDefinition->id)->toBe('async_parent.completed');
});
