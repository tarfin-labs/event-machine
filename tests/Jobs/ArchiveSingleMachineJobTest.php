<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Jobs\ArchiveSingleMachineJob;

describe('ArchiveSingleMachineJob', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled' => true,
            'machine.archival.level'   => 6,
        ]);
    });

    it('archives a single machine with all its events', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create multiple events for the same machine
        for ($i = 1; $i <= 3; $i++) {
            MachineEvent::factory()->create([
                'id'              => sprintf('01H8BM4VK82JKPK7RPR3YG%04d', $i),
                'sequence_number' => $i,
                'root_event_id'   => $rootEventId,
                'machine_id'      => $machineId,
                'created_at'      => now()->subDays(35),
            ]);
        }

        expect(MachineEvent::count())->toBe(3);
        expect(MachineEventArchive::count())->toBe(0);

        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        expect(MachineEventArchive::count())->toBe(1);
        expect(MachineEvent::count())->toBe(0);

        $archive = MachineEventArchive::first();
        expect($archive->root_event_id)->toBe($rootEventId);
        expect($archive->machine_id)->toBe($machineId);
        expect($archive->event_count)->toBe(3);
    });

    it('does nothing when archival is disabled', function (): void {
        config(['machine.archival.enabled' => false]);

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        MachineEvent::factory()->create([
            'root_event_id' => $rootEventId,
            'created_at'    => now()->subDays(35),
        ]);

        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        expect(MachineEventArchive::count())->toBe(0);
        expect(MachineEvent::count())->toBe(1);
    });

    it('skips already archived machines', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create event
        $event = MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => $machineId,
            'created_at'      => now()->subDays(35),
        ]);

        // Archive it first
        MachineEventArchive::archiveEvents(
            new EventCollection([$event])
        );
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        expect(MachineEventArchive::count())->toBe(1);

        // Try to archive again
        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        // Should still be 1, not 2
        expect(MachineEventArchive::count())->toBe(1);
    });

    it('does nothing when machine has no events', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        expect(MachineEventArchive::count())->toBe(0);
    });

    it('uses configured compression level', function (): void {
        config(['machine.archival.level' => 9]);

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(35),
        ]);

        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        $archive = MachineEventArchive::first();
        expect($archive->compression_level)->toBe(9);
    });

    it('has unique id based on root_event_id', function (): void {
        $rootEventId1 = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $rootEventId2 = '01H8BM4VK82JKPK7RPR3YGT2DN';

        $job1 = new ArchiveSingleMachineJob($rootEventId1);
        $job2 = new ArchiveSingleMachineJob($rootEventId2);
        $job3 = new ArchiveSingleMachineJob($rootEventId1);

        expect($job1->uniqueId())->toBe('archive-'.$rootEventId1);
        expect($job2->uniqueId())->toBe('archive-'.$rootEventId2);
        expect($job1->uniqueId())->toBe($job3->uniqueId());
    });

    it('uses configured queue when set', function (): void {
        Queue::fake();
        config(['machine.archival.advanced.queue' => 'archival']);

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        ArchiveSingleMachineJob::dispatch($rootEventId);

        Queue::assertPushedOn('archival', ArchiveSingleMachineJob::class);
    });

    it('uses default queue when not configured', function (): void {
        Queue::fake();
        config(['machine.archival.advanced.queue' => null]);

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        ArchiveSingleMachineJob::dispatch($rootEventId);

        Queue::assertPushed(ArchiveSingleMachineJob::class, function ($job) {
            return $job->queue === null; // Default queue
        });
    });

    it('preserves event order by sequence number', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        // Create events out of order
        MachineEvent::factory()->create([
            'id'              => '01H8BM4VK82JKPK7RPR3YG0003',
            'sequence_number' => 3,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(35),
        ]);
        MachineEvent::factory()->create([
            'id'              => '01H8BM4VK82JKPK7RPR3YG0001',
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(37),
        ]);
        MachineEvent::factory()->create([
            'id'              => '01H8BM4VK82JKPK7RPR3YG0002',
            'sequence_number' => 2,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(36),
        ]);

        $job = new ArchiveSingleMachineJob($rootEventId);
        $job->handle();

        $archive = MachineEventArchive::first();
        $events  = $archive->restoreEvents();

        expect($events->get(0)->sequence_number)->toBe(1);
        expect($events->get(1)->sequence_number)->toBe(2);
        expect($events->get(2)->sequence_number)->toBe(3);
    });
});
