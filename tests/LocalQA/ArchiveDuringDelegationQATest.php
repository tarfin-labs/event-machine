<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Jobs\ChildMachineCompletionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.threshold'              => 100,
        'machine.archival.days_inactive'          => 0,
        'machine.archival.restore_cooldown_hours' => 0,
    ]);

    // Ensure archive table is clean
    if (DB::getSchemaBuilder()->hasTable('machine_event_archives')) {
        DB::table('machine_event_archives')->truncate();
    }
});

// ═══════════════════════════════════════════════════════════════
//  Archive during active child delegation
// ═══════════════════════════════════════════════════════════════

it('LocalQA: archived parent auto-restores when ChildMachineCompletionJob fires', function (): void {
    // Scenario:
    // 1. Parent delegates to child via queue (ChildMachineJob)
    // 2. Child starts in idle, waits for external COMPLETE
    // 3. While child is waiting, parent gets archived
    // 4. We dispatch ChildMachineCompletionJob directly (simulating child completion)
    // 5. ChildMachineCompletionJob catches RestoringStateException → auto-restores → routes @done
    //
    // Bug found: ChildMachineCompletionJob caught ALL Throwables and silently discarded.
    // Fix: Catch RestoringStateException specifically and attempt archive auto-restore.
    $parent = AsyncParentMachine::create();
    $parent->send(['type' => 'START']);
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to be created and persisted by ChildMachineJob
    $childRootEventId = null;

    $childReady = LocalQATestCase::waitFor(function () use ($parentRootEventId, &$childRootEventId) {
        $childRecord = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();
        if (!$childRecord || !$childRecord->child_root_event_id) {
            return false;
        }

        $childRootEventId = $childRecord->child_root_event_id;

        return MachineCurrentState::where('root_event_id', $childRootEventId)->exists();
    }, timeoutSeconds: 60, description: 'archive+delegation: child machine created and persisted');

    expect($childReady)->toBeTrue('Child machine was not created or persisted');

    // Archive the PARENT while child is still running (in idle state)
    $archiveService = new ArchiveService();
    $archive        = $archiveService->archiveMachine($parentRootEventId);
    expect($archive)->not->toBeNull('Parent could not be archived');

    // Verify parent events are gone from active table
    $parentActiveEvents = DB::table('machine_events')
        ->where('root_event_id', $parentRootEventId)
        ->count();
    expect($parentActiveEvents)->toBe(0, 'Parent events still in active table after archive');

    // Verify archive exists
    expect(MachineEventArchive::where('root_event_id', $parentRootEventId)->exists())->toBeTrue();

    // Dispatch ChildMachineCompletionJob directly — simulating child reaching final state.
    // This is the exact job that ChildMachineJob would dispatch if child completed.
    // The parent is archived, so ChildMachineCompletionJob must auto-restore it.
    dispatch(new ChildMachineCompletionJob(
        parentRootEventId: $parentRootEventId,
        parentMachineClass: AsyncParentMachine::class,
        parentStateId: 'async_parent.processing',
        childMachineClass: SimpleChildMachine::class,
        childRootEventId: $childRootEventId,
        success: true,
        childContextData: [],
        childFinalState: 'done',
    ));

    // Wait for parent to reach completed state (auto-restored + @done processed)
    $parentCompleted = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        // Check if parent events have been restored (auto-restore)
        $eventCount = DB::table('machine_events')
            ->where('root_event_id', $parentRootEventId)
            ->count();

        if ($eventCount === 0) {
            return false;
        }

        $cs = MachineCurrentState::where('root_event_id', $parentRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'archive+delegation: parent auto-restores after ChildMachineCompletionJob');

    expect($parentCompleted)->toBeTrue(
        'Archived parent did not auto-restore and reach completed after ChildMachineCompletionJob'
    );

    // Verify parent is in completed state
    $restored = AsyncParentMachine::create(state: $parentRootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('async_parent.completed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $parentRootEventId)->count();
    expect($locks)->toBe(0);

    // No failed jobs — auto-restore should prevent job failure
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Archive + delegation caused failed jobs');
});
