<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

describe('Archive Concurrency Safety', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled' => true,
            'machine.archival.level'   => 6,
        ]);
    });

    it('handles multiple rapid event creations for same archived machine', function (): void {
        $rootEventId = (string) Str::ulid();
        $machineId   = 'concurrency_test_machine';

        // Create and archive an event
        $archivedEvent = new MachineEvent([
            'id'              => (string) Str::ulid(),
            'sequence_number' => 1,
            'created_at'      => now()->subDays(60),
            'machine_id'      => $machineId,
            'machine_value'   => ['concurrency_test_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => ['data' => 'archived'],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        MachineEventArchive::archiveEvents(new EventCollection([$archivedEvent]));

        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();

        // Create multiple events rapidly (simulating concurrent requests)
        $eventIds = [];
        for ($i = 2; $i <= 5; $i++) {
            $event = MachineEvent::create([
                'id'              => (string) Str::ulid(),
                'sequence_number' => $i,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ["concurrency_test_machine.step{$i}"],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => "EVENT_{$i}",
                'payload'         => ['step' => $i],
                'context'         => [],
                'meta'            => null,
                'version'         => 1,
            ]);
            $eventIds[] = $event->id;
        }

        // Archive should be deleted
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();

        // All events should exist (1 restored + 4 new)
        $allEvents = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        expect($allEvents)->toHaveCount(5);
        expect($allEvents->first()->type)->toBe('START');
    });

    it('does not fail when restoreAndDelete is called for non-existent archive', function (): void {
        $rootEventId = (string) Str::ulid();

        $service = new ArchiveService();

        // Should return false, not throw
        $result = $service->restoreAndDelete($rootEventId);

        expect($result)->toBeFalse();
    });

    it('handles event creation when no archive exists', function (): void {
        $rootEventId = (string) Str::ulid();
        $machineId   = 'no_archive_machine';

        // No archive exists - event creation should work normally
        $event = MachineEvent::create([
            'id'              => (string) Str::ulid(),
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['no_archive_machine.initial'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => [],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        expect($event->exists)->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(1);
    });

    it('handles event creation without root_event_id', function (): void {
        // Some edge cases may have null root_event_id - should not trigger restore
        $event = MachineEvent::create([
            'id'              => (string) Str::ulid(),
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'null_root_machine',
            'machine_value'   => ['null_root_machine.initial'],
            'root_event_id'   => null,
            'source'          => SourceType::INTERNAL,
            'type'            => 'START',
            'payload'         => [],
            'context'         => [],
            'meta'            => null,
            'version'         => 1,
        ]);

        expect($event->exists)->toBeTrue();
    });
});
