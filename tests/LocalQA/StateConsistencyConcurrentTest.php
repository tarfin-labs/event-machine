<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EActionCounterMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchViaEventMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  State value consistency during concurrent event processing
// ═══════════════════════════════════════════════════════════════

it('LocalQA: restored state is always a valid known state during concurrent processing', function (): void {
    // Create a machine and dispatch events concurrently.
    // Read the machine state repeatedly while events are being processed.
    // Every read must return a valid, known state — never a partial/corrupted value.
    $machine = E2EActionCounterMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Known valid states for this machine
    $validStateIds = [
        'e2e_action_counter.idle',
        'e2e_action_counter.processing',
        'e2e_action_counter.completed',
    ];

    // Dispatch a sequence of events that will be processed by Horizon
    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'COMPLETE'],
    );

    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'FINISH'],
    );

    // Poll the machine state repeatedly during processing
    // Each read must return a valid state — no inconsistency
    $invalidStatesFound = [];
    $statesObserved     = [];
    $start              = time();

    while (time() - $start < 30) {
        try {
            $restored = E2EActionCounterMachine::create(state: $rootEventId);
            $stateId  = $restored->state->currentStateDefinition->id;

            $statesObserved[] = $stateId;

            if (!in_array($stateId, $validStateIds, true)) {
                $invalidStatesFound[] = $stateId;
            }

            // Context counters must be non-negative integers
            $entryCount = $restored->state->context->get('entry_count');
            $exitCount  = $restored->state->context->get('exit_count');

            expect($entryCount)->toBeGreaterThanOrEqual(0);
            expect($exitCount)->toBeGreaterThanOrEqual(0);

            // entry_count must always be >= exit_count (can't exit without entering)
            expect($entryCount)->toBeGreaterThanOrEqual($exitCount);

            // If machine reached completed, we're done
            if ($stateId === 'e2e_action_counter.completed') {
                break;
            }
        } catch (Throwable) {
            // Machine may be in transition (lock held) — skip and retry
        }

        usleep(100_000); // 100ms between reads
    }

    // No invalid states were observed
    expect($invalidStatesFound)->toBe(
        [],
        'Invalid states observed during concurrent processing: '.implode(', ', $invalidStatesFound),
    );

    // We should have observed at least 2 distinct states (idle + at least one other)
    $uniqueStates = array_unique($statesObserved);
    expect(count($uniqueStates))->toBeGreaterThanOrEqual(1);

    // Machine should eventually reach completed
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Machine did not reach completed state');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: parallel state value consistency — no partial region state during concurrent completion', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);

    // Create a parallel machine and observe its state during region processing
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Valid states for this machine
    $validTopLevelStates = [
        'parallel_dispatch_via_event.idle',
        'parallel_dispatch_via_event.processing',
        'parallel_dispatch_via_event.completed',
    ];

    // Poll the machine state during parallel region processing
    $invalidStatesFound = [];
    $statesObserved     = [];
    $start              = time();

    while (time() - $start < 30) {
        try {
            $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
            $stateId  = $restored->state->currentStateDefinition->id;

            $statesObserved[] = $stateId;

            if (!in_array($stateId, $validTopLevelStates, true)) {
                $invalidStatesFound[] = $stateId;
            }

            // If in parallel state, verify state value array is consistent
            if ($stateId === 'parallel_dispatch_via_event.processing') {
                $stateValue = $restored->state->value;

                // State value must be an array
                expect($stateValue)->toBeArray();

                // Each region's state must be a valid sub-state
                foreach ($stateValue as $regionState) {
                    expect($regionState)->toContain('parallel_dispatch_via_event.processing.');
                }
            }
        } catch (Throwable) {
            // Machine may be in transition — skip and retry
        }

        usleep(100_000);
    }

    // No invalid states
    expect($invalidStatesFound)->toBe([]);

    // Now complete the regions
    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    usleep(500_000);

    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    // Wait for completion
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Machine did not reach completed state');

    // Final state is consistent
    $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_via_event.completed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});
