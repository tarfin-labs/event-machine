<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\FailingJobActorParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Job Actor — Success via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: job actor runs via Horizon and completes parent with result', function (): void {
    $parent = JobActorParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in processing state (ChildJobJob dispatched to queue)
    // Note: job actors do NOT create MachineChild records — that's for machine delegation only
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Wait for Horizon to process ChildJobJob → ChildMachineCompletionJob → parent @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('Job actor did not complete parent via Horizon');

    // Verify result was passed to parent context
    $restored = JobActorParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('paymentId'))->toBe('pay_test_123');
});

// ═══════════════════════════════════════════════════════════════
//  Job Actor — Failure routes to @fail
// ═══════════════════════════════════════════════════════════════

it('LocalQA: failing job actor routes parent to @fail via Horizon', function (): void {
    $parent = FailingJobActorParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for Horizon to process ChildJobJob (fails) → ChildMachineCompletionJob (success=false) → parent @fail
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60);

    expect($failed)->toBeTrue('Failing job actor did not route parent to @fail');

    // Verify error captured in context
    $restored = FailingJobActorParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('error'))->toContain('simulated error');
});
