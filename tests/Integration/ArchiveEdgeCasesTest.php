<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Support\CompressionManager;

describe('Archive Edge Cases', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'   => true,
            'machine.archival.level'     => 6,
            'machine.archival.threshold' => 100,
        ]);
        CompressionManager::clearCache();
    });

    describe('Data Integrity', function (): void {
        it('preserves unicode characters in payload through archive/restore cycle', function (): void {
            $rootEventId    = '01H8BM4VK82JKPK7RPR3YGT001';
            $unicodePayload = [
                'turkish' => 'Türkçe karakterler: ğüşıöç ĞÜŞİÖÇ',
                'chinese' => '中文字符',
                'arabic'  => 'النص العربي',
                'emoji'   => '🚀 🎉 ✅ ❌',
                'special' => "Line1\nLine2\tTabbed\r\nWindows",
            ];

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'unicode_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.unicode',
                    'payload'         => $unicodePayload,
                    'context'         => ['locale' => 'tr_TR'],
                    'meta'            => ['encoding' => 'UTF-8'],
                    'version'         => 1,
                ]),
            ]);

            $archive  = MachineEventArchive::archiveEvents($events);
            $restored = $archive->restoreEvents();

            expect($restored->first()->payload)->toEqual($unicodePayload);
        });

        it('preserves null values in payload, context, and meta', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT002';

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'null_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.null',
                    'payload'         => ['value' => null, 'nested' => ['also_null' => null]],
                    'context'         => ['nullable_field' => null],
                    'meta'            => ['debug' => null],
                    'version'         => 1,
                ]),
            ]);

            $archive  = MachineEventArchive::archiveEvents($events);
            $restored = $archive->restoreEvents();

            expect($restored->first()->payload['value'])->toBeNull();
            expect($restored->first()->payload['nested']['also_null'])->toBeNull();
            expect($restored->first()->context['nullable_field'])->toBeNull();
            expect($restored->first()->meta['debug'])->toBeNull();
        });

        it('preserves deeply nested structures', function (): void {
            $rootEventId  = '01H8BM4VK82JKPK7RPR3YGT003';
            $deeplyNested = [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => [
                                    'value' => 'deep_value',
                                    'array' => [1, 2, 3],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'nested_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.nested',
                    'payload'         => $deeplyNested,
                    'version'         => 1,
                ]),
            ]);

            $archive  = MachineEventArchive::archiveEvents($events);
            $restored = $archive->restoreEvents();

            expect($restored->first()->payload)->toEqual($deeplyNested);
        });

        it('handles large number of events in single archive', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT004';
            $events      = collect();

            for ($i = 1; $i <= 100; $i++) {
                $events->push(MachineEvent::create([
                    'id'              => sprintf('01H8BM4VK82JKPK7RPR3YG%04d', $i),
                    'sequence_number' => $i,
                    'created_at'      => now()->addSeconds($i),
                    'machine_id'      => 'bulk_test',
                    'machine_value'   => ['state' => "state_{$i}"],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => "event.{$i}",
                    'payload'         => ['iteration' => $i, 'data' => str_repeat('x', 50)],
                    'version'         => 1,
                ]));
            }

            $eventCollection = new EventCollection($events->all());
            $archive         = MachineEventArchive::archiveEvents($eventCollection);

            expect($archive->event_count)->toBe(100);
            expect($archive->compressed_size)->toBeLessThan($archive->original_size);

            $restored = $archive->restoreEvents();
            expect($restored)->toHaveCount(100);
            expect($restored->first()->payload['iteration'])->toBe(1);
            expect($restored->last()->payload['iteration'])->toBe(100);
        });
    });

    describe('Compression Edge Cases', function (): void {
        it('handles data exactly at threshold boundary', function (): void {
            config(['machine.archival.threshold' => 100]);
            CompressionManager::clearCache();

            // Create data exactly at threshold (100 bytes)
            $exactData = str_repeat('x', 80); // JSON overhead will push it to ~100
            expect(CompressionManager::shouldCompress(str_repeat('x', 99)))->toBeFalse();
            expect(CompressionManager::shouldCompress(str_repeat('x', 100)))->toBeTrue();
        });

        it('handles incompressible data gracefully', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT005';

            // Random-looking data that doesn't compress well
            $randomData = base64_encode(random_bytes(500));

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'random_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.random',
                    'payload'         => ['random' => $randomData],
                    'version'         => 1,
                ]),
            ]);

            $archive  = MachineEventArchive::archiveEvents($events);
            $restored = $archive->restoreEvents();

            // Even if compression doesn't help much, data should still be preserved
            expect($restored->first()->payload['random'])->toBe($randomData);
        });

        it('handles empty strings in payload', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT006';

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'empty_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.empty',
                    'payload'         => ['empty' => '', 'spaces' => '   ', 'zero' => '0'],
                    'version'         => 1,
                ]),
            ]);

            $archive  = MachineEventArchive::archiveEvents($events);
            $restored = $archive->restoreEvents();

            expect($restored->first()->payload['empty'])->toBe('');
            expect($restored->first()->payload['spaces'])->toBe('   ');
            expect($restored->first()->payload['zero'])->toBe('0');
        });
    });

    describe('Error Handling', function (): void {
        it('throws exception when decompressing corrupted data', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT007';

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'corrupt_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.corrupt',
                    'payload'         => ['data' => str_repeat('test', 100)],
                    'version'         => 1,
                ]),
            ]);

            $archive = MachineEventArchive::archiveEvents($events);

            // Corrupt the compressed data
            $archive->update(['events_data' => 'corrupted_invalid_data']);

            // Should throw an exception (either RuntimeException or PHP Error from gzuncompress)
            $exceptionThrown = false;
            try {
                $archive->restoreEvents();
            } catch (Throwable) {
                $exceptionThrown = true;
            }
            expect($exceptionThrown)->toBeTrue();
        });

        it('throws exception for invalid JSON in CompressionManager decompress', function (): void {
            $invalidJson = 'this is not json at all {{{';

            expect(fn () => CompressionManager::decompress($invalidJson))
                ->toThrow(InvalidArgumentException::class, 'Data is neither compressed nor valid JSON');
        });
    });

    describe('Sequence and Order Preservation', function (): void {
        it('preserves event order after archive/restore', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT008';
            $events      = collect();

            // Create events in reverse order to test sorting
            for ($i = 10; $i >= 1; $i--) {
                $events->push(MachineEvent::create([
                    'id'              => sprintf('01H8BM4VK82JKPK7RPR3YO%04d', $i),
                    'sequence_number' => $i,
                    'created_at'      => now()->addSeconds($i),
                    'machine_id'      => 'order_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => "event.seq.{$i}",
                    'payload'         => ['seq' => $i],
                    'version'         => 1,
                ]));
            }

            // Sort by sequence number before archiving (as would happen in real usage)
            $sortedEvents    = $events->sortBy('sequence_number')->values();
            $eventCollection = new EventCollection($sortedEvents->all());

            $archive  = MachineEventArchive::archiveEvents($eventCollection);
            $restored = $archive->restoreEvents();

            // Verify order is preserved
            $sequences = $restored->pluck('sequence_number')->all();
            expect($sequences)->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        });
    });

    describe('Re-archival Scenarios', function (): void {
        it('prevents duplicate archive for same root_event_id', function (): void {
            $rootEventId = '01H8BM4VK82JKPK7RPR3YGT009';

            $events = new EventCollection([
                MachineEvent::create([
                    'id'              => $rootEventId,
                    'sequence_number' => 1,
                    'created_at'      => now(),
                    'machine_id'      => 'duplicate_test',
                    'machine_value'   => ['state' => 'test'],
                    'root_event_id'   => $rootEventId,
                    'source'          => SourceType::INTERNAL,
                    'type'            => 'test.first',
                    'payload'         => ['version' => 1],
                    'version'         => 1,
                ]),
            ]);

            // First archive should succeed
            MachineEventArchive::archiveEvents($events);

            // Second archive with same root_event_id should fail (unique constraint)
            expect(fn () => MachineEventArchive::archiveEvents($events))
                ->toThrow(\Illuminate\Database\QueryException::class);
        });
    });

});
