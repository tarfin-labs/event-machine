<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

describe('ArchiveStatusCommand', function (): void {

    it('shows summary with no archives', function (): void {
        Artisan::call('machine:archive-status');

        $output = Artisan::output();

        expect($output)->toContain('Machine Events Archive Status');
        expect($output)->toContain('Active');
        expect($output)->toContain('Archived');
        expect($output)->toContain('0'); // No archives
    });

    it('shows summary with active events only', function (): void {
        MachineEvent::factory()->count(5)->create([
            'root_event_id' => '01H8TEST1234567890ABCDEF',
            'machine_id'    => 'test_machine',
        ]);

        Artisan::call('machine:archive-status');

        $output = Artisan::output();

        expect($output)->toContain('5'); // 5 active events
        expect($output)->toContain('Active');
    });

    it('shows summary with archives', function (): void {
        // Create and archive some events
        $events = new EventCollection([
            MachineEvent::factory()->create([
                'root_event_id' => '01H8TEST1234567890ABCDEF',
                'machine_id'    => 'test_machine',
            ]),
            MachineEvent::factory()->create([
                'root_event_id'   => '01H8TEST1234567890ABCDEF',
                'machine_id'      => 'test_machine',
                'sequence_number' => 2,
            ]),
        ]);

        MachineEventArchive::archiveEvents($events);
        MachineEvent::where('root_event_id', '01H8TEST1234567890ABCDEF')->delete();

        Artisan::call('machine:archive-status');

        $output = Artisan::output();

        expect($output)->toContain('Archived');
        expect($output)->toContain('2'); // 2 archived events
        expect($output)->toContain('Compression:');
    });

    it('fails restore when archive not found', function (): void {
        $exitCode = Artisan::call('machine:archive-status', [
            '--restore' => 'nonexistent_id',
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('Archive not found');
    });

    it('restores archive when confirmed', function (): void {
        // Create and archive events
        $rootEventId = '01H8TEST1234567890ABCDEF';

        $event = MachineEvent::factory()->create([
            'id'            => $rootEventId,
            'root_event_id' => $rootEventId,
            'machine_id'    => 'test_machine',
        ]);

        $events = new EventCollection([$event]);
        MachineEventArchive::archiveEvents($events);
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        // Verify archived
        expect(MachineEventArchive::count())->toBe(1);
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Mock confirmation
        $this->artisan('machine:archive-status', ['--restore' => $rootEventId])
            ->expectsConfirmation(
                'Restore 1 events for test_machine?',
                'yes'
            )
            ->assertSuccessful();

        // Verify restored
        expect(MachineEventArchive::count())->toBe(0);
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(1);
    });

    it('cancels restore when not confirmed', function (): void {
        $rootEventId = '01H8TEST1234567890ABCDEF';

        $event = MachineEvent::factory()->create([
            'id'            => $rootEventId,
            'root_event_id' => $rootEventId,
            'machine_id'    => 'test_machine',
        ]);

        MachineEventArchive::archiveEvents(new EventCollection([$event]));
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        $this->artisan('machine:archive-status', ['--restore' => $rootEventId])
            ->expectsConfirmation(
                'Restore 1 events for test_machine?',
                'no'
            )
            ->assertSuccessful();

        // Archive should still exist
        expect(MachineEventArchive::count())->toBe(1);
    });

    it('fails cleanup when archive not found', function (): void {
        $exitCode = Artisan::call('machine:archive-status', [
            '--cleanup' => 'nonexistent_id',
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('Archive not found');
    });

    it('deletes archive when cleanup confirmed', function (): void {
        $rootEventId = '01H8TEST1234567890ABCDEF';

        $event = MachineEvent::factory()->create([
            'id'            => $rootEventId,
            'root_event_id' => $rootEventId,
            'machine_id'    => 'test_machine',
        ]);

        MachineEventArchive::archiveEvents(new EventCollection([$event]));
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        expect(MachineEventArchive::count())->toBe(1);

        $this->artisan('machine:archive-status', ['--cleanup' => $rootEventId])
            ->expectsConfirmation(
                'Permanently delete archive for test_machine? This cannot be undone.',
                'yes'
            )
            ->assertSuccessful();

        expect(MachineEventArchive::count())->toBe(0);
    });

    it('cancels cleanup when not confirmed', function (): void {
        $rootEventId = '01H8TEST1234567890ABCDEF';

        $event = MachineEvent::factory()->create([
            'id'            => $rootEventId,
            'root_event_id' => $rootEventId,
            'machine_id'    => 'test_machine',
        ]);

        MachineEventArchive::archiveEvents(new EventCollection([$event]));
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        $this->artisan('machine:archive-status', ['--cleanup' => $rootEventId])
            ->expectsConfirmation(
                'Permanently delete archive for test_machine? This cannot be undone.',
                'no'
            )
            ->assertSuccessful();

        // Archive should still exist
        expect(MachineEventArchive::count())->toBe(1);
    });

    it('formats bytes correctly', function (): void {
        // Create a larger archive to test byte formatting
        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = MachineEvent::factory()->create([
                'root_event_id'   => '01H8TEST1234567890ABCDEF',
                'machine_id'      => 'test_machine',
                'sequence_number' => $i + 1,
                'context'         => ['data' => str_repeat('x', 1000)], // Add some bulk
            ]);
        }

        MachineEventArchive::archiveEvents(new EventCollection($events));
        MachineEvent::where('root_event_id', '01H8TEST1234567890ABCDEF')->delete();

        Artisan::call('machine:archive-status');

        $output = Artisan::output();

        // Should show KB or MB formatting
        expect($output)->toMatch('/\d+(\.\d+)?\s*(B|KB|MB)/');
    });
});
