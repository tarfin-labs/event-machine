<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Jobs\ArchiveSingleMachineJob;

describe('ArchiveEventsCommand', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'        => true,
            'machine.archival.level'          => 6,
            'machine.archival.days_inactive'  => 30,
            'machine.archival.advanced.queue' => null,
        ]);
    });

    it('dispatches jobs for eligible machines', function (): void {
        Queue::fake();

        // Create eligible machines (old, inactive)
        for ($i = 1; $i <= 3; $i++) {
            $rootEventId = sprintf('01H8BM4VK82JKPK7RPR3YG%04d', $i);
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'sequence_number' => 1,
                'root_event_id'   => $rootEventId,
                'machine_id'      => "machine_{$i}",
                'created_at'      => now()->subDays(35),
            ]);
        }

        $this->artisan('machine:archive-events')
            ->assertSuccessful();

        Queue::assertPushed(ArchiveSingleMachineJob::class, 3);
    });

    it('respects dispatch limit', function (): void {
        Queue::fake();

        // Create 5 eligible machines
        for ($i = 1; $i <= 5; $i++) {
            $rootEventId = sprintf('01H8BM4VK82JKPK7RPR3YG%04d', $i);
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'sequence_number' => 1,
                'root_event_id'   => $rootEventId,
                'machine_id'      => "machine_{$i}",
                'created_at'      => now()->subDays(35),
            ]);
        }

        $this->artisan('machine:archive-events', ['--dispatch-limit' => 2])
            ->assertSuccessful();

        Queue::assertPushed(ArchiveSingleMachineJob::class, 2);
    });

    it('does not dispatch jobs for active machines', function (): void {
        Queue::fake();

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        // Create old event
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'active_machine',
            'created_at'      => now()->subDays(60),
        ]);

        // Create recent event (same machine)
        MachineEvent::factory()->create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DN',
            'sequence_number' => 2,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'active_machine',
            'created_at'      => now()->subDays(5),
        ]);

        $this->artisan('machine:archive-events')
            ->assertSuccessful();

        Queue::assertNotPushed(ArchiveSingleMachineJob::class);
    });

    it('does not dispatch jobs for already archived machines', function (): void {
        Queue::fake();

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';

        // Create and archive machine
        $event = MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'archived_machine',
            'created_at'      => now()->subDays(35),
        ]);

        MachineEventArchive::archiveEvents(
            new \Tarfinlabs\EventMachine\EventCollection([$event])
        );
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        $this->artisan('machine:archive-events')
            ->assertSuccessful();

        Queue::assertNotPushed(ArchiveSingleMachineJob::class);
    });

    it('shows dry run information without dispatching', function (): void {
        Queue::fake();

        for ($i = 1; $i <= 3; $i++) {
            $rootEventId = sprintf('01H8BM4VK82JKPK7RPR3YG%04d', $i);
            MachineEvent::factory()->create([
                'id'              => $rootEventId,
                'sequence_number' => 1,
                'root_event_id'   => $rootEventId,
                'machine_id'      => "machine_{$i}",
                'created_at'      => now()->subDays(35),
            ]);
        }

        $this->artisan('machine:archive-events', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry Run');

        Queue::assertNotPushed(ArchiveSingleMachineJob::class);
    });

    it('runs synchronously with sync option', function (): void {
        // No Queue::fake() - we want the job to actually run

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(35),
        ]);

        expect(MachineEvent::count())->toBe(1);
        expect(MachineEventArchive::count())->toBe(0);

        $this->artisan('machine:archive-events', ['--sync' => true])
            ->assertSuccessful();

        // Machine should be archived immediately
        expect(MachineEventArchive::count())->toBe(1);
        expect(MachineEvent::count())->toBe(0);
    });

    it('fails when archival is disabled', function (): void {
        config(['machine.archival.enabled' => false]);

        $this->artisan('machine:archive-events')
            ->assertFailed();
    });

    it('uses configured queue for dispatched jobs', function (): void {
        Queue::fake();
        config(['machine.archival.advanced.queue' => 'archival']);

        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        MachineEvent::factory()->create([
            'id'              => $rootEventId,
            'sequence_number' => 1,
            'root_event_id'   => $rootEventId,
            'machine_id'      => 'test_machine',
            'created_at'      => now()->subDays(35),
        ]);

        $this->artisan('machine:archive-events')
            ->assertSuccessful();

        Queue::assertPushedOn('archival', ArchiveSingleMachineJob::class);
    });

    it('outputs nothing when no eligible machines', function (): void {
        Queue::fake();

        $this->artisan('machine:archive-events')
            ->assertSuccessful()
            ->expectsOutputToContain('No eligible machines');

        Queue::assertNotPushed(ArchiveSingleMachineJob::class);
    });
});
