<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob;

describe('ArchiveMachineEventsJob', function (): void {
    beforeEach(function (): void {
        // Enable archival for tests
        config([
            'machine.archival.enabled'       => true,
            'machine.archival.level'         => 6,
            'machine.archival.threshold'     => 100,
            'machine.archival.days_inactive' => 30,
        ]);
    });

    it('can get count of qualified machines for archival', function (): void {
        $config = [
            'enabled'       => true,
            'days_inactive' => 30,
        ];

        $oldDate    = now()->subDays(35); // Older than 30 days
        $recentDate = now()->subDays(20); // Within 30 days

        // Create old machine events (should qualify)
        $oldMachine = '01H8BM4VK82JKPK7RPR3YGT2DM';
        MachineEvent::factory()->create([
            'root_event_id' => $oldMachine,
            'machine_id'    => 'old_machine',
            'created_at'    => $oldDate,
        ]);

        // Create recent machine events (should not qualify)
        $recentMachine = '01H8BM4VK82JKPK7RPR3YGT2DN';
        MachineEvent::factory()->create([
            'root_event_id' => $recentMachine,
            'machine_id'    => 'recent_machine',
            'created_at'    => $recentDate,
        ]);

        $qualifiedCount = ArchiveMachineEventsJob::getQualifiedMachinesCount($config);

        expect($qualifiedCount)->toBe(1);
    });

    it('does not archive machines that are already archived', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'archived_machine';

        // Create old machine events
        $events = collect([
            MachineEvent::factory()->create([
                'root_event_id' => $rootEventId,
                'machine_id'    => $machineId,
                'created_at'    => now()->subDays(35),
            ]),
        ]);

        // Archive the machine manually
        MachineEventArchive::archiveEvents(new \Tarfinlabs\EventMachine\EventCollection($events->all()));

        // Should not qualify since it's already archived
        $qualifiedCount = ArchiveMachineEventsJob::getQualifiedMachinesCount();

        expect($qualifiedCount)->toBe(0);
    });

    it('archives qualified machines when job runs', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create old events that qualify for archival
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => $machineId,
            'created_at'      => now()->subDays(35),
        ]);

        expect(MachineEventArchive::count())->toBe(0);
        expect(MachineEvent::count())->toBe(1);

        // Run the job with custom config
        $config = [
            'enabled'       => true,
            'days_inactive' => 30,
        ];
        $job = ArchiveMachineEventsJob::withConfig($config, 100);
        $job->handle();

        // Should have created an archive and removed original events
        expect(MachineEventArchive::count())->toBe(1);
        expect(MachineEvent::count())->toBe(0); // Original events always cleaned up

        $archive = MachineEventArchive::first();
        expect($archive->root_event_id)->toBe($rootEventId);
        expect($archive->machine_id)->toBe($machineId);
        expect($archive->event_count)->toBe(1);
    });

    it('always cleans up original events after archival', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create old events
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => $machineId,
            'created_at'      => now()->subDays(35),
        ]);

        expect(MachineEvent::count())->toBe(1);

        // Run job with custom config
        $config = [
            'enabled'       => true,
            'days_inactive' => 30,
        ];
        $job = ArchiveMachineEventsJob::withConfig($config, 100);
        $job->handle();

        // Original events should always be deleted after archival
        expect(MachineEvent::count())->toBe(0);
        expect(MachineEventArchive::count())->toBe(1);
    });

    it('respects custom archival configuration', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create events that don't qualify under default config
        MachineEvent::factory()->create([
            'root_event_id' => $rootEventId,
            'machine_id'    => $machineId,
            'created_at'    => now()->subDays(20), // Less than 30 days
        ]);

        // Custom config with shorter inactive period
        $customConfig = [
            'enabled'       => true,
            'days_inactive' => 15, // Shorter period
        ];

        // Should qualify under custom config
        $qualifiedCount = ArchiveMachineEventsJob::getQualifiedMachinesCount($customConfig);
        expect($qualifiedCount)->toBe(1);

        // Run job with custom config
        $job = ArchiveMachineEventsJob::withConfig($customConfig, 100);
        $job->handle();

        expect(MachineEventArchive::count())->toBe(1);
    });

    it('does not run when archival is disabled', function (): void {
        // Create old events
        MachineEvent::factory()->create([
            'root_event_id' => '01H8BM4VK82JKPK7RPR3YGT2DM',
            'created_at'    => now()->subDays(35),
        ]);

        // Disable archival
        config(['machine.archival.enabled' => false]);

        $job = new ArchiveMachineEventsJob();
        $job->handle();

        // Should not have archived anything
        expect(MachineEventArchive::count())->toBe(0);
    });

    it('handles errors gracefully and continues processing', function (): void {
        // Create events
        $rootEventId1 = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $rootEventId2 = '01H8BM4VK82JKPK7RPR3YGT2DN';

        MachineEvent::factory()->create([
            'root_event_id' => $rootEventId1,
            'created_at'    => now()->subDays(35),
        ]);

        MachineEvent::factory()->create([
            'root_event_id' => $rootEventId2,
            'created_at'    => now()->subDays(35),
        ]);

        // Mock a scenario where one archive fails but processing continues
        $job = new ArchiveMachineEventsJob(1); // Small batch size to test error handling

        // This should not throw an exception even if individual archives fail
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });
});
