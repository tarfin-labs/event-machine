<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedGrandparentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedFailingGrandparentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Typed Deep Delegation — Three-Level Chain via Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: three-level typed delegation — grandparent → parent → child, output propagates via Horizon', function (): void {
    // Chain: QATypedGrandparentMachine → QATypedMiddleMachine → QATypedImmediateChildMachine
    //
    // Data flow:
    // 1. Grandparent context has orderId='ORD-DEEP-QA', amount=500
    // 2. Grandparent delegates with input: PaymentInput::class → PaymentInput(orderId, amount)
    // 3. Middle machine receives input, merges into context, then delegates to child with same input
    // 4. Child receives input, sets status='processed' via entry action, reaches 'completed' final
    // 5. Child output: PaymentOutput::fromContext() → PaymentOutput(paymentId, status)
    // 6. ChildMachineCompletionJob carries output back to middle → middle @done → middle 'completed'
    // 7. Middle's 'completed' state has output: PaymentOutput::class → computed from middle's context
    // 8. ChildMachineCompletionJob carries middle output back to grandparent → grandparent @done
    //
    // Each hop goes through Redis queue serialization. This test verifies the entire
    // three-level chain works end-to-end with typed input/output contracts.
    $grandparent = QATypedGrandparentMachine::create();
    $grandparent->send(['type' => 'START']);
    $grandparent->persist();

    $rootEventId = $grandparent->state->history->first()->root_event_id;

    // Three-level chain via Horizon requires more time — use 90s timeout
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 90, description: 'deep typed delegation: grandparent → middle → child chain completion');

    expect($completed)->toBeTrue('Three-level typed delegation chain did not complete via Horizon');

    // Verify: 2 machine_children records (grandparent→middle, middle→child)
    $directChild = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($directChild)->not->toBeNull('Grandparent should have a direct child record');
    expect($directChild->status)->toBe('completed');

    // Total children across all levels
    $totalChildren = DB::table('machine_children')->count();
    expect($totalChildren)->toBe(2, 'Should be 2 total: grandparent→middle and middle→child');

    // Verify typed output propagated to grandparent context
    $restored    = QATypedGrandparentMachine::create(state: $rootEventId);
    $childOutput = $restored->state->context->get('childOutput');

    // PaymentOutput from the middle machine (which got it from the child)
    // Middle captures child output then produces its own PaymentOutput on completion
    expect($childOutput)->toBeArray('childOutput should contain propagated PaymentOutput')
        ->and($childOutput)->toHaveKey('paymentId')
        ->and($childOutput)->toHaveKey('status');
});

it('LocalQA: three-level typed delegation — child fails, failure propagates up through chain via Horizon', function (): void {
    // Chain: QATypedFailingGrandparentMachine → QATypedFailingMiddleMachine → QATypedFailingChildMachine
    //
    // Failure flow:
    // 1. Grandparent delegates to middle via Horizon (ChildMachineJob)
    // 2. Middle starts in 'delegating', dispatches child via Horizon (ChildMachineJob)
    // 3. Child throws RuntimeException('Payment gateway timeout') on entry
    // 4. failure: SimpleFailure::class → SimpleFailure::fromException() auto-resolves $message
    // 5. ChildMachineCompletionJob(success=false) → middle @fail → middle 'failed' final
    // 6. Middle reaching final state triggers ChildMachineCompletionJob to grandparent
    // 7. Grandparent @fail → grandparent 'errored' final
    //
    // Each failure propagation hop goes through queue serialization.
    // This tests that failure propagation works correctly through the entire chain.
    $grandparent = QATypedFailingGrandparentMachine::create();
    $grandparent->send(['type' => 'START']);
    $grandparent->persist();

    $rootEventId = $grandparent->state->history->first()->root_event_id;

    // Failure chain: child throws → middle @fail → grandparent @fail — 90s for three levels
    $errored = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'errored');
    }, timeoutSeconds: 90, description: 'deep typed failure: child throws → middle @fail → grandparent @fail');

    expect($errored)->toBeTrue('Three-level failure chain did not propagate to grandparent via Horizon');

    // Verify grandparent captured error from the chain
    $restored = QATypedFailingGrandparentMachine::create(state: $rootEventId);
    $error    = $restored->state->context->get('error');

    expect($error)->not->toBeNull('Error should be captured in grandparent context from failure chain');

    // Verify middle machine also reached failed state
    $middleChild = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($middleChild)->not->toBeNull('Grandparent should have a child record for middle machine');
    // The middle's final state is 'failed' — the grandparent sees this as child failure
    expect($middleChild->status)->toBe('failed', 'Middle machine child record should show failed status');

    // Verify the entire chain created 2 child records
    $totalChildren = DB::table('machine_children')->count();
    expect($totalChildren)->toBe(2, 'Should be 2: grandparent→middle and middle→child');
});
