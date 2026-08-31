<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Contracts\ScheduleResolver;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\BrokenDefinitionTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

beforeEach(function (): void {
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| SCHEDULES — Bug 1: duplicate dispatch for a machine with >1 current-state row
|--------------------------------------------------------------------------
|
| A parallel machine records one machine_current_states row per active
| region (composite PK: root_event_id, state_id). Both the resolver
| cross-check and the root-level auto-detect pluck('root_event_id') with
| no distinct(), so a single instance yields N identical SendToMachineJobs.
|
*/

it('BUG: resolver path dispatches one job per current-state row (parallel duplication)', function (): void {
    // One instance ('mre-parallel') that is in TWO regions at once → two rows.
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-parallel', 'machine_class' => ScheduledMachine::class, 'state_id' => 'verification', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-parallel', 'machine_class' => ScheduledMachine::class, 'state_id' => 'data_collection', 'state_entered_at' => now()],
    ]);

    // The resolver correctly returns the instance ONCE.
    ExpiredApplicationsResolver::setUp(['mre-parallel']);

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    // EXPECTED: exactly ONE job for the one instance.
    // ACTUAL (bug): two identical jobs — the cross-check pluck returns 'mre-parallel' twice.
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

it('BUG: auto-detect root-level path dispatches one job per current-state row', function (): void {
    // Same instance in two regions.
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-parallel', 'machine_class' => ScheduledMachine::class, 'state_id' => 'verification', 'state_entered_at' => now()],
        ['root_event_id' => 'mre-parallel', 'machine_class' => ScheduledMachine::class, 'state_id' => 'data_collection', 'state_entered_at' => now()],
    ]);

    // DAILY_REPORT is a root-level `on` event with a null resolver → all instances.
    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'DAILY_REPORT',
    ])->assertSuccessful();

    // EXPECTED: one job. ACTUAL (bug): two.
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

/*
|--------------------------------------------------------------------------
| SCHEDULES — Bug 2: a throwing resolver is swallowed and the command SUCCEEDS
|--------------------------------------------------------------------------
|
| "Resolver blew up" is indistinguishable from "nothing to process": a
| Sentry cron monitor stays green while nothing is dispatched.
|
*/

it('BUG: a throwing resolver makes the command exit SUCCESS, not FAILURE', function (): void {
    // Bind the resolver class the machine references to a throwing implementation.
    app()->bind(ExpiredApplicationsResolver::class, fn (): ScheduleResolver => new class() implements ScheduleResolver {
        public function __invoke(): Collection
        {
            throw new RuntimeException('resolver query failed');
        }
    });

    $this->artisan('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertFailed();
});

/*
|--------------------------------------------------------------------------
| TIMERS — Bug 3: a machine whose definition() throws is swallowed → SUCCESS
|--------------------------------------------------------------------------
|
| ProcessTimersCommand::processClass() catches the definition-load Throwable
| and returns; handle() still returns SUCCESS. Same swallow class as the
| schedules resolver bug, in the "correct reference" command.
|
*/

it('BUG: timer sweep exits SUCCESS when the machine definition fails to build', function (): void {
    $this->artisan('machine:process-timers', [
        '--class' => BrokenDefinitionTimerMachine::class,
    ])->assertFailed();
});

/*
|--------------------------------------------------------------------------
| TIMERS — characterization: immune to the duplicate-dispatch pattern
|--------------------------------------------------------------------------
|
| Timer queries always filter by a single state_id, and the PK guarantees
| at most one row per (instance, state_id). So an instance with multiple
| current-state rows still yields exactly one timer job. This test should
| PASS today and documents why timers do NOT share Bug 1.
|
*/

it('timers dispatch one job for an instance with multiple current-state rows', function (): void {
    $machineClass = AfterTimerMachine::class;

    // Persist a real instance so the timer's target current-state row exists
    // with the exact state_id the sweep expects.
    $machine = $machineClass::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Fabricate a SECOND current-state row for the SAME instance, as if it were
    // also active in another parallel region.
    MachineCurrentState::insert([
        ['root_event_id' => $rootEventId, 'machine_class' => $machineClass, 'state_id' => 'some_other_region', 'state_entered_at' => now()->subDays(8)],
    ]);

    // Backdate every row so the after-timer deadline (7 days) has passed.
    MachineCurrentState::query()->update(['state_entered_at' => now()->subDays(8)]);

    $this->artisan('machine:process-timers', ['--class' => $machineClass])
        ->assertSuccessful();

    // Only the row matching the timer's state_id qualifies → exactly one job, no duplication.
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});
