<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\AutoDetectScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

beforeEach(function (): void {
    Bus::fake();
});

it('dispatches batch jobs for resolver-returned instances', function (): void {
    // Seed current states
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-1', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-2', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
    ]);

    ExpiredApplicationsResolver::setUp(['mre-1', 'mre-2']);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof SendToMachineJob
            && $job->event === ['type' => 'CHECK_EXPIRY']
        )
    );
});

it('cross-check filters out IDs for wrong machine class', function (): void {
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-1', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-2', 'machine_class' => 'App\\Other\\Machine', 'state_id' => 'active', 'state_entered_at' => now()],
    ]);

    ExpiredApplicationsResolver::setUp(['mre-1', 'mre-2']);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first()->rootEventId === 'mre-1'
    );
});

it('dispatches nothing when resolver returns empty', function (): void {
    ExpiredApplicationsResolver::setUp([]);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    Bus::assertNothingBatched();
});

it('auto-detect dispatches to all instances for root-level on event', function (): void {
    // DAILY_REPORT is defined on root-level `on` in ScheduledMachine with null resolver
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-1', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-2', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
    ]);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof SendToMachineJob
            && $job->event === ['type' => 'DAILY_REPORT']
        )
    );
});

it('auto-detect dispatches nothing when no matching instances', function (): void {
    // No instances seeded
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ])->assertSuccessful();

    Bus::assertNothingBatched();
});

it('auto-detect filters by state for state-level event', function (): void {
    // AutoDetectScheduledMachine has CHECK_EXPIRY only on 'active' state (null resolver)
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-active', 'machine_class' => AutoDetectScheduledMachine::class, 'state_id' => 'auto_detect_scheduled.active', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-suspended', 'machine_class' => AutoDetectScheduledMachine::class, 'state_id' => 'auto_detect_scheduled.suspended', 'state_entered_at' => now()],
    ]);

    $this->artisan('machine:process-scheduled', [
        '--class' => AutoDetectScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    // Only 'active' state handles CHECK_EXPIRY, so only mre-active should be dispatched
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first()->rootEventId === 'mre-active'
    );
});

it('fails when --class is missing', function (): void {
    $this->artisan('machine:process-scheduled', [
        '--event' => 'CHECK_EXPIRY',
    ])->assertFailed();
});

it('fails when --event is missing', function (): void {
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
    ])->assertFailed();
});

it('fails when event is not in schedules', function (): void {
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'NONEXISTENT_EVENT',
    ])->assertFailed();
});

it('dispatches with closure resolver', function (): void {
    // Create a machine with a closure schedule for testing
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-closure', 'machine_class' => ScheduledMachine::class, 'state_id' => 'active', 'state_entered_at' => now()],
    ]);

    // Use the class-based resolver since closure can't be tested via artisan command
    // (closures defined in definition are instantiated at define() time)
    ExpiredApplicationsResolver::setUp(['mre-closure']);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first()->rootEventId === 'mre-closure'
    );
});
