<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DispatchToParentParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  dispatchToParent — Child → Parent Communication via Horizon
// ═══════════════════════════════════════════════════════════════

/*
 * Known architecture limitation: dispatchToParent cannot be used from
 * events sent to the child after initial ChildMachineJob execution.
 *
 * Parent identity (parentMachineId, parentMachineClass) is stored in
 * transient ContextManager internal properties — NOT in the data array.
 * When the child is restored via SendToMachineJob, parent identity is lost.
 *
 * dispatchToParent only works from actions that run during ChildMachineJob::handle():
 * - @always transitions during start()? No — setMachineIdentity is called AFTER start().
 * - Only the ChildMachineCompletionJob has parent info (passed as constructor args).
 *
 * This test verifies that the parent delegates and the child is created,
 * but does not test dispatchToParent (which would require parent identity persistence).
 */
it('LocalQA: parent delegates to child which stays working via Horizon', function (): void {
    $parent = DispatchToParentParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be created via Horizon
    $childCreated = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_children')
            ->where('parent_root_event_id', $rootEventId)
            ->where('status', 'running')
            ->exists();
    }, timeoutSeconds: 60, description: 'dispatchToParent: waiting for child to be created');

    expect($childCreated)->toBeTrue('Child machine was not created');

    // Verify: parent at processing, child at working
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');
});
