<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DoneDotParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DoneDotCatchallParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  @done.{state} Routing — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async delegation routes via @done.{finalState} through Horizon (LQA1)', function (): void {
    $parent = DoneDotParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in processing state (async child dispatched)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Wait for Horizon: child auto-completes in 'approved' → @done.approved → parent goes to 'completed'
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Async @done.approved routing not completed by Horizon');
});

it('LocalQA: async delegation falls through to @done catch-all through Horizon (LQA2)', function (): void {
    $parent = DoneDotCatchallParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in processing state
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Wait for Horizon: ImmediateChildMachine completes in 'done' → not matched by @done.approved → catch-all @done → 'fallback'
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'fallback');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Async @done catch-all fallback not completed by Horizon');
});

it('LocalQA: Machine::fake(finalState:) short-circuits async @done.{state} routing (LQA3)', function (): void {
    ImmediateApprovedChildMachine::fake(finalState: 'approved');

    $parent = DoneDotParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Faked: should complete immediately without Horizon
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Faked @done.approved routing not completed');

    ImmediateApprovedChildMachine::assertInvoked();
    Machine::resetMachineFakes();
});
