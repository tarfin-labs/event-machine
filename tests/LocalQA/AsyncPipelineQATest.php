<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobThenAlwaysParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\MixedDelegationParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Job actor @done → @always chain via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: job @done → @always fires correctly — routing state is transient', function (): void {
    // JobThenAlwaysParentMachine:
    //   idle → START → processing [job] → @done → routing → @always → completed
    //
    // The @always on 'routing' state must fire after job completes via Horizon.
    // 'routing' is a transient state — machine should skip through it.
    $machine = JobThenAlwaysParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'job @done → @always: reaches completed via transient routing');

    expect($completed)->toBeTrue('Job @done → @always chain did not complete');

    $restored = JobThenAlwaysParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('job_then_always.completed');

    // @done action should have set 'routed' context
    expect($restored->state->context->get('routed'))->toBeTrue('markRoutedAction did not run');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 3 concurrent job→@always machines all complete', function (): void {
    $rootEventIds = [];

    for ($i = 0; $i < 3; $i++) {
        $machine = JobThenAlwaysParentMachine::create();
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
    }, timeoutSeconds: 60, description: '3 concurrent job→@always all complete');

    expect($allCompleted)->toBeTrue('Not all job→@always machines completed');

    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  Mixed delegation: machine → job actor chain via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: machine delegation → @done → job actor → @done → completed (mixed chain)', function (): void {
    // MixedDelegationParentMachine:
    //   idle → START → delegating [machine: ImmediateChildMachine]
    //   → @done → processing [job: SuccessfulTestJob]
    //   → @done → completed
    //
    // This tests the full pipeline: machine delegation completes,
    // parent routes to a job state, job completes, parent reaches final.
    $machine = MixedDelegationParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'mixed chain: machine→job→completed via Horizon');

    expect($completed)->toBeTrue('Mixed delegation chain did not complete');

    $restored = MixedDelegationParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('mixed_delegation.completed');

    // Both @done actions should have run
    expect($restored->state->context->get('childOutput'))->toBe('child_done',
        'captureChildOutputAction did not run after machine @done'
    );
    expect($restored->state->context->get('jobOutput'))->not->toBeNull(
        'captureJobOutputAction did not run after job @done'
    );

    // Verify both child start events recorded (one machine, one job)
    $childStartEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%.child.%.start')
        ->count();
    expect($childStartEvents)->toBe(2, "Expected 2 child start events (1 machine + 1 job), got {$childStartEvents}");

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 3 concurrent mixed delegation chains all complete', function (): void {
    $rootEventIds = [];

    for ($i = 0; $i < 3; $i++) {
        $machine = MixedDelegationParentMachine::create();
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
    }, timeoutSeconds: 60, description: '3 concurrent mixed chains all complete');

    expect($allCompleted)->toBeTrue('Not all mixed delegation machines completed');

    // Verify each machine has both delegation markers
    foreach ($rootEventIds as $rootEventId) {
        $restored = MixedDelegationParentMachine::create(state: $rootEventId);
        expect($restored->state->context->get('childOutput'))->toBe('child_done');
        expect($restored->state->context->get('jobOutput'))->not->toBeNull();
    }

    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
