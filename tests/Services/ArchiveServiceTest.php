<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

describe('ArchiveService', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'                => true,
            'machine.archival.level'                  => 6,
            'machine.archival.threshold'              => 100,
            'machine.archival.restore_cooldown_hours' => 24,
        ]);
    });

    it('can archive a machine with restore tracking', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create test events
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'root_event_id'   => $rootEventId,
            'machine_id'      => $machineId,
            'sequence_number' => 1,
        ]);

        $archiveService = new ArchiveService();
        $archive        = $archiveService->archiveMachine($rootEventId);

        expect($archive)->toBeInstanceOf(MachineEventArchive::class);
        expect($archive->root_event_id)->toBe($rootEventId);
        expect($archive->machine_id)->toBe($machineId);
        expect($archive->restore_count)->toBe(0);
        expect($archive->last_restored_at)->toBeNull();
    });

    it('can restore a machine with tracking', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create and archive events
        $events = collect([
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'root_event_id'   => $rootEventId,
                'machine_id'      => $machineId,
                'sequence_number' => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());
        $archive         = MachineEventArchive::archiveEvents($eventCollection);

        $archiveService = new ArchiveService();
        $restoredEvents = $archiveService->restoreMachine($rootEventId);

        expect($restoredEvents)->toBeInstanceOf(EventCollection::class);
        expect($restoredEvents)->toHaveCount(1);

        // Check that restoration was tracked
        $archive->refresh();
        expect($archive->restore_count)->toBe(1);
        expect($archive->last_restored_at)->not->toBeNull();
    });

    it('respects cooldown period for re-archival', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create archive with recent restoration
        $events = collect([
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'root_event_id'   => $rootEventId,
                'machine_id'      => $machineId,
                'sequence_number' => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());
        $archive         = MachineEventArchive::archiveEvents($eventCollection);

        // Simulate restoration tracking
        $archive->update([
            'restore_count'    => 1,
            'last_restored_at' => now()->subHours(12), // Recent restoration
        ]);

        $archiveService = new ArchiveService();

        // Should not be able to re-archive due to cooldown
        expect($archiveService->canReArchive($rootEventId))->toBeFalse();

        // But should be able to after cooldown period
        $archive->update(['last_restored_at' => now()->subHours(25)]);
        expect($archiveService->canReArchive($rootEventId))->toBeTrue();
    });

    it('can get eligible machines for archival', function (): void {
        $oldRootEventId    = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $recentRootEventId = '01H8BM4VK82JKPK7RPR3YGT2DN';

        // Create old machine (eligible for archival)
        MachineEvent::factory()->create([
            'root_event_id' => $oldRootEventId,
            'machine_id'    => 'old_machine',
            'created_at'    => now()->subDays(35),
        ]);

        // Create recent machine (not eligible)
        MachineEvent::factory()->create([
            'root_event_id' => $recentRootEventId,
            'machine_id'    => 'recent_machine',
            'created_at'    => now()->subDays(15),
        ]);

        $archiveService = new ArchiveService([
            'enabled'  => true,
            'triggers' => [
                'days_inactive' => 30,
                'max_events'    => 0,
                'max_size'      => 0,
            ],
        ]);

        $eligibleMachines = $archiveService->getEligibleInstances();

        expect($eligibleMachines)->toHaveCount(1);
        expect($eligibleMachines->first()->root_event_id)->toBe($oldRootEventId);
    });

    it('can get archive statistics', function (): void {
        // Create some archives
        $events1 = new EventCollection([
            MachineEvent::factory()->create([
                'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'machine_id'    => 'machine_1',
            ]),
        ]);

        $events2 = new EventCollection([
            MachineEvent::factory()->create([
                'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DN',
                'machine_id'    => 'machine_2',
            ]),
        ]);

        MachineEventArchive::archiveEvents($events1);
        MachineEventArchive::archiveEvents($events2);

        $archiveService = new ArchiveService();
        $stats          = $archiveService->getArchiveStats();

        expect($stats['enabled'])->toBeTrue();
        expect($stats['total_archives'])->toBe(2);
        expect($stats['total_events_archived'])->toBe(2);
        expect($stats['total_space_saved'])->toBeGreaterThan(0);
        expect($stats['average_compression_ratio'])->toBeGreaterThanOrEqual(0);
    });

    it('can batch archive multiple machines', function (): void {
        $rootEventIds = [
            '01H8BM4VK82JKPK7RPR3YGT2DM',
            '01H8BM4VK82JKPK7RPR3YGT2DN',
            '01H8BM4VK82JKPK7RPR3YGT2DO',
        ];

        // Create events for each machine
        foreach ($rootEventIds as $index => $rootEventId) {
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'root_event_id'   => $rootEventId,
                'machine_id'      => "machine_{$index}",
                'sequence_number' => 1,
            ]);
        }

        $archiveService = new ArchiveService();
        $results        = $archiveService->batchArchive($rootEventIds);

        expect($results['archived'])->toHaveCount(3);
        expect($results['failed'])->toBeEmpty();
        expect($results['skipped'])->toBeEmpty();

        // Verify archives were created
        expect(MachineEventArchive::count())->toBe(3);
    });

    it('skips machines during cooldown in batch archival', function (): void {
        $rootEventIds = [
            '01H8BM4VK82JKPK7RPR3YGT2DM',
            '01H8BM4VK82JKPK7RPR3YGT2DN',
        ];

        // Create events
        foreach ($rootEventIds as $index => $rootEventId) {
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'root_event_id'   => $rootEventId,
                'machine_id'      => "machine_{$index}",
                'sequence_number' => 1,
            ]);
        }

        // Archive first machine and simulate recent restoration
        $firstEvents = new EventCollection([
            MachineEvent::where('root_event_id', $rootEventIds[0])->first(),
        ]);
        $firstArchive = MachineEventArchive::archiveEvents($firstEvents);
        $firstArchive->update([
            'restore_count'    => 1,
            'last_restored_at' => now()->subHours(12), // Recent restoration
        ]);

        $archiveService = new ArchiveService();
        $results        = $archiveService->batchArchive($rootEventIds);

        expect($results['archived'])->toHaveCount(1); // Only second machine
        expect($results['skipped'])->toHaveCount(1); // First machine skipped due to cooldown
        expect($results['skipped'][0]['reason'])->toBe('cooldown_period_active');
    });

    it('returns null for disabled archival', function (): void {
        $archiveService = new ArchiveService(['enabled' => false]);

        $result = $archiveService->archiveMachine('01H8BM4VK82JKPK7RPR3YGT2DM');

        expect($result)->toBeNull();

        $stats = $archiveService->getArchiveStats();
        expect($stats['enabled'])->toBeFalse();
    });
});
