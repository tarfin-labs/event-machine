<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

// ─── Resolver Pipeline ──────────────────────────────────────

it('E2E: resolver pipeline dispatches and transitions machine', function (): void {
    // Create a machine instance
    $machine = ScheduledMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Set up resolver to return this instance
    ExpiredApplicationsResolver::setUp([$rootEventId]);

    // Run real artisan command (sync queue, no Bus::fake)
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertExitCode(0);

    // Restore from DB — verify state changed
    $restored = ScheduledMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('scheduled_machine.expired');

    // Verify machine_current_states updated
    $currentState = MachineCurrentState::forInstance($rootEventId)->first();
    expect($currentState->state_id)->toBe('scheduled_machine.expired');
});

it('E2E: resolver cross-check filters wrong machine class', function (): void {
    // Create a machine instance
    $machine = ScheduledMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Resolver returns IDs, but we'll also include a fake ID that doesn't belong
    ExpiredApplicationsResolver::setUp([$rootEventId, 'fake-mre-999']);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertExitCode(0);

    // Only the real machine should have transitioned
    $restored = ScheduledMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('scheduled_machine.expired');
});

it('E2E: resolver returns empty dispatches nothing', function (): void {
    ExpiredApplicationsResolver::setUp([]);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertExitCode(0);
});

// ─── Auto-Detect Pipeline ──────────────────────────────────

it('E2E: auto-detect dispatches to all instances for root-level event', function (): void {
    // Create two machine instances
    $machine1 = ScheduledMachine::create();
    $machine1->persist();
    $rootEventId1 = $machine1->state->history->first()->root_event_id;

    $machine2 = ScheduledMachine::create();
    $machine2->persist();
    $rootEventId2 = $machine2->state->history->first()->root_event_id;

    // DAILY_REPORT is root-level on with null resolver → auto-detect
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ])->assertExitCode(0);

    // Both machines should still be in 'active' (DAILY_REPORT → 'active' self-transition)
    $restored1 = ScheduledMachine::create(state: $rootEventId1);
    $restored2 = ScheduledMachine::create(state: $rootEventId2);

    expect($restored1->state->currentStateDefinition->id)->toBe('scheduled_machine.active')
        ->and($restored2->state->currentStateDefinition->id)->toBe('scheduled_machine.active');
});

it('E2E: multiple schedules on same machine are independent', function (): void {
    $machine = ScheduledMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First: run DAILY_REPORT (auto-detect, stays active)
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ])->assertExitCode(0);

    $restored = ScheduledMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('scheduled_machine.active');

    // Then: run CHECK_EXPIRY (transitions to expired)
    ExpiredApplicationsResolver::setUp([$rootEventId]);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertExitCode(0);

    $restored = ScheduledMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('scheduled_machine.expired');
});
