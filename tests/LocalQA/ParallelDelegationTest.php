<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParallelDelegationParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Parallel Region + Sync Machine Delegation via Real MySQL
//
//  Tests the fix for the handleMachineInvoke bug: child machines
//  must be invoked when entering parallel region initial states,
//  and the parallel value array must be preserved when a sync
//  child completes (via @done → setCurrentStateDefinition).
//
//  ParallelDelegationParentMachine has:
//  - Region A: async child (queue: child-queue)
//  - Region B: sync child (no queue)
// ═══════════════════════════════════════════════════════════════

it('LocalQA: sync child delegation in parallel region completes and preserves value array', function (): void {
    $machine = ParallelDelegationParentMachine::create();
    $machine->send(['type' => 'START']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region B (sync) should have completed immediately.
    // Region A (async) dispatched ChildMachineJob to Horizon.

    // Check the last persisted event's machine_value
    $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('machine_id', 'parallel_delegation_parent')
        ->orderByDesc('sequence_number')
        ->first();

    $machineValue = $lastEvent->machine_value;

    // Critical: both regions must be in the value array (not wiped to single element)
    expect($machineValue)->toHaveCount(2, 'Parallel value array must contain both regions');

    // Region B must have completed (sync child finished immediately)
    $regionB = collect($machineValue)->first(fn (string $v): bool => str_contains($v, 'region_b'));
    expect($regionB)->toContain('completed');

    // Region A must still be at running (async child dispatched to Horizon)
    $regionA = collect($machineValue)->first(fn (string $v): bool => str_contains($v, 'region_a'));
    expect($regionA)->toContain('running');

    // machine_children record must exist for the async child
    $child = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($child)->not->toBeNull('Async child machine should have been dispatched');
});

it('LocalQA: async child delegation in parallel region completes via Horizon', function (): void {
    $machine = ParallelDelegationParentMachine::create();
    $machine->send(['type' => 'START']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for Horizon to process ChildMachineJob → child completes → ChildMachineCompletionJob → @done on region_a
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId): bool {
        // Check if the parent has transitioned out of the parallel state
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->where('machine_id', 'parallel_delegation_parent')
            ->orderByDesc('sequence_number')
            ->first();

        if ($lastEvent === null) {
            return false;
        }

        $value = $lastEvent->machine_value;

        // Check if region_a has completed (async child finished)
        $regionA = collect($value)->first(fn (string $v): bool => str_contains($v, 'region_a'));

        return $regionA !== null && str_contains($regionA, 'completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Async child delegation in parallel region did not complete via Horizon');
});
