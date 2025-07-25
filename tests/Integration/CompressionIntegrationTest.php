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

    it('handles backward compatibility with existing uncompressed data', function (): void {
        $testData = ['existing' => 'uncompressed_data', 'legacy' => true];

        // Create event and manually set raw attributes to simulate existing data
        $event = new MachineEvent();
        $event->setRawAttributes([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DO',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'backward_compatibility_test',
            'machine_value'   => json_encode(['state' => 'legacy']),
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DO',
            'source'          => 'internal',
            'type'            => 'legacy.event',
            'payload'         => json_encode($testData), // Uncompressed JSON
            'context'         => json_encode($testData), // Uncompressed JSON
            'meta'            => json_encode($testData),    // Uncompressed JSON
            'version'         => 1,
        ]);

        // Should be able to read legacy uncompressed data
        expect($event->payload)->toEqual($testData);
        expect($event->context)->toEqual($testData);
        expect($event->meta)->toEqual($testData);

        // Verify data is not compressed
        expect(CompressionManager::isCompressed($event->getAttributes()['payload']))->toBeFalse();
        expect(CompressionManager::isCompressed($event->getAttributes()['context']))->toBeFalse();
        expect(CompressionManager::isCompressed($event->getAttributes()['meta']))->toBeFalse();
    });

    it('provides significant storage savings with real-world data sizes', function (): void {
        $realWorldContext = [
            'user_session' => [
                'id'          => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'user_id'     => 12345,
                'permissions' => array_fill(0, 50, 'permission_'.rand(1000, 9999)),
                'preferences' => [
                    'theme'         => 'dark',
                    'language'      => 'en',
                    'notifications' => array_fill(0, 20, 'notification_setting_'.rand(100, 999)),
                ],
                'activity_history' => array_fill(0, 100, [
                    'timestamp' => now()->toISOString(),
                    'action'    => 'user_action_'.rand(1, 50),
                    'details'   => str_repeat('activity_detail_', 10),
                ]),
            ],
            'application_state' => [
                'current_view'   => 'dashboard',
                'loaded_modules' => array_fill(0, 30, 'module_'.rand(1, 100)),
                'cache_data'     => str_repeat('cached_information_', 100),
            ],
        ];

        $originalSize = strlen(json_encode($realWorldContext));

        $event = MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DP',
            'sequence_number' => 1,
            'created_at'      => now(),
            'machine_id'      => 'storage_savings_test',
            'machine_value'   => ['state' => 'active'],
            'root_event_id'   => '01H8BM4VK82JKPK7RPR3YGT2DP',
            'source'          => 'internal',
            'type'            => 'real.world.event',
            'context'         => $realWorldContext,
            'version'         => 1,
        ]);

        $compressedSize   = strlen($event->getAttributes()['context']);
        $compressionRatio = $compressedSize / $originalSize;
        $savingsPercent   = (($originalSize - $compressedSize) / $originalSize) * 100;

        // Verify significant compression achieved
        expect($compressionRatio)->toBeLessThan(0.5); // At least 50% compression
        expect($savingsPercent)->toBeGreaterThan(30); // At least 30% savings
        expect(CompressionManager::isCompressed($event->getAttributes()['context']))->toBeTrue();

        // Verify data integrity maintained
        expect($event->context)->toEqual($realWorldContext);
    });
});
