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
 * Tests for auto-restore feature:
 * When new events are saved to machine_events for a root_event_id
 * that has an archive, automatically restore events from archive
 * to machine_events and delete the archive.
 */
describe('Auto-Restore on Event Save', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'                => true,
            'machine.archival.level'                  => 6,
            'machine.archival.days_inactive'          => 30,
            'machine.archival.restore_cooldown_hours' => 24,
        ]);
    });

    it('automatically restores archived events when new event is saved', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'auto_restore_test_machine';

        // Create an archived event (simulate old archived machine)
        $archivedEvent = new MachineEvent([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['auto_restore_test_machine.step1'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => ['data' => 'archived'],
            'context'         => ['step' => 1],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Archive it directly
        $eventCollection = new EventCollection([$archivedEvent]);
        $archive         = MachineEventArchive::archiveEvents($eventCollection);

        // Verify archive exists, no events in machine_events
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Now save a new event for the same root_event_id
        // This should trigger auto-restore
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 2,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['auto_restore_test_machine.step2'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'CONTINUE',
            'payload'         => ['data' => 'new'],
            'context'         => ['step' => 2],
            'meta'            => null,
            'version'         => 1,
        ]);

        // After auto-restore:
        // 1. Archive should be deleted
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

        // 2. Both events should be in machine_events (restored + new)
        $events = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        expect($events)->toHaveCount(2);
        expect($events[0]->type)->toBe('START');
        expect($events[0]->sequence_number)->toBe(1);
        expect($events[1]->type)->toBe('CONTINUE');
        expect($events[1]->sequence_number)->toBe(2);
    });

    it('does nothing when no archive exists for root_event_id', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'no_archive_test_machine';

        // Verify no archive exists
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

        // Create a new event - should work normally without any restore
        $event = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['no_archive_test_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => ['data' => 'test'],
            'context'         => ['step' => 1],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Event should be created normally
        expect($event->exists)->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(1);

        // Still no archive
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();
    });

    it('restores only once when multiple events are saved in batch for same root_event_id', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'batch_test_machine';

        // Create and archive initial events
        $archivedEvents = collect([
            new MachineEvent([
                'id'              => Str::ulid()->toString(),
                'sequence_number' => 1,
                'created_at'      => now()->subDays(60),
                'machine_id'      => $machineId,
                'machine_value'   => ['batch_test_machine.step1'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'STEP1',
                'payload'         => ['data' => 'first'],
                'context'         => ['step' => 1],
                'meta'            => null,
                'version'         => 1,
            ]),
            new MachineEvent([
                'id'              => Str::ulid()->toString(),
                'sequence_number' => 2,
                'created_at'      => now()->subDays(60)->addMinutes(1),
                'machine_id'      => $machineId,
                'machine_value'   => ['batch_test_machine.step2'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'STEP2',
                'payload'         => ['data' => 'second'],
                'context'         => ['step' => 2],
                'meta'            => null,
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($archivedEvents->all());
        MachineEventArchive::archiveEvents($eventCollection);

        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Now create multiple new events one by one (simulating batch)
        // First event triggers restore, subsequent events should not
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 3,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['batch_test_machine.step3'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'STEP3',
            'payload'         => ['data' => 'third'],
            'context'         => ['step' => 3],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Archive should be deleted after first event
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

        // Create second new event - should not try to restore (archive already deleted)
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 4,
            'created_at'      => now()->addSecond(),
            'machine_id'      => $machineId,
            'machine_value'   => ['batch_test_machine.step4'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'STEP4',
            'payload'         => ['data' => 'fourth'],
            'context'         => ['step' => 4],
            'meta'            => null,
            'version'         => 1,
        ]);

        // All events should be present: 2 restored + 2 new
        $allEvents = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        expect($allEvents)->toHaveCount(4);
        expect($allEvents[0]->type)->toBe('STEP1');
        expect($allEvents[1]->type)->toBe('STEP2');
        expect($allEvents[2]->type)->toBe('STEP3');
        expect($allEvents[3]->type)->toBe('STEP4');
    });

    it('handles different root_event_ids independently', function (): void {
        $rootEventId1 = Str::ulid()->toString();
        $rootEventId2 = Str::ulid()->toString();
        $machineId    = 'independent_test_machine';

        // Archive events for root_event_id_1 only
        $archivedEvent = new MachineEvent([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['independent_test_machine.archived'],
            'root_event_id'   => $rootEventId1,
            'source'          => SourceType::INTERNAL,
            'type'            => 'ARCHIVED_EVENT',
            'payload'         => ['data' => 'archived'],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        MachineEventArchive::archiveEvents(new EventCollection([$archivedEvent]));

        expect(MachineEventArchive::where('root_event_id', $rootEventId1)->exists())->toBeTrue();
        expect(MachineEventArchive::where('root_event_id', $rootEventId2)->exists())->toBeFalse();

        // Create event for root_event_id_2 (no archive) - should work normally
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['independent_test_machine.new'],
            'root_event_id'   => $rootEventId2,
            'source'          => SourceType::INTERNAL,
            'type'            => 'NEW_EVENT_2',
            'payload'         => ['data' => 'new'],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        // root_event_id_1 archive should still exist
        expect(MachineEventArchive::where('root_event_id', $rootEventId1)->exists())->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId2)->count())->toBe(1);

        // Now create event for root_event_id_1 - should trigger restore
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 2,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['independent_test_machine.continued'],
            'root_event_id'   => $rootEventId1,
            'source'          => SourceType::INTERNAL,
            'type'            => 'NEW_EVENT_1',
            'payload'         => ['data' => 'new_for_1'],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        // root_event_id_1 archive should be deleted, events restored
        expect(MachineEventArchive::where('root_event_id', $rootEventId1)->exists())->toBeFalse();
        expect(MachineEvent::where('root_event_id', $rootEventId1)->count())->toBe(2);

        // root_event_id_2 should be unchanged
        expect(MachineEvent::where('root_event_id', $rootEventId2)->count())->toBe(1);
    });
});

/*
 * Full lifecycle integration test:
 * Archive → Transparent Restore → New Event → Auto-Restore → Re-Archive eligible
 */
describe('Auto-Restore Full Lifecycle', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'                => true,
            'machine.archival.level'                  => 6,
            'machine.archival.days_inactive'          => 30,
            'machine.archival.restore_cooldown_hours' => 24,
        ]);
    });

    it('completes full lifecycle: archive → transparent read → new event → auto-restore → re-archive eligible', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'full_lifecycle_machine';

        // ============================================
        // PHASE 1: Create initial events (60 days ago)
        // ============================================
        $oldDate = now()->subDays(60);

        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => $oldDate,
            'machine_id'      => $machineId,
            'machine_value'   => ['full_lifecycle_machine.step1'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => ['data' => 'initial'],
            'context'         => ['step' => 1],
            'meta'            => null,
            'version'         => 1,
        ]);

        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 2,
            'created_at'      => $oldDate->copy()->addMinutes(5),
            'machine_id'      => $machineId,
            'machine_value'   => ['full_lifecycle_machine.step2'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'PROCESS',
            'payload'         => ['data' => 'processing'],
            'context'         => ['step' => 2],
            'meta'            => null,
            'version'         => 1,
        ]);

        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(2);

        // ============================================
        // PHASE 2: Archive the events
        // ============================================
        $archiveService = new ArchiveService();
        $archive        = $archiveService->archiveMachine($rootEventId);

        expect($archive)->toBeInstanceOf(MachineEventArchive::class);
        expect($archive->event_count)->toBe(2);
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // ============================================
        // PHASE 3: Transparent restore (read-only, no DB write)
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

        // Events should NOT be in machine_events (transparent restore)
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Archive should still exist with restore tracking
        $archive->refresh();
        expect($archive->restore_count)->toBe(1);
        expect($archive->last_restored_at)->not->toBeNull();

        // ============================================
        // PHASE 4: New event arrives → Auto-restore triggered
        // ============================================
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 3,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['full_lifecycle_machine.done'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'COMPLETE',
            'payload'         => ['data' => 'completed'],
            'context'         => ['step' => 3],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Auto-restore should have:
        // 1. Restored archived events to machine_events
        // 2. Deleted the archive
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

        $allEvents = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        expect($allEvents)->toHaveCount(3);
        expect($allEvents[0]->type)->toBe('START');
        expect($allEvents[1]->type)->toBe('PROCESS');
        expect($allEvents[2]->type)->toBe('COMPLETE');

        // ============================================
        // PHASE 5: Verify re-archive eligibility
        // ============================================
        // Make all events old enough for archival
        MachineEvent::where('root_event_id', $rootEventId)
            ->update(['created_at' => now()->subDays(35)]);

        // Should be eligible for archival again
        $eligibleInstances = $archiveService->getEligibleInstances(100);
        $eligibleRootIds   = $eligibleInstances->pluck('root_event_id')->toArray();

        expect($eligibleRootIds)->toContain($rootEventId);

        // ============================================
        // PHASE 6: Re-archive and verify
        // ============================================
        $newArchive = $archiveService->archiveMachine($rootEventId);

        expect($newArchive)->toBeInstanceOf(MachineEventArchive::class);
        expect($newArchive->event_count)->toBe(3); // All 3 events now
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();
    });

    it('preserves event data integrity through archive → auto-restore cycle', function (): void {
        $rootEventId = Str::ulid()->toString();
        $machineId   = 'data_integrity_machine';

        // Create event with complex payload
        $complexPayload = [
            'nested' => [
                'array'   => [1, 2, 3],
                'object'  => ['key' => 'value'],
                'unicode' => '日本語テスト',
                'special' => "line1\nline2\ttab",
            ],
            'numbers' => [
                'int'   => 42,
                'float' => 3.14159,
                'zero'  => 0,
            ],
            'booleans' => [
                'true'  => true,
                'false' => false,
            ],
            'null_value' => null,
        ];

        $complexContext = [
            'user_id'  => 12345,
            'session'  => 'abc-xyz-123',
            'metadata' => ['source' => 'test', 'version' => '1.0'],
        ];

        $originalEvent = MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['data_integrity_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'COMPLEX_EVENT',
            'payload'         => $complexPayload,
            'context'         => $complexContext,
            'meta'            => ['trace_id' => 'trace-123'],
            'version'         => 1,
        ]);

        $originalId       = $originalEvent->id;
        $originalSequence = $originalEvent->sequence_number;

        // Archive
        $archiveService = new ArchiveService();
        $archiveService->archiveMachine($rootEventId);

        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Trigger auto-restore by creating new event
        MachineEvent::create([
            'id'              => Str::ulid()->toString(),
            'sequence_number' => 2,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['data_integrity_machine.next'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'TRIGGER',
            'payload'         => ['trigger' => true],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        // Verify restored event data integrity
        $restoredEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->where('sequence_number', 1)
            ->first();

        expect($restoredEvent)->not->toBeNull();
        expect($restoredEvent->id)->toBe($originalId);
        expect($restoredEvent->sequence_number)->toBe($originalSequence);
        expect($restoredEvent->type)->toBe('COMPLEX_EVENT');
        expect($restoredEvent->payload)->toBe($complexPayload);
        expect($restoredEvent->context)->toBe($complexContext);
        expect($restoredEvent->meta)->toBe(['trace_id' => 'trace-123']);
        expect($restoredEvent->machine_value)->toBe(['data_integrity_machine.initial']);
    });
});
