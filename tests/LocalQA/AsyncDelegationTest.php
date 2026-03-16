<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Async Delegation — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async delegation creates machine_children and dispatches to Horizon', function (): void {
    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in processing state (async child dispatched)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // machine_children record should exist
    $child = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($child)->not->toBeNull();

    // Wait for Horizon to complete the delegation (ImmediateChildMachine auto-completes)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Async delegation not completed by Horizon');
});

it('LocalQA: machine faking works with async delegation', function (): void {
    ImmediateChildMachine::fake();

    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for completion
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Faked delegation not completed');

    ImmediateChildMachine::assertInvoked();
    Machine::resetMachineFakes();
});
