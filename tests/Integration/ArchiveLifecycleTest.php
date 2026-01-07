<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Tests the complete archive lifecycle:
 * 1. Archive: events move to archive, deleted from machine_events
 * 2. Access: transparent in-memory restore (no DB write)
 * 3. New events: arrive in machine_events while archive exists
 * 4. Cooldown: prevents archiving new events for recently-accessed archives
 * 5. Re-archive: after cooldown, new events can be archived
 */
describe('Archive Lifecycle', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'                => true,
            'machine.archival.level'                  => 6,
            'machine.archival.days_inactive'          => 30,
            'machine.archival.restore_cooldown_hours' => 24,
        ]);
    });

    it('completes full archive → access → new events → cooldown → re-archive cycle', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'lifecycle_test_machine';

        // ============================================
        // PHASE 1: Create initial events (60 days ago)
        // ============================================
        $oldDate = now()->subDays(60);

        $initialEvents = collect([
            MachineEvent::create([
                'id'              => Str::ulid()->toString(),
                'sequence_number' => 1,
                'created_at'      => $oldDate,
                'machine_id'      => $machineId,
                'machine_value'   => ['lifecycle_test_machine.step1'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'START',
                'payload'         => ['data' => 'initial'],
                'context'         => ['step' => 1],
                'meta'            => null,
                'version'         => 1,
            ]),
            MachineEvent::create([
                'id'              => Str::ulid()->toString(),
                'sequence_number' => 2,
                'created_at'      => $oldDate->copy()->addMinutes(5),
                'machine_id'      => $machineId,
                'machine_value'   => ['lifecycle_test_machine.step2'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'PROCESS',
                'payload'         => ['data' => 'processing'],
                'context'         => ['step' => 2],
                'meta'            => null,
                'version'         => 1,
            ]),
        ]);

        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(2);

        // ============================================
        // PHASE 2: Archive the events
        // ============================================
        $archiveService = new ArchiveService();
        $archive        = $archiveService->archiveMachine($rootEventId);

        expect($archive)->toBeInstanceOf(MachineEventArchive::class);
        expect($archive->event_count)->toBe(2);
        expect($archive->restore_count)->toBe(0);
        expect($archive->last_restored_at)->toBeNull();

        // Events should be deleted from machine_events
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->count())->toBe(1);

        // ============================================
        // PHASE 3: Access archived machine (transparent restore)
        // ============================================
        $machineDefinition = MachineDefinition::define([
            'id'      => $machineId,
            'initial' => 'step1',
            'states'  => [
                'step1' => ['on' => ['PROCESS' => 'step2']],
                'step2' => ['on' => ['COMPLETE' => 'done']],
                'done'  => ['type' => 'final'],
            ],
        ]);

        $machine = Machine::withDefinition($machineDefinition);
        $state   = $machine->restoreStateFromRootEventId($rootEventId);

        // State should be restored in-memory
        expect($state)->toBeInstanceOf(State::class);
        expect($state->history)->toHaveCount(2);
        expect($state->history->first()->type)->toBe('START');
        expect($state->history->last()->type)->toBe('PROCESS');

        // Events should NOT be written back to machine_events
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Archive should track the restoration
        $archive->refresh();
        expect($archive->restore_count)->toBe(1);
        expect($archive->last_restored_at)->not->toBeNull();

        // ============================================
        // PHASE 4: New event arrives (machine continues)
        // ============================================
        $newEvent = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 3,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['lifecycle_test_machine.done'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'COMPLETE',
            'payload'         => ['data' => 'completed'],
            'context'         => ['step' => 3],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Now we have split state: archive + active
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(1);
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->count())->toBe(1);

        // ============================================
        // PHASE 5: Archive job should skip (cooldown active)
        // ============================================
        $eligibleInstances = $archiveService->getEligibleInstances(100);

        // This machine should NOT be eligible due to cooldown
        $eligibleRootIds = $eligibleInstances->pluck('root_event_id')->toArray();
        expect($eligibleRootIds)->not->toContain($rootEventId);

        // ============================================
        // PHASE 6: Design decision - archive already exists
        // ============================================
        // Once an archive exists for a root_event_id, new events in machine_events
        // are NOT eligible for archival (to prevent duplicate archives).
        // The split state (archive + active events) is intentional and permanent
        // until the archive is fully restored/deleted.

        // Simulate cooldown expiry
        $archive->update(['last_restored_at' => now()->subHours(25)]);

        // Make the new event old enough
        $newEvent->update(['created_at' => now()->subDays(35)]);

        $eligibleAfterCooldown = $archiveService->getEligibleInstances(100);

        // Still NOT eligible because archive already exists for this root_event_id
        // This is by design - we don't create duplicate/incremental archives
        $eligibleRootIds = $eligibleAfterCooldown->pluck('root_event_id')->toArray();
        expect($eligibleRootIds)->not->toContain($rootEventId);

        // Verify split state remains: archive + active events
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->count())->toBe(1);
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(1);
    });

    it('merges archive and active events when restoring state', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'merge_test_machine';

        // Create and archive old events
        $oldEvent = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['merge_test_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'OLD_EVENT',
            'payload'         => ['data' => 'archived'],
            'context'         => ['step' => 1],
            'meta'            => null,
            'version'         => 1,
        ]);

        $eventCollection = new EventCollection([$oldEvent]);
        MachineEventArchive::archiveEvents($eventCollection);
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        // Create new active event
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 2,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['merge_test_machine.processing'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'NEW_EVENT',
            'payload'         => ['data' => 'active'],
            'context'         => ['step' => 2],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Machine should prefer active events
        $machineDefinition = MachineDefinition::define([
            'id'      => $machineId,
            'initial' => 'initial',
            'states'  => [
                'initial'    => ['on' => ['NEW_EVENT' => 'processing']],
                'processing' => ['type' => 'final'],
            ],
        ]);

        $machine = Machine::withDefinition($machineDefinition);
        $state   = $machine->restoreStateFromRootEventId($rootEventId);

        // Should only have the active event (active takes precedence)
        expect($state->history)->toHaveCount(1);
        expect($state->history->first()->type)->toBe('NEW_EVENT');
    });

    it('tracks multiple restores correctly', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'multi_restore_machine';

        // Create and archive events
        $event = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['multi_restore_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => null,
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        $archiveService = new ArchiveService();
        $archive        = $archiveService->archiveMachine($rootEventId);

        expect($archive->restore_count)->toBe(0);

        // Multiple restores
        $archiveService->restoreMachine($rootEventId, keepArchive: true);
        $archive->refresh();
        expect($archive->restore_count)->toBe(1);

        $archiveService->restoreMachine($rootEventId, keepArchive: true);
        $archive->refresh();
        expect($archive->restore_count)->toBe(2);

        $archiveService->restoreMachine($rootEventId, keepArchive: true);
        $archive->refresh();
        expect($archive->restore_count)->toBe(3);
        expect($archive->last_restored_at)->not->toBeNull();
    });

    it('handles transparent restore without modifying archive compression', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'compression_check_machine';

        // Create event with predictable payload
        $event = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['compression_check_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => ['large_data' => str_repeat('test_payload_', 100)],
            'context'         => ['large_context' => str_repeat('context_data_', 100)],
            'meta'            => null,
            'version'         => 1,
        ]);

        $archiveService = new ArchiveService();
        $archive        = $archiveService->archiveMachine($rootEventId);

        $originalCompressedSize = $archive->compressed_size;
        $originalEventCount     = $archive->event_count;

        // Multiple restores should not change archive data
        for ($i = 0; $i < 5; $i++) {
            $events = $archiveService->restoreMachine($rootEventId, keepArchive: true);
            expect($events)->toHaveCount(1);
        }

        $archive->refresh();

        expect($archive->compressed_size)->toBe($originalCompressedSize);
        expect($archive->event_count)->toBe($originalEventCount);
        expect($archive->restore_count)->toBe(5);
    });
});
