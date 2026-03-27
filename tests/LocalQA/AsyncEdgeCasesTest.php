<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncFailParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SequentialParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncTimeoutParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  @timeout fires when child takes too long
// ═══════════════════════════════════════════════════════════════

it('LocalQA: @timeout fires when child does not complete in time', function (): void {
    // AsyncTimeoutParentMachine has @timeout configured
    $parent = AsyncTimeoutParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be running
    $childRunning = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $child = MachineChild::where('parent_root_event_id', $rootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child did not reach running status');

    // Do NOT complete the child — let the timeout fire
    // ChildMachineTimeoutJob is dispatched with a delay matching @timeout config
    // Wait for timeout to fire and parent to transition
    $timedOut = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'timed_out');
    }, timeoutSeconds: 60); // Generous timeout for delayed job

    expect($timedOut)->toBeTrue('Timeout did not fire — parent not in timed_out state');

    // Verify child marked as timed_out
    $childRecord = MachineChild::where('parent_root_event_id', $rootEventId)->first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_TIMED_OUT);
});

// ═══════════════════════════════════════════════════════════════
//  @fail routing — child fails via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: child failure routes parent to @fail via real Horizon', function (): void {
    // AsyncFailParentMachine delegates to FailingChildMachine which throws on entry
    // No faking — real ChildMachineJob runs, child throws, ChildMachineJob.failed() fires,
    // ChildMachineCompletionJob(success: false) dispatched → parent @fail
    $parent = AsyncFailParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for parent to reach failed
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60);

    expect($failed)->toBeTrue('Child failure did not route parent to @fail via Horizon');

    // Verify child record marked as failed
    $childRecord = MachineChild::where('parent_root_event_id', $rootEventId)->first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_FAILED);

    // Verify error captured in parent context
    $restored = AsyncFailParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('error'))->toContain('Payment gateway down');
});

// ═══════════════════════════════════════════════════════════════
//  Sequential child delegation chain
// ═══════════════════════════════════════════════════════════════

it('LocalQA: sequential delegation — two children complete in order', function (): void {
    $parent = SequentialParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for parent to reach completed (both children auto-complete)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Sequential delegation did not complete both children');

    // Verify two MachineChild records exist
    $children = MachineChild::where('parent_root_event_id', $rootEventId)->get();
    expect($children)->toHaveCount(2);

    // Both should be completed
    foreach ($children as $child) {
        expect($child->status)->toBe(MachineChild::STATUS_COMPLETED);
    }

    // No stale locks
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  Concurrent sends to same machine
// ═══════════════════════════════════════════════════════════════

it('LocalQA: concurrent sends to same machine — locking prevents corruption', function (): void {
    // Create a machine in idle state
    $machine = AsyncAutoCompleteParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch two events concurrently via Horizon
    SendToMachineJob::dispatch(
        machineClass: AsyncAutoCompleteParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    SendToMachineJob::dispatch(
        machineClass: AsyncAutoCompleteParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'ADVANCE'],
    );

    // Wait for machine to settle (one event should succeed, one may fail or be handled)
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        // Machine should be in some state beyond idle
        return $cs && !str_contains($cs->state_id, 'idle');
    }, timeoutSeconds: 60);

    expect($settled)->toBeTrue('Neither concurrent send was processed');

    // Machine should be in a consistent state (not corrupted)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs)->not->toBeNull();

    // No stale locks
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
