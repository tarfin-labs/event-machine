<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\ChainedJobParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\FailingJobActorParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Job actor chain via real Horizon — no infinite loop
// ═══════════════════════════════════════════════════════════════

it('LocalQA: single job actor completes via Horizon and routes @done', function (): void {
    // JobActorParentMachine: idle → START → processing [job: SuccessfulTestJob] → @done → completed
    $machine = JobActorParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for job to complete and @done to fire
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'job actor: single job completes via Horizon');

    expect($completed)->toBeTrue('Job actor did not complete');

    $restored = JobActorParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('job_actor_parent.completed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: chained job states complete sequentially via Horizon — no infinite loop', function (): void {
    // ChainedJobParentMachine: idle → START → step_one [job] → @done → step_two [job] → @done → completed
    // This is the exact pattern that caused infinite loop in test mode (sync queue).
    // Under Horizon, each job runs independently — no cascade.
    $machine = ChainedJobParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for machine to reach completed via both job actors
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'chained job actors: both jobs complete via Horizon');

    expect($completed)->toBeTrue('Chained job actors did not complete');

    $restored = ChainedJobParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('chained_job_parent.completed');

    // Both CHILD_MACHINE_START events should be recorded (one per job)
    $jobStartEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%.child.%.start')
        ->count();
    expect($jobStartEvents)->toBe(2, "Expected 2 job start events, got {$jobStartEvents}");

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: failing job actor routes @fail via Horizon', function (): void {
    // FailingJobActorParentMachine: idle → START → processing [job: FailingTestJob] → @fail → failed
    $machine = FailingJobActorParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for failure routing
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60, description: 'job actor: failing job routes @fail via Horizon');

    expect($failed)->toBeTrue('Failing job actor did not route to @fail');

    $restored = FailingJobActorParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toContain('failed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 3 concurrent chained job machines all complete without deadlock', function (): void {
    // Stress test: 3 machines each with chained job states.
    // All should complete without infinite loops or deadlocks.
    $rootEventIds = [];

    for ($i = 0; $i < 3; $i++) {
        $machine = ChainedJobParentMachine::create();
        $machine->send(['type' => 'START']);
        $rootEventIds[] = $machine->state->history->first()->root_event_id;
    }

    $allCompleted = LocalQATestCase::waitFor(function () use ($rootEventIds) {
        foreach ($rootEventIds as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'completed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 60, description: '3 concurrent chained job machines all complete');

    expect($allCompleted)->toBeTrue('Not all chained job machines completed');

    // No stale locks
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
