<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DeepDelegationParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Deep Delegation — Parent → Child → Grandchild via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: three-level async delegation completes via Horizon', function (): void {
    $parent = DeepDelegationParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for entire chain to complete: Parent → GrandchildDelegation → ImmediateChild → back up
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 90, description: 'deep delegation: Parent → Child → Grandchild chain completion');

    expect($completed)->toBeTrue('Three-level delegation chain did not complete via Horizon');

    // Assert: 2 MachineChild records (parent→child, child→grandchild)
    $childRecords = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->count();
    expect($childRecords)->toBe(1, 'Parent should have 1 direct child record');

    // The grandchild is a child of the child, not of the parent
    $allChildren = DB::table('machine_children')->count();
    expect($allChildren)->toBe(2, 'Total should be 2: parent→child and child→grandchild');

    // Assert: context flows through chain
    $restored = DeepDelegationParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('chainCompleted'))->toBeTrue();
});
