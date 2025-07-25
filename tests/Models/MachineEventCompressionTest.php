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
});
