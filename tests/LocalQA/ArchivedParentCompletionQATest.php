<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.threshold'              => 100,
        'machine.archival.restore_cooldown_hours' => 0,
    ]);

    if (DB::getSchemaBuilder()->hasTable('machine_event_archives')) {
        DB::table('machine_event_archives')->truncate();
    }
});

// ═══════════════════════════════════════════════════════════════
//  Archive and Restore — Full Lifecycle via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: completed machine archives and restores correctly via Horizon', function (): void {
    // Create a machine that completes via async child delegation
    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for async child to complete the parent via Horizon
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'archive lifecycle: waiting for initial completion');

    expect($completed)->toBeTrue('Parent did not complete');

    $originalEventCount = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($originalEventCount)->toBeGreaterThan(3);

    // Archive the completed machine's events
    $archiveService = new ArchiveService();
    $archive        = $archiveService->archiveMachine($rootEventId);
    expect($archive)->not->toBeNull('Failed to archive parent');
    expect($archive->event_count)->toBe($originalEventCount);

    // Verify events are gone from active table
    expect(DB::table('machine_events')->where('root_event_id', $rootEventId)->count())->toBe(0);
    expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();

    // Restore the machine via ArchiveService
    $archiveService->restoreAndDelete($rootEventId);

    // Verify events are back and archive is deleted
    $restoredEventCount = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($restoredEventCount)->toBe($originalEventCount);
    expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

    // Machine state should still be intact after restore
    $restoredMachine = AsyncAutoCompleteParentMachine::create(state: $rootEventId);
    expect($restoredMachine->state->currentStateDefinition->id)->toContain('completed');
});
