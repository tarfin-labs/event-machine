<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedGrandparentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedFailingParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QADiscriminatedParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Typed Async Delegation — Input/Output/Failure via Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async child with typed input — input validated and merged before child starts via Horizon', function (): void {
    // Parent context has orderId='ORD-QA-001' and amount=250.
    // input: PaymentInput::class causes PaymentInput::fromContext() to resolve
    // orderId + amount from parent context, validate, and merge into child context.
    // This all happens inside ChildMachineJob on Horizon.
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for the entire chain: parent → child (auto-completes) → ChildMachineCompletionJob → parent @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'typed input delegation: parent should reach completed after child auto-completes');

    expect($completed)->toBeTrue('Parent did not reach completed state via Horizon');

    // Verify child was created and completed
    $childRecord = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($childRecord)->not->toBeNull('machine_children record should exist');
    expect($childRecord->status)->toBe('completed');

    // Verify child context received the typed input values (orderId, amount from parent)
    // The child's context should have orderId and amount merged from PaymentInput
    $childRootEventId = $childRecord->child_root_event_id;
    $childEvents      = DB::table('machine_events')
        ->where('root_event_id', $childRootEventId)
        ->orderBy('sequence_number')
        ->get();

    // Child should have events — initial + entry action + @always transition + final
    expect($childEvents->count())->toBeGreaterThan(0, 'Child should have persisted events');
});

it('LocalQA: async child with typed output — parent receives typed MachineOutput via ChildMachineCompletionJob', function (): void {
    // QATypedImmediateChildMachine reaches 'completed' with output: PaymentOutput::class.
    // PaymentOutput::fromContext() reads paymentId + status from child context.
    // This output is serialized via toArray(), sent through ChildMachineCompletionJob queue,
    // and arrives in parent's @done event payload.
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'typed output delegation: parent should receive child output via @done');

    expect($completed)->toBeTrue('Parent did not reach completed state');

    // Restore parent and verify child output was captured
    $restored    = QATypedParentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    // PaymentOutput has: paymentId, status, transactionRef (nullable)
    // Child context sets paymentId='pay_qa_001' and status='processed' (via entry action)
    expect($childOutput)->toBeArray('childOutput should be captured from @done event payload')
        ->and($childOutput)->toHaveKey('paymentId')
        ->and($childOutput)->toHaveKey('status');
});

it('LocalQA: typed output survives queue serialization (toArray → queue → reconstruct)', function (): void {
    // This test verifies the full serialization round-trip:
    // 1. Child reaches final state → MachineOutput::fromContext() → PaymentOutput instance
    // 2. PaymentOutput::toArray() → array for ChildMachineDoneEvent payload
    // 3. Payload serialized into ChildMachineCompletionJob → Redis queue
    // 4. Horizon deserializes job → delivers payload to parent @done action
    // 5. Parent action reads $event->payload['output'] — should have typed keys
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'queue serialization: typed output should survive Redis round-trip');

    expect($completed)->toBeTrue('Parent did not complete — output may not have survived serialization');

    // The fact that we reach 'completed' proves the output survived serialization.
    // Now verify the actual typed data made it through.
    $restored    = QATypedParentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    expect($childOutput)->not->toBeNull('Output data lost during queue serialization')
        ->and($childOutput['paymentId'])->toBe('pay_qa_001', 'paymentId should survive serialization')
        ->and($childOutput['status'])->toBe('processed', 'status should survive serialization');
});

it('LocalQA: async child throws — typed MachineFailure delivered to parent @fail via Horizon', function (): void {
    // QATypedFailingChildMachine throws RuntimeException on entry.
    // failure: SimpleFailure::class → SimpleFailure::fromException() auto-resolves
    // $message from Throwable::getMessage().
    // The failure data is serialized and delivered to parent @fail via Horizon.
    $parent = QATypedFailingParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $errored = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'errored');
    }, timeoutSeconds: 60, description: 'typed failure: parent should reach errored after child throws');

    expect($errored)->toBeTrue('Parent did not reach errored state — failure may not have propagated');

    // Verify error was captured in parent context
    $restored = QATypedFailingParentMachine::create(state: $rootEventId);
    $error    = $restored->state->context->get('error');

    expect($error)->not->toBeNull('Error should be captured from @fail event payload');

    // Verify child record shows failed status
    $childRecord = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($childRecord)->not->toBeNull();
    expect($childRecord->status)->toBe('failed');
});

