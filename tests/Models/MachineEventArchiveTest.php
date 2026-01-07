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
        $firstTime   = now()->subMinutes(5);
        $lastTime    = now();

        // Create test events
        $events = collect([
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'sequence_number' => 1,
                'created_at'      => $firstTime,
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
                'created_at'      => $lastTime,
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
        expect($archive->original_size)->toBeGreaterThan(0);
        expect($archive->compressed_size)->toBeGreaterThan(0);
        expect($archive->compressed_size)->toBeLessThan($archive->original_size);

        // Verify metadata columns
        expect($archive->compression_level)->toBe(6);
        expect($archive->archived_at)->not->toBeNull();
        expect($archive->first_event_at->toDateTimeString())->toBe($firstTime->toDateTimeString());
        expect($archive->last_event_at->toDateTimeString())->toBe($lastTime->toDateTimeString());
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

    it('calculates compression ratio correctly', function (): void {
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

        // Test that compressed size is indeed smaller
        expect($archive->compressed_size)->toBeLessThan($archive->original_size);
    });

    it('throws exception when archiving empty collection', function (): void {
        $emptyCollection = new EventCollection([]);

        expect(fn () => MachineEventArchive::archiveEvents($emptyCollection))
            ->toThrow(InvalidArgumentException::class, 'Cannot archive empty event collection');
    });

    it('stores custom compression level', function (): void {
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

        // Archive with custom compression level
        $archive = MachineEventArchive::archiveEvents($eventCollection, 9);

        expect($archive->compression_level)->toBe(9);
    });
});
