<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

describe('Compression Integration Tests', function (): void {
    beforeEach(function (): void {
        // Enable compression for integration tests
        config([
            'machine.compression.enabled'   => true,
            'machine.compression.level'     => 6,
            'machine.compression.fields'    => ['payload', 'context', 'meta'],
            'machine.compression.threshold' => 50,
        ]);
    });

    it('automatically compresses and decompresses large MachineEvent data', function (): void {
        $largeContext = [
            'large_data' => str_repeat('test_data_for_compression_testing_', 50),
            'counter'    => 1,
            'metadata'   => [
                'processing_time' => time(),
                'large_array'     => array_fill(0, 100, 'data_item'),
            ],
        ];

        $largePayload = [
            'event_data' => str_repeat('payload_data_for_compression_', 30),
            'parameters' => array_fill(0, 50, 'param_value'),
        ];

        $largeMeta = [
            'debug_info' => str_repeat('debug_data_for_compression_', 20),
            'trace'      => array_fill(0, 30, 'trace_item'),
        ];

        // Create a MachineEvent with large data
        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'compression_integration_test',
            'machine_value'   => ['current_state' => 'test_state'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'source'          => 'internal',
            'type'            => 'test.compression.event',
            'payload'         => $largePayload,
            'context'         => $largeContext,
            'meta'            => $largeMeta,
            'version'         => 1,
        ]);

        // Verify data is compressed at storage level
        $rawContext = $event->getAttributes()['context'];
        $rawPayload = $event->getAttributes()['payload'];
        $rawMeta    = $event->getAttributes()['meta'];

        expect(CompressionManager::isCompressed($rawContext))->toBeTrue();
        expect(CompressionManager::isCompressed($rawPayload))->toBeTrue();
        expect(CompressionManager::isCompressed($rawMeta))->toBeTrue();

        // Verify data is correctly decompressed when accessed
        expect($event->context)->toEqual($largeContext);
        expect($event->payload)->toEqual($largePayload);
        expect($event->meta)->toEqual($largeMeta);

        // Test querying and retrieval maintains data integrity
        $retrievedEvent = MachineEvent::find($event->id);
        expect($retrievedEvent->context)->toEqual($largeContext);
        expect($retrievedEvent->payload)->toEqual($largePayload);
        expect($retrievedEvent->meta)->toEqual($largeMeta);

        // Verify machine_value is not compressed (kept as JSON for querying)
        $rawMachineValue = $event->getAttributes()['machine_value'];
        expect(CompressionManager::isCompressed($rawMachineValue))->toBeFalse();
    });

    it('respects compression threshold and field configuration', function (): void {
        // Create event with small context (below threshold)
        $smallContext = ['small' => 'data', 'count' => 1];

        // Create event with large payload (above threshold)
        $largePayload = ['large' => str_repeat('x', 200)];

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DN',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'threshold_test',
            'machine_value'   => ['state' => 'test'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DN',
            'source'          => 'internal',
            'type'            => 'test.threshold.event',
            'payload'         => $largePayload,
            'context'         => $smallContext,
            'meta'            => null,
            'version'         => 1,
        ]);

        // Small context should not be compressed
        $rawContext = $event->getAttributes()['context'];
        expect(CompressionManager::isCompressed($rawContext))->toBeFalse();

        // Large payload should be compressed
        $rawPayload = $event->getAttributes()['payload'];
        expect(CompressionManager::isCompressed($rawPayload))->toBeTrue();

        // Data should still be accessible correctly
        expect($event->context)->toEqual($smallContext);
        expect($event->payload)->toEqual($largePayload);
        expect($event->meta)->toBeNull();
    });
});