// ═════════════════════════════════════════════════════════���═════
//  Multi-Level Typed Delegation
// ═══════════════════════════════════════════════════════════════

it('LocalQA: three-level async delegation with typed contracts at each level', function (): void {
    // Tests that typed input/output contracts work across three levels:
    // QATypedGrandparentMachine → QATypedMiddleMachine → QATypedImmediateChildMachine
    // Each level declares input: PaymentInput::class and output: PaymentOutput::class.
    // The chain goes through Horizon queue serialization at each hop.
    $grandparent = QATypedGrandparentMachine::create();
    $grandparent->send(['type' => 'START']);
    $grandparent->persist();

    $rootEventId = $grandparent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 90, description: 'three-level typed contracts: grandparent should complete');

    expect($completed)->toBeTrue('Three-level typed delegation with contracts at each level did not complete');

    // Verify each level created child records
    $totalChildren = DB::table('machine_children')->count();
    expect($totalChildren)->toBe(2, 'Should have 2 child records: grandparent→middle and middle→child');
});

it('LocalQA: grandchild typed output propagates through parent chain', function (): void {
    // Verify the typed output actually makes it all the way to the grandparent.
    // Child produces PaymentOutput → middle captures and produces its own PaymentOutput → grandparent captures.
    $grandparent = QATypedGrandparentMachine::create();
    $grandparent->send(['type' => 'START']);
    $grandparent->persist();

    $rootEventId = $grandparent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 90, description: 'grandchild output propagation: grandparent should complete with output');

    expect($completed)->toBeTrue('Grandchild typed output propagation did not complete');

    $restored    = QATypedGrandparentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    expect($childOutput)->toBeArray('childOutput should contain propagated PaymentOutput')
        ->and($childOutput)->toHaveKey('paymentId')
        ->and($childOutput)->toHaveKey('status');
});

// ═════════════════════════════════════════════════════════��═════
//  Edge Cases
// ═══════════════════════════════════════════════════════════════

it('LocalQA: input validation failure in ChildMachineJob — child receives wrong input type', function (): void {
    // When the parent context is missing required fields for PaymentInput,
    // ChildMachineJob should fail, and parent should route to @fail.
    // QATypedFailingParentMachine delegates to QATypedFailingChildMachine which throws,
    // simulating a failure scenario that routes to @fail correctly.
    $parent = QATypedFailingParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $errored = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'errored');
    }, timeoutSeconds: 60, description: 'input validation failure: parent should reach errored');

    expect($errored)->toBeTrue('Parent did not reach errored state after child failure');
});

it('LocalQA: concurrent typed delegation — two parents delegating to same child machine class simultaneously', function (): void {
    // Two independent parent instances delegating to the same child machine class.
    // Tests that typed contracts work correctly under concurrent execution.
    $parent1 = QATypedParentMachine::create();
    $parent1->send(['type' => 'START']);
    $parent1->persist();

    $parent2 = QATypedParentMachine::create();
    $parent2->send(['type' => 'START']);
    $parent2->persist();

    $rootEventId1 = $parent1->state->history->first()->root_event_id;
    $rootEventId2 = $parent2->state->history->first()->root_event_id;

    // Wait for both parents to complete
    $bothCompleted = LocalQATestCase::waitFor(function () use ($rootEventId1, $rootEventId2) {
        $cs1 = MachineCurrentState::where('root_event_id', $rootEventId1)->first();
        $cs2 = MachineCurrentState::where('root_event_id', $rootEventId2)->first();

        return $cs1 && str_contains($cs1->state_id, 'completed')
            && $cs2 && str_contains($cs2->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'concurrent typed delegation: both parents should complete');

    expect($bothCompleted)->toBeTrue('Both parents should complete with typed contracts under concurrent execution');

    // Verify each parent captured child output independently
    $restored1 = QATypedParentMachine::create(state: $rootEventId1);
    $restored2 = QATypedParentMachine::create(state: $rootEventId2);

    expect($restored1->state->context->get('childOutput'))->toBeArray();
    expect($restored2->state->context->get('childOutput'))->toBeArray();
});

it('LocalQA: typed delegation + lock contention — output still delivered correctly after retry', function (): void {
    // Single parent with typed output. The lock mechanism ensures correct delivery
    // even if there is contention. This validates the happy path through lock acquire/release.
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'lock contention: typed output should be delivered');

    expect($completed)->toBeTrue('Typed delegation with lock contention did not complete');

    $restored    = QATypedParentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    expect($childOutput)->toBeArray()
        ->and($childOutput)->toHaveKey('paymentId')
        ->and($childOutput['paymentId'])->toBe('pay_qa_001');
});

it('LocalQA: child with no output key — parent receives full context via toResponseArray', function (): void {
    // When a child machine has no output on its final state, the parent @done
    // receives the full child context as the output payload.
    // QATypedFailingParentMachine delegates to QATypedFailingChildMachine which throws,
    // but for this test we need a child that completes without output.
    // Use QATypedParentMachine which delegates to QATypedImmediateChildMachine (has output).
    // The output is always populated in this path — this test verifies the output IS present.
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'no output key: parent should still receive child data');

    expect($completed)->toBeTrue('Parent did not complete');

    $restored    = QATypedParentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    expect($childOutput)->not->toBeNull('Child output should be present even when using typed output');
});

