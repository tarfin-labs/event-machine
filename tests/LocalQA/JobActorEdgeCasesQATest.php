<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\GuardedDoneJobParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\FailingJobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\FireAndForgetJobParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Fire-and-forget job actors via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: fire-and-forget job — parent transitions to target immediately, job runs independently', function (): void {
    // FireAndForgetJobParentMachine: idle → dispatching [job+target] → waiting
    // Parent should reach 'waiting' immediately, job runs on Horizon independently.
    $machine = FireAndForgetJobParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Parent should be in 'waiting' immediately (fire-and-forget transitions instantly)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs)->not->toBeNull();
    expect($cs->state_id)->toContain('waiting');

    // Can still accept events in 'waiting'
    SendToMachineJob::dispatch(
        machineClass: FireAndForgetJobParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'FINISH'],
    );

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'fire-and-forget job: parent accepts FINISH event');

    expect($completed)->toBeTrue('Parent did not reach completed after FINISH');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  Guarded @done on job actors via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: guarded @done on job actor — guard passes → approved route via Horizon', function (): void {
    // GuardedDoneJobParentMachine: @done with guard that checks context 'approved' flag.
    // Set approved=true before starting → guard passes → routes to 'approved'
    $machine = GuardedDoneJobParentMachine::create();
    $machine->state->context->set('approved', true);
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'approved');
    }, timeoutSeconds: 60, description: 'guarded @done: guard passes → approved');

    expect($completed)->toBeTrue('Guarded @done did not route to approved');

    $restored = GuardedDoneJobParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('guarded_done_job.approved');
});

it('LocalQA: guarded @done on job actor — guard fails → fallback to manual_review via Horizon', function (): void {
    // approved=false (default) → guard fails → fallback to 'manual_review'
    $machine = GuardedDoneJobParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'manual_review');
    }, timeoutSeconds: 60, description: 'guarded @done: guard fails → manual_review');

    expect($completed)->toBeTrue('Guarded @done did not fall through to manual_review');

    $restored = GuardedDoneJobParentMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('guarded_done_job.manual_review');
});

// ═══════════════════════════════════════════════════════════════
//  Concurrent job actors — lock serialization
// ═══════════════════════════════════════════════════════════════

it('LocalQA: concurrent SendToMachineJob during job actor processing — no corruption', function (): void {
    // Start job actor, then send an external event concurrently.
    // The external event should be serialized via lock (release/retry).
    $machine = JobActorParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch an external event while job is being processed
    SendToMachineJob::dispatch(
        machineClass: JobActorParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'NONEXISTENT_EVENT'],
    );

    // Wait for job to complete normally via @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'concurrent send during job actor: no corruption');

    expect($completed)->toBeTrue('Job actor did not complete under concurrent event pressure');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Concurrent event during job actor caused failed jobs');
});

// ═══════════════════════════════════════════════════════════════
//  Job actor @fail with retry pattern
// ═══════════════════════════════════════════════════════════════

it('LocalQA: failing job actor records error and routes @fail via Horizon', function (): void {
    // FailingJobActorParentMachine: FailingTestJob always throws
    $machine = FailingJobActorParentMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60, description: 'failing job actor: @fail route via Horizon');

    expect($failed)->toBeTrue('Failing job did not route to @fail');

    // Verify error was captured in context
    $restored = FailingJobActorParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('error'))->not->toBeNull('Error not captured in @fail action');

    // Verify CHILD_MACHINE_FAIL event recorded
    $failEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%.child.%.fail')
        ->count();
    expect($failEvents)->toBeGreaterThanOrEqual(1, 'No child fail event recorded');
});

// ═══════════════════════════════════════════════════════════════
//  Mixed stress: multiple machine types concurrently
// ═══════════════════════════════════════════════════════════════

it('LocalQA: mixed job actor types — success + fail + fire-and-forget concurrently', function (): void {
    // Dispatch all 3 types simultaneously to test Horizon isolation.
    $successMachine = JobActorParentMachine::create();
    $successMachine->send(['type' => 'START']);
    $successId = $successMachine->state->history->first()->root_event_id;

    $failMachine = FailingJobActorParentMachine::create();
    $failMachine->send(['type' => 'START']);
    $failId = $failMachine->state->history->first()->root_event_id;

    $ffMachine = FireAndForgetJobParentMachine::create();
    $ffMachine->send(['type' => 'START']);
    $ffId = $ffMachine->state->history->first()->root_event_id;

    // Wait for all to settle
    $allSettled = LocalQATestCase::waitFor(function () use ($successId, $failId) {
        $successCs = MachineCurrentState::where('root_event_id', $successId)->first();
        $failCs    = MachineCurrentState::where('root_event_id', $failId)->first();

        return $successCs && str_contains($successCs->state_id, 'completed')
            && $failCs && str_contains($failCs->state_id, 'failed');
    }, timeoutSeconds: 60, description: 'mixed job actors: all settle concurrently');

    expect($allSettled)->toBeTrue('Not all job actor machines settled');

    // Verify fire-and-forget parent is in 'waiting' (already transitioned)
    $ffCs = MachineCurrentState::where('root_event_id', $ffId)->first();
    expect($ffCs->state_id)->toContain('waiting');

    // No stale locks across any machine
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);

    // FailingTestJob exhausts retries → ChildJobJob::failed() routes @fail to parent,
    // but Laravel also records the final failure in failed_jobs. This is expected:
    // the machine routes @fail correctly even though the worker-level failure is recorded.
    // We only verify that the failed_jobs count is <= the number of failing machines (1).
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBeLessThanOrEqual(1);
});
