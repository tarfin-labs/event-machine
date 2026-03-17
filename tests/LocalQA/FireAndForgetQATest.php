<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetAlwaysParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Fire-and-Forget Machine Delegation — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: fire-and-forget parent stays in state, child runs via Horizon', function (): void {
    $parent = FireAndForgetParentMachine::create();
    $parent->state->context->set('order_id', 'ORD-QA-1');
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent immediately at 'processing' (not stuck waiting)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // No machine_children record
    expect(DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)->count()
    )->toBe(0);

    // Wait for child machine events to appear (Horizon processes ChildMachineJob)
    $childCreated = LocalQATestCase::waitFor(function () {
        return DB::table('machine_current_states')
            ->where('state_id', 'LIKE', '%immediate_child%')
            ->exists();
    }, timeoutSeconds: 30);

    expect($childCreated)->toBeTrue('Child machine was not created by Horizon');
});

it('LocalQA: fire-and-forget parent accepts events while child runs', function (): void {
    $parent = FireAndForgetParentMachine::create();
    $parent->state->context->set('order_id', 'ORD-QA-2');
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent is in 'processing', send FINISH
    $restored = FireAndForgetParentMachine::create(state: $rootEventId);
    $restored->send(['type' => 'FINISH']);
    $restored->persist();

    // Parent at completed
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('completed');
});

it('LocalQA: fire-and-forget with @always transitions parent immediately', function (): void {
    $parent = FireAndForgetAlwaysParentMachine::create();
    $parent->state->context->set('tckn', '12345678901');
    $parent->send(['type' => 'REJECT']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent at 'prevented' (via @always, not waiting)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('prevented');

    // Wait for child to complete
    $childCreated = LocalQATestCase::waitFor(function () {
        return DB::table('machine_current_states')
            ->where('state_id', 'LIKE', '%immediate_child%')
            ->exists();
    }, timeoutSeconds: 30);

    expect($childCreated)->toBeTrue('Child was not processed by Horizon');
});

it('LocalQA: fire-and-forget child does not dispatch completion job', function (): void {
    $parent = FireAndForgetParentMachine::create();
    $parent->state->context->set('order_id', 'ORD-QA-4');
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to finish
    LocalQATestCase::waitFor(function () {
        return DB::table('machine_current_states')
            ->where('state_id', 'LIKE', '%immediate_child%')
            ->exists();
    }, timeoutSeconds: 30);

    // Small delay to let any stray completion jobs process
    sleep(2);

    // Parent STILL at processing (no ChildMachineCompletionJob changed it)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');
});

it('LocalQA: managed async still works with MachineChild tracking (regression)', function (): void {
    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // MachineChild should exist
    expect(DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)->count()
    )->toBe(1);

    // Wait for managed completion
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Managed async regression: not completed');
});
