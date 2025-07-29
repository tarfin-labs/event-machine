<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

describe('MachineEventArchive', function (): void {
    beforeEach(function (): void {
        // Enable archival for tests
        config([
            'machine.archival.enabled'   => true,
            'machine.archival.level'     => 6,
            'machine.archival.threshold' => 100,
        ]);
    });

    it('can archive a collection of machine events', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create test events
        $events = collect([
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'sequence_number' => 1,
                'created_at'      => now()->subMinutes(5),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'initial'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'machine.start',
                'payload'         => ['data' => str_repeat('test_data_', 20)],
                'context'         => ['user' => 'test'],
                'meta'            => ['debug' => 'info'],
                'version'         => 1,
            ]),
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DN',
                'sequence_number' => 2,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'processing'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'machine.process',
                'payload'         => ['data' => str_repeat('process_data_', 20)],
                'context'         => ['user' => 'test'],
                'meta'            => ['debug' => 'processing'],
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());

        // Archive the events
        $archive = MachineEventArchive::archiveEvents($eventCollection);

        expect($archive)->toBeInstanceOf(MachineEventArchive::class);
        expect($archive->root_event_id)->toBe($rootEventId);
        expect($archive->machine_id)->toBe($machineId);
        expect($archive->event_count)->toBe(2);
        expect($archive->compression_level)->toBe(6);
        expect($archive->original_size)->toBeGreaterThan(0);
        expect($archive->compressed_size)->toBeGreaterThan(0);
        expect($archive->compressed_size)->toBeLessThan($archive->original_size);
    });

    it('can restore archived events to a collection', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create and archive events
        $originalEvents = collect([
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'sequence_number' => 1,
                'created_at'      => now()->subMinutes(5),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'initial'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'machine.start',
                'payload'         => ['data' => 'test_payload'],
                'context'         => ['user' => 'test'],
                'meta'            => ['debug' => 'info'],
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($originalEvents->all());
        $archive         = MachineEventArchive::archiveEvents($eventCollection);

        // Restore events
        $restoredEvents = $archive->restoreEvents();

        expect($restoredEvents)->toBeInstanceOf(EventCollection::class);
        expect($restoredEvents)->toHaveCount(1);

        $restoredEvent = $restoredEvents->first();
        expect($restoredEvent->id)->toBe('01H8BM4VK82JKPK7RPR3YGT2DM');
        expect($restoredEvent->machine_id)->toBe($machineId);
        expect($restoredEvent->payload)->toEqual(['data' => 'test_payload']);
        expect($restoredEvent->context)->toEqual(['user' => 'test']);
        expect($restoredEvent->meta)->toEqual(['debug' => 'info']);
    });

    it('calculates compression statistics correctly', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create events with known data sizes
        $events = collect([
            MachineEvent::create([
                'id'              => $rootEventId,
                'sequence_number' => 1,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'test'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'test.event',
                'payload'         => ['data' => str_repeat('large_payload_data_', 50)],
                'context'         => ['context' => str_repeat('large_context_data_', 30)],
                'meta'            => ['meta' => str_repeat('large_meta_data_', 20)],
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());
        $archive         = MachineEventArchive::archiveEvents($eventCollection);

        // Test compression ratio
        expect($archive->compression_ratio)->toBeLessThan(1.0);
        expect($archive->compression_ratio)->toBeGreaterThan(0.0);

        // Test savings percentage
        expect($archive->savings_percent)->toBeGreaterThan(0.0);
        expect($archive->savings_percent)->toBeLessThan(100.0);

        // Test that compressed size is indeed smaller
        expect($archive->compressed_size)->toBeLessThan($archive->original_size);
    });

    it('handles custom compression levels', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        $events = collect([
            MachineEvent::create([
                'id'              => $rootEventId,
                'sequence_number' => 1,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'test'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'test.event',
                'payload'         => ['data' => str_repeat('test_data_', 100)],
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());

        // Test with compression level 1 (fast)
        $archiveFast = MachineEventArchive::archiveEvents($eventCollection, 1);
        expect($archiveFast->compression_level)->toBe(1);

        // Create different events for level 9 test to avoid unique constraint violation
        $rootEventId2 = '01H8BM4VK82JKPK7RPR3YGT2DN';
        $events2      = collect([
            MachineEvent::create([
                'id'              => $rootEventId2,
                'sequence_number' => 1,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ['state' => 'test'],
                'root_event_id'   => $rootEventId2,
                'source'          => SourceType::INTERNAL,
                'type'            => 'test.event',
                'payload'         => ['data' => str_repeat('test_data_', 100)],
                'version'         => 1,
            ]),
        ]);
        $eventCollection2 = new EventCollection($events2->all());

        // Test with compression level 9 (best)
        $archiveBest = MachineEventArchive::archiveEvents($eventCollection2, 9);
        expect($archiveBest->compression_level)->toBe(9);

        // Level 9 should generally achieve better compression than level 1
        expect($archiveBest->compression_ratio)->toBeLessThanOrEqual($archiveFast->compression_ratio);
    });

    it('throws exception when archiving empty collection', function (): void {
        $emptyCollection = new EventCollection([]);

        expect(fn () => MachineEventArchive::archiveEvents($emptyCollection))
            ->toThrow(InvalidArgumentException::class, 'Cannot archive empty event collection');
    });

    it('can query archives by machine ID', function (): void {
        $machineId1 = 'machine_1';
        $machineId2 = 'machine_2';

        // Create archives for different machines
        $events1 = new EventCollection([
            MachineEvent::factory()->create([
                'machine_id'    => $machineId1,
                'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DM',
            ]),
        ]);

        $events2 = new EventCollection([
            MachineEvent::factory()->create([
                'machine_id'    => $machineId2,
                'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DN',
            ]),
        ]);

        MachineEventArchive::archiveEvents($events1);
        MachineEventArchive::archiveEvents($events2);

        // Test scope filtering
        $machine1Archives = MachineEventArchive::forMachine($machineId1)->get();
        $machine2Archives = MachineEventArchive::forMachine($machineId2)->get();

        expect($machine1Archives)->toHaveCount(1);
        expect($machine2Archives)->toHaveCount(1);
        expect($machine1Archives->first()->machine_id)->toBe($machineId1);
        expect($machine2Archives->first()->machine_id)->toBe($machineId2);
    });

    it('can query archives by date range', function (): void {
        $now     = now();
        $oldDate = $now->copy()->subDays(5);

        // Create archive with old date
        $events = new EventCollection([
            MachineEvent::factory()->create([
                'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DM',
            ]),
        ]);

        $archive = MachineEventArchive::archiveEvents($events);
        $archive->update(['archived_at' => $oldDate]);

        // Test date range filtering
        $recentArchives = MachineEventArchive::archivedBetween($now->copy()->subDay(), $now)->get();
        $oldArchives    = MachineEventArchive::archivedBetween($oldDate->copy()->subDay(), $oldDate->copy()->addDay())->get();

        expect($recentArchives)->toHaveCount(0);
        expect($oldArchives)->toHaveCount(1);
    });
});
