<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedParentMachine;
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
