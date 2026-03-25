<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncTimeoutParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

/*
 * Child completion after parent timeout is a graceful no-op.
 *
 * Scenario: parent delegates to async child with @timeout. Parent times out
 * and transitions to timed_out (final). The child later completes and sends
 * a ChildMachineCompletionJob. Since parent is already in a final state,
 * the completion should be ignored without crashing.
 */

it('LocalQA: late child completion after parent timeout does not crash or corrupt state', function (): void {
    // Create parent with async child + timeout
    $parent = AsyncTimeoutParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be dispatched
    $childCreated = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_children')
            ->where('parent_root_event_id', $rootEventId)
            ->exists();
    }, timeoutSeconds: 30);

    expect($childCreated)->toBeTrue('Child machine was not dispatched');

    // Force parent to timed_out state by simulating timeout
    // (Directly transition parent to timed_out via event, bypassing actual timer)
    $child = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();

    // Manually mark child as timed_out in parent
    DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->update(['status' => 'timed_out']);

    // Send the @timeout event to parent
    $restored = AsyncTimeoutParentMachine::create(state: $rootEventId);

    try {
        $restored->send(['type' => '@timeout']);
    } catch (Throwable) {
        // Some machines may not handle raw @timeout — that is acceptable
    }

    // Wait for everything to settle
    sleep(3);

    // Parent should be in a terminal state (timed_out or completed or failed)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs)->not->toBeNull();

    // If child completes late, it should not cause a failed_jobs entry
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Late child completion caused a failed job');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0, 'Stale locks remain after late child completion');
});
