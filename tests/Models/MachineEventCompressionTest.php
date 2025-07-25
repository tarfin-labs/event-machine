<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

describe('MachineEvent Compression', function (): void {
    beforeEach(function (): void {
        // Enable compression for tests
        config([
            'machine.compression.enabled'   => true,
            'machine.compression.level'     => 6,
            'machine.compression.fields'    => ['payload', 'context', 'meta'],
            'machine.compression.threshold' => 50,
        ]);
    });

    it('automatically compresses payload on save', function (): void {
        $largePayload = ['data' => str_repeat('test', 100)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'payload'         => $largePayload,
            'version'         => 1,
        ]);

        // Check that raw database value is compressed
        $rawPayload = $event->getAttributes()['payload'];
        expect(CompressionManager::isCompressed($rawPayload))->toBeTrue();

        // Check that accessor returns decompressed data
        expect($event->payload)->toEqual($largePayload);
    });

    it('automatically compresses context on save', function (): void {
        $largeContext = ['context' => str_repeat('test', 100)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'context'         => $largeContext,
            'version'         => 1,
        ]);

        // Check that raw database value is compressed
        $rawContext = $event->getAttributes()['context'];
        expect(CompressionManager::isCompressed($rawContext))->toBeTrue();

        // Check that accessor returns decompressed data
        expect($event->context)->toEqual($largeContext);
    });

    it('automatically compresses meta on save', function (): void {
        $largeMeta = ['meta' => str_repeat('test', 100)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'meta'            => $largeMeta,
            'version'         => 1,
        ]);

        // Check that raw database value is compressed
        $rawMeta = $event->getAttributes()['meta'];
        expect(CompressionManager::isCompressed($rawMeta))->toBeTrue();

        // Check that accessor returns decompressed data
        expect($event->meta)->toEqual($largeMeta);
    });

    it('does not compress small data below threshold', function (): void {
        $smallPayload = ['small' => 'data'];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'payload'         => $smallPayload,
            'version'         => 1,
        ]);

        // Check that raw database value is not compressed
        $rawPayload = $event->getAttributes()['payload'];
        expect(CompressionManager::isCompressed($rawPayload))->toBeFalse();

        // Check that accessor still returns correct data
        expect($event->payload)->toEqual($smallPayload);
    });

    it('handles null values correctly', function (): void {
        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'payload'         => null,
            'context'         => null,
            'meta'            => null,
            'version'         => 1,
        ]);

        expect($event->payload)->toBeNull();
        expect($event->context)->toBeNull();
        expect($event->meta)->toBeNull();
    });

    it('maintains backward compatibility with existing uncompressed data', function (): void {
        $data     = ['existing' => 'data'];
        $jsonData = json_encode($data);

        // Directly insert uncompressed JSON data (simulating existing data)
        $event = new MachineEvent([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'version'         => 1,
        ]);

        // Set raw attributes to simulate existing uncompressed data
        $event->setRawAttributes([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => json_encode(['state' => 'test']),
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'payload'         => $jsonData,
            'context'         => $jsonData,
            'meta'            => $jsonData,
            'version'         => 1,
        ]);

        // Should be able to read existing uncompressed data
        expect($event->payload)->toEqual($data);
        expect($event->context)->toEqual($data);
        expect($event->meta)->toEqual($data);
    });

    it('can update compressed data correctly', function (): void {
        $initialData = ['initial' => str_repeat('test', 100)];
        $updatedData = ['updated' => str_repeat('data', 100)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'test_machine',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.event',
            'payload'         => $initialData,
            'version'         => 1,
        ]);

        // Update the payload
        $event->update(['payload' => $updatedData]);

        // Reload from database
        $event->refresh();

        // Check that updated data is correct and compressed
        expect($event->payload)->toEqual($updatedData);
        $rawPayload = $event->getAttributes()['payload'];
        expect(CompressionManager::isCompressed($rawPayload))->toBeTrue();
    });

    it('handles edge case: empty arrays and objects', function (): void {
        $emptyData = [
            'empty_array'  => [],
            'empty_object' => [], // JSON conversion will make this an array anyway
            'null_value'   => null,
            'false_value'  => false,
            'zero_value'   => 0,
            'empty_string' => '',
        ];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DX',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'edge_case_test',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DX',
            'source'          => 'internal',
            'type'            => 'test.edge.event',
            'payload'         => $emptyData,
            'version'         => 1,
        ]);

        // Should handle empty data correctly (JSON roundtrip normalizes objects to arrays)
        expect($event->payload)->toEqual($emptyData);
    });

    it('handles concurrent updates correctly', function (): void {
        $initialData = ['counter' => 0, 'data' => str_repeat('test', 100)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DY',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'concurrent_test',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DY',
            'source'          => 'internal',
            'type'            => 'test.concurrent.event',
            'payload'         => $initialData,
            'version'         => 1,
        ]);

        // Simulate concurrent updates
        $event1 = MachineEvent::find($event->id);
        $event2 = MachineEvent::find($event->id);

        $event1->payload = ['counter' => 1, 'data' => str_repeat('test', 150)];
        $event2->payload = ['counter' => 2, 'data' => str_repeat('test', 200)];

        $event1->save();
        $event2->save();

        // Last update should win
        $finalEvent = MachineEvent::find($event->id);
        expect($finalEvent->payload['counter'])->toEqual(2);
    });

    it('handles compression when fields are disabled mid-operation', function (): void {
        // Start with compression enabled
        config(['machine.compression.fields' => ['payload', 'context', 'meta']]);

        $data  = ['test' => str_repeat('data', 100)];
        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DZ',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'field_disable_test',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DZ',
            'source'          => 'internal',
            'type'            => 'test.field.event',
            'payload'         => $data,
            'version'         => 1,
        ]);

        // Verify it was compressed
        expect(CompressionManager::isCompressed($event->getAttributes()['payload']))->toBeTrue();

        // Now disable compression for payload
        config(['machine.compression.fields' => ['context', 'meta']]);

        // Update should work even with changed config
        $newData = ['updated' => str_repeat('new', 100)];
        $event->update(['payload' => $newData]);

        expect($event->payload)->toEqual($newData);
    });
});
