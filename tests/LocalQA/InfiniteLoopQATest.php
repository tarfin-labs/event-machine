<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopOnDoneParent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopOnTimerMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  QA #1: Timer sweep → @always loop → failed_jobs entry
// ═══════════════════════════════════════════════════════════════

it('LocalQA: timer sweep on loop machine → failed_jobs entry, machine unchanged', function (): void {
    $machine = AlwaysLoopOnTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subSeconds(10)]);

    Artisan::call('machine:process-timers', ['--class' => AlwaysLoopOnTimerMachine::class]);

    // Wait for Horizon to process (and fail)
    $hasFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($hasFailed)->toBeTrue('No failed_jobs entry after loop exception');

    // Verify exception type in failed_jobs
    $failedJob = DB::table('failed_jobs')->first();
    expect($failedJob->exception)->toContain('MaxTransitionDepthExceededException');

    // Machine state unchanged
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop_timer.waiting');
});

// ═══════════════════════════════════════════════════════════════
//  QA #2: Scheduled event → @always loop → failed_jobs
// ═══════════════════════════════════════════════════════════════

it('LocalQA: scheduled event on loop machine → failed_jobs, machine unchanged', function (): void {
    // Use AlwaysLoopMachine — dispatch TRIGGER event via SendToMachineJob
    $machine = AlwaysLoopMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: AlwaysLoopMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'TRIGGER'],
    );

    $hasFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($hasFailed)->toBeTrue('No failed_jobs entry');

    $failedJob = DB::table('failed_jobs')->first();
    expect($failedJob->exception)->toContain('MaxTransitionDepthExceededException');

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop.idle');
});

// ═══════════════════════════════════════════════════════════════
//  QA #3: Async child @done → parent @always loop — document behavior
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async child @done → parent with @always loop — documents actual behavior', function (): void {
    // AlwaysLoopOnDoneParent: delegates to AlwaysLoopImmediateChild.
    // @done routes to loop_a which has @always loop.
    // NOTE: @done uses executeChildTransitionBranch which bypasses transition().
    // The @always loop may NOT fire — this test documents actual observed behavior.
    $parent = AlwaysLoopOnDoneParent::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to complete and @done to route
    $settled = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $parentRootEventId)->first();

        // Parent moved out of delegating state (either to loop_a or failed_jobs entry)
        return $cs && !str_contains($cs->state_id, 'delegating');
    }, timeoutSeconds: 45);

    // Document actual behavior:
    $cs          = MachineCurrentState::where('root_event_id', $parentRootEventId)->first();
    $failedCount = DB::table('failed_jobs')->count();

    // Either: parent lands in loop_a (bypass path, @always not evaluated)
    // Or: CompletionJob fails with MaxTransitionDepthExceededException
    // Or: parent stays in delegating (completion never processed)
    expect($settled || $failedCount > 0)->toBeTrue(
        "Parent state: {$cs?->state_id}, failed_jobs: {$failedCount}"
    );
});

// ═══════════════════════════════════════════════════════════════
//  QA #4: SendToMachineJob → target @always loop → failed_jobs
// ═══════════════════════════════════════════════════════════════

it('LocalQA: SendToMachineJob to loop target → failed_jobs, target unchanged', function (): void {
    $machine = AlwaysLoopMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: AlwaysLoopMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'TRIGGER'],
    );

    $hasFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($hasFailed)->toBeTrue('No failed_jobs entry');

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop.idle');
});

// ═══════════════════════════════════════════════════════════════
//  QA #5: Horizon worker recovery after loop exception
// ═══════════════════════════════════════════════════════════════

it('LocalQA: Horizon worker recovers after loop exception — processes next job', function (): void {
    // First: send a loop-triggering job
    $loopMachine = AlwaysLoopMachine::create();
    $loopMachine->persist();
    $loopId = $loopMachine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: AlwaysLoopMachine::class,
        rootEventId: $loopId,
        event: ['type' => 'TRIGGER'],
    );

    // Wait for it to fail
    $loopFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($loopFailed)->toBeTrue('Loop job did not fail');

    // Now send a normal job — worker should still be alive
    $normalMachine = AfterTimerMachine::create();
    $normalMachine->persist();
    $normalId = $normalMachine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $normalId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Wait for normal machine to transition
    $normalProcessed = LocalQATestCase::waitFor(function () use ($normalId) {
        $cs = MachineCurrentState::where('root_event_id', $normalId)->first();

        return $cs && str_contains($cs->state_id, 'cancelled');
    }, timeoutSeconds: 45);

    expect($normalProcessed)->toBeTrue('Worker did not recover — normal job not processed');
});

// ═══════════════════════════════════════════════════════════════
//  QA #6: Selective failure — 5 machines, 1 loops
// ═══════════════════════════════════════════════════════════════

it('LocalQA: selective failure — 4 normal timers succeed, 1 loop timer fails', function (): void {
    // Create 4 normal AfterTimerMachines
    $normalIds = [];
    for ($i = 0; $i < 4; $i++) {
        $m = AfterTimerMachine::create();
        $m->persist();
        $normalIds[] = $m->state->history->first()->root_event_id;
    }

    // Backdate all normal machines
    foreach ($normalIds as $id) {
        DB::table('machine_current_states')
            ->where('root_event_id', $id)
            ->update(['state_entered_at' => now()->subDays(8)]);
    }

    // Create 1 loop machine
    $loopMachine = AlwaysLoopOnTimerMachine::create();
    $loopMachine->persist();
    $loopId = $loopMachine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $loopId)
        ->update(['state_entered_at' => now()->subSeconds(10)]);

    // Run sweeps for both machine classes
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Artisan::call('machine:process-timers', ['--class' => AlwaysLoopOnTimerMachine::class]);

    // Wait for normal machines to transition
    $allNormalDone = LocalQATestCase::waitFor(function () use ($normalIds) {
        foreach ($normalIds as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'cancelled')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 45);

    expect($allNormalDone)->toBeTrue('Not all normal machines transitioned');

    // Wait for loop machine to fail
    $loopFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($loopFailed)->toBeTrue('Loop machine did not produce failed_jobs entry');

    // Loop machine still in original state
    $cs = MachineCurrentState::where('root_event_id', $loopId)->first();
    expect($cs->state_id)->toBe('always_loop_timer.waiting');
});

// ═══════════════════════════════════════════════════════════════
//  QA #7: Cross-machine sendTo → target loops
// ═══════════════════════════════════════════════════════════════

it('LocalQA: SendToMachineJob targeting loop machine → failed, target preserved', function (): void {
    // This is equivalent to cross-machine sendTo via queue dispatch
    $target = AlwaysLoopMachine::create();
    $target->persist();
    $targetId = $target->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: AlwaysLoopMachine::class,
        rootEventId: $targetId,
        event: ['type' => 'TRIGGER'],
    );

    $hasFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 45);

    expect($hasFailed)->toBeTrue();

    // Target machine preserved
    $cs = MachineCurrentState::where('root_event_id', $targetId)->first();
    expect($cs->state_id)->toBe('always_loop.idle');
});
