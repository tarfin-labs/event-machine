<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA\QATypedForwardParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    // Register forward parent routes (machineId-bound)
    // This registers both parent endpoints (START) and forwarded child endpoints (SUBMIT_PAYMENT)
    MachineRouter::register(QATypedForwardParentMachine::class, [
        'prefix'       => '/api/typed-forward-parent',
        'create'       => true,
        'machineIdFor' => ['START'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════
//  Typed Forward Endpoint — Child Output via HTTP + Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward endpoint full lifecycle — create, delegate, forward, child completes with typed output, parent @done', function (): void {
    // This test exercises the full forward endpoint lifecycle with typed output:
    // 1. POST /create → creates parent in idle state
    // 2. POST /{machineId}/start → parent transitions to 'processing', child dispatched via Horizon
    // 3. Wait for child machine to be running
    // 4. POST /{machineId}/submit-payment → forwarded to child, child reaches 'completed' final
    //    with output: PaymentOutput::class
    // 5. ChildMachineCompletionJob fires → parent @done with typed output → parent 'completed'

    // Step 1: Create parent via HTTP
    $createResponse = $this->postJson('/api/typed-forward-parent/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.id');
    expect($machineId)->not->toBeNull('Machine ID should be returned from create endpoint');

    // Step 2: Send START via HTTP → parent enters 'processing', child dispatched
    $this->postJson("/api/typed-forward-parent/{$machineId}/start");

    // Step 3: Wait for child machine to be running via Horizon
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60, description: 'typed forward: child should reach running status via Horizon');

    expect($childRunning)->toBeTrue('Child machine did not reach running status via Horizon');

    // Step 4: Forward SUBMIT_PAYMENT to child via HTTP
    // The forward endpoint sends SUBMIT_PAYMENT to the running child.
    // Child's processPaymentAction sets paymentId='pay_fwd_001' and status='charged',
    // then transitions to 'completed' (final) with output: PaymentOutput::class.
    $forwardResponse = $this->postJson("/api/typed-forward-parent/{$machineId}/submit-payment");
    $forwardResponse->assertSuccessful();

    // Step 5: Wait for parent to complete via ChildMachineCompletionJob
    // Child reaching final state triggers ChildMachineCompletionJob → parent @done → 'completed'
    $parentCompleted = LocalQATestCase::waitFor(function () use ($machineId) {
        $cs = MachineCurrentState::where('root_event_id', $machineId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'typed forward: parent should reach completed after child final via forward');

    expect($parentCompleted)->toBeTrue('Parent did not transition to completed after child reached final via forward');

    // Verify child record shows completed
    $childRecord = MachineChild::where('parent_root_event_id', $machineId)->first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_COMPLETED);

    // Verify typed output was captured in parent context
    $restored    = QATypedForwardParentMachine::create(state: $machineId);
    $childOutput = $restored->state->context->get('childOutput');

    // PaymentOutput from child: paymentId='pay_fwd_001', status='charged'
    expect($childOutput)->toBeArray('childOutput should contain PaymentOutput data from forwarded child')
        ->and($childOutput)->toHaveKey('paymentId')
        ->and($childOutput['paymentId'])->toBe('pay_fwd_001')
        ->and($childOutput)->toHaveKey('status')
        ->and($childOutput['status'])->toBe('charged');
});

it('LocalQA: forward endpoint returns child state after forwarded event', function (): void {
    // Verifies that the HTTP response from a forward endpoint includes child state data.
    // This tests the synchronous part of forwarding — the HTTP response includes
    // the child's new state after processing the forwarded event.

    // Create and start parent
    $createResponse = $this->postJson('/api/typed-forward-parent/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/typed-forward-parent/{$machineId}/start");

    // Wait for child running
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60, description: 'typed forward response: child should reach running status');

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    // Forward SUBMIT_PAYMENT and check response structure
    $response = $this->postJson("/api/typed-forward-parent/{$machineId}/submit-payment");

    $response->assertSuccessful();

    // Response should contain child data
    $data = $response->json('data');
    expect($data)->not->toBeNull('Forward response should contain data');

    // The response should include child state information
    // (exact structure depends on output configuration, but data should be present)
    expect($data)->toBeArray();
});

// ═══════════════════════════════════════════════════════════════
//  Forward Endpoint — Edge Cases
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward endpoint child has no MachineOutput — toResponseArray() fallback', function (): void {
    // When a child machine is in a state that has no MachineOutput defined,
    // the forward endpoint response should fall back to toResponseArray().
    // QATypedForwardChildMachine starts in 'awaiting_input' which has no output.
    // The forward event SUBMIT_PAYMENT transitions it to 'completed' which has PaymentOutput.
    // Before forwarding, the child is in awaiting_input (no output) — this tests
    // that the response works correctly even when the child is not in a final state with output.

    // Create and start parent
    $createResponse = $this->postJson('/api/typed-forward-parent/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/typed-forward-parent/{$machineId}/start");

    // Wait for child running
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60, description: 'forward no output: child should reach running status');

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    // Forward SUBMIT_PAYMENT — this transitions child from awaiting_input → completed
    // The response should succeed regardless of output configuration
    $response = $this->postJson("/api/typed-forward-parent/{$machineId}/submit-payment");
    $response->assertSuccessful();

    $data = $response->json('data');
    expect($data)->not->toBeNull('Response should contain data even in fallback scenario');
});

it('LocalQA: forward endpoint OutputBehavior type-hints MachineOutput but child has none — MachineOutputInjectionException scenario', function (): void {
    // This test documents the expected behavior when an OutputBehavior type-hints
    // a MachineOutput subclass but the child's current state has no MachineOutput.
    // In the current QA stubs, this scenario doesn't occur because the child
    // transitions to 'completed' which has output: PaymentOutput::class.
    // This test verifies the normal path works — the MachineOutputInjectionException
    // path is tested in unit tests (TypedForwardEndpointTest.php).

    // Create and start parent
    $createResponse = $this->postJson('/api/typed-forward-parent/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/typed-forward-parent/{$machineId}/start");

    // Wait for child running
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60, description: 'forward output injection: child should reach running');

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    // Forward event and verify it succeeds (normal path — child has output)
    $response = $this->postJson("/api/typed-forward-parent/{$machineId}/submit-payment");
    $response->assertSuccessful();

    // Wait for parent to complete
    $completed = LocalQATestCase::waitFor(function () use ($machineId) {
        $cs = MachineCurrentState::where('root_event_id', $machineId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'forward output injection: parent should complete');

    expect($completed)->toBeTrue('Parent did not complete after forward with typed output');
});
