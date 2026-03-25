<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  @done fires exactly once when regions complete simultaneously
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parallel dispatch @done fires exactly once when regions complete simultaneously', function (): void {
    // E2EBasicMachine: two regions with entry actions that set context AND raise
    // events to reach final. Under real Horizon, both ParallelRegionJobs run
    // near-simultaneously. The lock ensures @done fires exactly once.
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Wait for machine to reach completed state
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45, description: 'parallel machine reaches completed via single @done');

    expect($completed)->toBeTrue('Machine did not reach completed state after both regions finished');

    // Verify machine is in the completed state
    $restored = E2EBasicMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_basic.completed');

    // Both regions should have run their entry actions
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');

    // Exactly ONE @done event should be recorded — not two
    $doneEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%parallel%done%')
        ->count();

    expect($doneEvents)->toBe(1, "Expected exactly 1 @done event, got {$doneEvents}");

    // Exactly ONE transition.start for @done should be recorded
    $doneTransitionStarts = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%transition.start%')
        ->where('type', 'like', '%@done%')
        ->count();

    expect($doneTransitionStarts)->toBe(1, "Expected exactly 1 @done transition.start, got {$doneTransitionStarts}");

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 5 concurrent parallel machines each fire @done exactly once', function (): void {
    // Stress test: create 5 machines that all auto-complete both regions.
    // Verify each machine fires @done exactly once — no duplicates under load.
    $rootEventIds = [];

    for ($i = 0; $i < 5; $i++) {
        $machine = E2EBasicMachine::create();
        $machine->persist();
        $rootEventIds[] = $machine->state->history->first()->root_event_id;
        $machine->dispatchPendingParallelJobs();
    }

    // Wait for ALL machines to complete
    $allCompleted = LocalQATestCase::waitFor(function () use ($rootEventIds) {
        foreach ($rootEventIds as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'completed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 60, description: 'all 5 concurrent parallel machines complete');

    expect($allCompleted)->toBeTrue('Not all machines completed');

    // Each machine should have exactly ONE @done event
    foreach ($rootEventIds as $rootEventId) {
        $doneEvents = MachineEvent::query()
            ->where('root_event_id', $rootEventId)
            ->where('type', 'like', '%parallel%done%')
            ->count();

        expect($doneEvents)->toBe(1, "Machine {$rootEventId}: expected 1 @done event, got {$doneEvents}");
    }

    // No stale locks across any machine
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