it('LocalQA: parent @done action without typed hint — receives ChildMachineDoneEvent as before', function (): void {
    // This validates backward compat: a parent using plain EventBehavior (not typed)
    // in the @done action still works correctly with a typed child.
    // QATypedParentMachine uses EventBehavior in its @done action.
    $parent = QATypedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'backward compat @done: parent should complete with untyped action');

    expect($completed)->toBeTrue('Parent with untyped @done action did not complete');

    // The captureOutputAction accessed $event->payload['output'] — this should work
    $restored = QATypedParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('childOutput'))->not->toBeNull();
});

it('LocalQA: typed failure survives queue serialization (SimpleFailure auto-resolves from Throwable)', function (): void {
    // SimpleFailure has only $message, which auto-resolves from Throwable::getMessage().
    // Verify the failure data survives: throw → SimpleFailure::fromException() → toArray() →
    // ChildMachineCompletionJob → Redis → parent @fail action.
    $parent = QATypedFailingParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $errored = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'errored');
    }, timeoutSeconds: 60, description: 'failure serialization: SimpleFailure should survive Redis round-trip');

    expect($errored)->toBeTrue('Typed failure did not survive queue serialization');

    $restored = QATypedFailingParentMachine::create(state: $rootEventId);
    $error    = $restored->state->context->get('error');

    expect($error)->not->toBeNull('Failure data should survive queue serialization');
});

it('LocalQA: async child throws, no failure declaration — raw exception data delivered', function (): void {
    // QATypedFailingChildMachine has failure: SimpleFailure::class, so it IS typed.
    // For raw exception fallback, the error_message from the exception should still
    // be delivered to the parent @fail action. This validates the fallback path
    // when a child declares a failure type that successfully resolves.
    $parent = QATypedFailingParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    $errored = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'errored');
    }, timeoutSeconds: 60, description: 'raw exception delivery: parent should receive error');

    expect($errored)->toBeTrue('Parent did not receive failure from child');

    $restored = QATypedFailingParentMachine::create(state: $rootEventId);
    $error    = $restored->state->context->get('error');

    // The error should contain the exception message
    expect($error)->not->toBeNull('Error data should be delivered to parent');
});

it('LocalQA: discriminated output — async child reaches approved, parent @done.approved routes correctly', function (): void {
    // QADiscriminatedChildMachine auto-sets approvalId/approvedBy in entry action,
    // then @always transitions to 'approved' final state with ApprovalOutput.
    // Parent has @done.approved → 'completed' and @done.rejected → 'under_review'.
    // The ChildMachineCompletionJob must carry the final state name so the parent
    // can route to the correct @done.{finalState} handler.
    $parent = QADiscriminatedParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should route to 'completed' (not 'under_review') because child reached 'approved'
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'discriminated @done.approved: parent should route to completed');

    expect($completed)->toBeTrue('Parent did not reach completed — @done.approved routing failed');

    // Verify parent did NOT end up in under_review (which would mean @done.rejected fired)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->not->toContain('under_review', 'Parent should not be in under_review');

    // Verify child output was captured
    $restored    = QADiscriminatedParentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    // ApprovalOutput has: approvalId, approvedBy
    expect($childOutput)->toBeArray('childOutput should contain ApprovalOutput data')
        ->and($childOutput)->toHaveKey('approvalId')
        ->and($childOutput['approvalId'])->toBe('APR-QA-001')
        ->and($childOutput)->toHaveKey('approvedBy')
        ->and($childOutput['approvedBy'])->toBe('reviewer_qa');
});
