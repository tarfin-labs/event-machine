<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.threshold'              => 100,
        'machine.archival.days_inactive'          => 1,
        'machine.archival.restore_cooldown_hours' => 0,
    ]);

    // Ensure archive table is clean
    if (DB::getSchemaBuilder()->hasTable('machine_event_archives')) {
        DB::table('machine_event_archives')->truncate();
    }
});

// ═══════════════════════════════════════════════════════════════
//  Event Archival System — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: archive-events command archives old machines via Horizon fan-out', function (): void {
    // Create 3 machines and persist
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $m = AsyncParentMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    // Backdate all events to 2+ days ago (past days_inactive=1)
    DB::table('machine_events')->update([
        'created_at' => now()->subDays(2),
    ]);

    // Run archive command (sync mode for deterministic test)
    Artisan::call('machine:archive-events', ['--sync' => true]);

    // Assert: archives created for all 3 machines
    foreach ($ids as $rootEventId) {
        $archive = MachineEventArchive::where('root_event_id', $rootEventId)->first();
        expect($archive)->not->toBeNull("Archive not created for {$rootEventId}");
        expect($archive->event_count)->toBeGreaterThan(0);
        expect($archive->compressed_size)->toBeGreaterThan(0);
    }

    // Assert: events removed from active table
    foreach ($ids as $rootEventId) {
        $activeCount = DB::table('machine_events')
            ->where('root_event_id', $rootEventId)
            ->count();
        expect($activeCount)->toBe(0);
    }
});

it('LocalQA: archived machine auto-restores when new event arrives', function (): void {
    // Create machine, persist, then archive
    $machine = AsyncParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $originalEventCount = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)->count();

    $archiveService = new ArchiveService();
    $archive        = $archiveService->archiveMachine($rootEventId);
    expect($archive)->not->toBeNull();

    // Verify: events gone from active table, archive exists
    expect(DB::table('machine_events')->where('root_event_id', $rootEventId)->count())->toBe(0);
    expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();

    // Send new event via SendToMachineJob → triggers auto-restore via MachineEvent::creating()
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for machine to transition (auto-restore + event processing)
    $processed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 60, description: 'archived machine auto-restore after SendToMachineJob');

    expect($processed)->toBeTrue('Archived machine did not auto-restore and transition');

    // Assert: events restored + new events present (more than original)
    $eventCount = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($eventCount)->toBeGreaterThan($originalEventCount);
});

it('LocalQA: concurrent event arrival during archival does not lose events', function (): void {
    // Create machine, persist
    $machine = AsyncParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Archive the machine
    $archiveService = new ArchiveService();
    $archiveService->archiveMachine($rootEventId);

    // Dispatch TWO events simultaneously — both trigger auto-restore
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'ADVANCE'],
    );

    // Wait for at least one event to process
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        // Machine should have transitioned to some non-idle state
        return $cs && !str_contains($cs->state_id, 'idle');
    }, timeoutSeconds: 60, description: 'concurrent events during archival: waiting for state change');

    expect($settled)->toBeTrue('Machine did not process any events after archival');

    // Machine should have correct state (events restored + processed)
    $events = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($events)->toBeGreaterThan(0);

    // No failed jobs from race conditions
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});
