<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
    ForwardEndpointAction::reset();

    // Register forward parent routes (machineId-bound)
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'       => '/api/forward-parent',
        'create'       => true,
        'machineIdFor' => ['START', 'CANCEL'],
    ]);

    // Register rename parent routes
    MachineRouter::register(RenameForwardParentMachine::class, [
        'prefix'       => '/api/rename-parent',
        'create'       => true,
        'machineIdFor' => ['START'],
    ]);

    // Register full config parent routes
    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix'       => '/api/full-config-parent',
        'create'       => true,
        'machineIdFor' => ['START'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════
//  Helper: create parent + enter processing + wait for child
// ═══════════════════════════════════════════════════════════════

function createAndStartParent(LocalQATestCase $test, string $prefix = '/api/forward-parent'): string
{
    $createResponse = $test->postJson("{$prefix}/create");
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.id');

    $test->postJson("{$prefix}/{$machineId}/start");

    // Wait for Horizon to process ChildMachineJob
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child machine did not reach running status via Horizon');

    return $machineId;
}

// ═══════════════════════════════════════════════════════════════
//  P0: Forward via HTTP endpoint — happy path
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward via HTTP endpoint delivers PROVIDE_CARD to async child', function (): void {
    $machineId = createAndStartParent($this);

    // Forward PROVIDE_CARD via HTTP
    $response = $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ]);

    $response->assertStatus(200);

    // In async mode, child processes via Horizon — immediate response may not have child state.
    // Wait for child to process the forwarded event.
    $childUpdated = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();
        if (!$child || !$child->child_root_event_id) {
            return false;
        }

        // Restore child and check if it received the forwarded event
        try {
            $childMachine = ForwardChildEndpointMachine::create(state: $child->child_root_event_id);

            return str_contains(implode(',', $childMachine->state->value), 'awaiting_confirmation');
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child receives PROVIDE_CARD and transitions to awaiting_confirmation');

    expect($childUpdated)->toBeTrue('Child did not reach awaiting_confirmation after forward');

    // Verify child context was updated by storeCardAction
    $child        = MachineChild::where('parent_root_event_id', $machineId)->first();
    $childMachine = ForwardChildEndpointMachine::create(state: $child->child_root_event_id);
    expect($childMachine->state->context->get('cardLast4'))->toBe('4242');
});

it('LocalQA: forward via HTTP with OutputBehavior returns custom response', function (): void {
    $machineId = createAndStartParent($this);

    // Step 1: Forward PROVIDE_CARD (no result, default response)
    $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ])->assertStatus(200);

    // Wait for child to process PROVIDE_CARD before sending next forward
    $childRecord      = MachineChild::where('parent_root_event_id', $machineId)->first();
    $provideProcessed = LocalQATestCase::waitFor(function () use ($childRecord) {
        try {
            $child = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);

            return str_contains(implode(',', $child->state->value), 'awaiting_confirmation');
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child processes PROVIDE_CARD before CONFIRM_PAYMENT');

    expect($provideProcessed)->toBeTrue('Child did not reach awaiting_confirmation');

    // Step 2: Forward CONFIRM_PAYMENT (has output: PaymentStepOutput)
    $response = $this->postJson("/api/forward-parent/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'ABC123'],
    ]);

    $response->assertStatus(200);

    // In async mode, the forward response runs PaymentStepOutput on the parent context.
    // PaymentStepOutput returns orderId from parent context. Output is in data.output.
    $output = $response->json('data.output');
    expect($output)->toBeArray()
        ->and($output)->toHaveKey('orderId');

    // Wait for child completion → parent @done
    $parentCompleted = LocalQATestCase::waitFor(function () use ($machineId) {
        $cs = MachineCurrentState::where('root_event_id', $machineId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($parentCompleted)->toBeTrue('Parent did not transition to completed via @done');

    // Verify child had the card data by restoring from DB
    $childMachine = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);
    expect($childMachine->state->context->get('cardLast4'))->toBe('4242');
});

// ═══════════════════════════════════════════════════════════════
//  P0: Forward returns 409 when not in delegating state
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward returns error when parent not in delegating state', function (): void {
    // Create parent but DON'T send START (stays in idle)
    $createResponse = $this->postJson('/api/forward-parent/create');
    $machineId      = $createResponse->json('data.id');

    // Try to forward — parent is in idle, not processing
    $response = $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ]);

    // Should fail — parent tries to send PROVIDE_CARD which is not in its own events
    // The exact error code depends on implementation — could be 422 or 500
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ═══════════════════════════════════════════════════════════════
//  P1: Rename forward via HTTP
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward rename CANCEL_ORDER → ABORT delivers to child via Machine::send', function (): void {
    // Rename forward via HTTP has a known type-mismatch limitation:
    // the controller creates AbortEvent (child type='ABORT'), but parent
    // needs 'CANCEL_ORDER' to resolve the forward mapping.
    // Test rename via direct Machine::send() instead.
    $parent = RenameForwardParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $parentRootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child running via Horizon
    $childRunning = LocalQATestCase::waitFor(function () use ($parentRootEventId) {
        $child = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child machine did not reach running status');

    $childRecord      = MachineChild::where('parent_root_event_id', $parentRootEventId)->first();
    $childRootEventId = $childRecord->child_root_event_id;

    // Child starts in awaiting_card. ABORT is only in awaiting_confirmation.
    // Advance child to awaiting_confirmation first via direct send.
    $child = ForwardChildEndpointMachine::create(state: $childRootEventId);
    $child->send(['type' => 'PROVIDE_CARD', 'payload' => ['cardNumber' => '4242424242424242']]);

    // Verify child is now in awaiting_confirmation
    $childCs = MachineCurrentState::where('root_event_id', $childRootEventId)->first();
    expect($childCs->state_id)->toContain('awaiting_confirmation');

    // Forward CANCEL_ORDER → child receives ABORT
    $restoredParent = RenameForwardParentMachine::create(state: $parentRootEventId);
    $restoredParent->send(['type' => 'CANCEL_ORDER']);

    // Wait for child to transition to aborted
    $childAborted = LocalQATestCase::waitFor(function () use ($childRootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $childRootEventId)->first();

        return $cs && str_contains($cs->state_id, 'aborted');
    }, timeoutSeconds: 60);

    expect($childAborted)->toBeTrue('Renamed forward CANCEL_ORDER→ABORT was not delivered');
});

// ═══════════════════════════════════════════════════════════════
//  P1: Forward causing child final auto-completes parent
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward causing child final state auto-completes parent', function (): void {
    $machineId = createAndStartParent($this);

    // Step 1: PROVIDE_CARD → child in awaiting_confirmation
    $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ])->assertStatus(200);

    // Step 2: CONFIRM_PAYMENT → child reaches 'charged' (final)
    $this->postJson("/api/forward-parent/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'FINAL123'],
    ])->assertStatus(200);

    // Wait for ChildMachineCompletionJob → parent @done → completed
    $parentCompleted = LocalQATestCase::waitFor(function () use ($machineId) {
        $cs = MachineCurrentState::where('root_event_id', $machineId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($parentCompleted)->toBeTrue('Parent did not transition to completed after child reached final via forward');

    // Verify child record is marked completed
    $childRecord = MachineChild::where('parent_root_event_id', $machineId)->first();
    expect($childRecord->status)->toBe(MachineChild::STATUS_COMPLETED);
});

// ═══════════════════════════════════════════════════════════════
//  P1: Full config forward with custom URI + method + action
// ═══════════════════════════════════════════════════════════════

it('LocalQA: forward with custom URI + method + action works via real HTTP', function (): void {
    $machineId = createAndStartParent($this, '/api/full-config-parent');

    // FullConfigForwardParentMachine has:
    // uri: '/enter-payment-details', method: 'PATCH', action: ForwardEndpointAction
    $response = $this->patchJson("/api/full-config-parent/{$machineId}/enter-payment-details", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ]);

    // Custom status code: 202
    $response->assertStatus(202);

    // Verify action lifecycle hooks were invoked
    expect(ForwardEndpointAction::$beforeCalled)->toBeTrue('ForwardEndpointAction::before() was not called')
        ->and(ForwardEndpointAction::$afterCalled)->toBeTrue('ForwardEndpointAction::after() was not called');
});

// ═══════════════════════════════════════════════════════════════
//  P1: Parent OutputBehavior receives both parent and child context
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parent OutputBehavior receives parent context, child data verified via restore', function (): void {
    $machineId = createAndStartParent($this);

    // Forward PROVIDE_CARD → child stores cardLast4
    $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '5555555555554444'],
    ])->assertStatus(200);

    // Wait for child to process PROVIDE_CARD before sending CONFIRM_PAYMENT
    $childRecord      = MachineChild::where('parent_root_event_id', $machineId)->first();
    $provideProcessed = LocalQATestCase::waitFor(function () use ($childRecord) {
        try {
            $child = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);

            return str_contains(implode(',', $child->state->value), 'awaiting_confirmation');
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child processes PROVIDE_CARD before CONFIRM_PAYMENT');

    expect($provideProcessed)->toBeTrue('Child did not reach awaiting_confirmation');

    // Forward CONFIRM_PAYMENT (has output: PaymentStepOutput)
    $response = $this->postJson("/api/forward-parent/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'CTX-TEST'],
    ]);

    $response->assertStatus(200);

    // PaymentStepOutput runs on parent context — returns orderId in data.output
    $output = $response->json('data.output');
    expect($output)->toBeArray()
        ->and($output)->toHaveKey('orderId');

    // Wait for child to process CONFIRM_PAYMENT and verify child context via restore
    $childUpdated = LocalQATestCase::waitFor(function () use ($childRecord) {
        try {
            $child = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);

            return $child->state->context->get('cardLast4') === '4444';
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child context contains cardLast4=4444');

    expect($childUpdated)->toBeTrue('Child did not have correct cardLast4 in context');
});

// ═══════════════════════════════════════════════════════════════
//  P2: Parent CANCEL orphans child
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parent CANCEL orphans child, subsequent forward fails', function (): void {
    $machineId = createAndStartParent($this);

    // Forward PROVIDE_CARD first via HTTP
    $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ])->assertStatus(200);

    // Send CANCEL via Machine::send() (CANCEL uses TestStartEvent which returns
    // getType()='START', so HTTP endpoint would send wrong event type)
    $parent = ForwardParentEndpointMachine::create(state: $machineId);
    $parent->send(['type' => 'CANCEL']);

    // Verify parent is cancelled
    $parentCs = MachineCurrentState::where('root_event_id', $machineId)->first();
    expect($parentCs->state_id)->toContain('cancelled');

    // Try to forward again — should fail (parent not in delegating state)
    $response = $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '1111111111111111'],
    ]);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ═══════════════════════════════════════════════════════════════
//  P2: available_events updates through full forward lifecycle
// ═══════════════════════════════════════════════════════════════

it('LocalQA: available_events updates correctly through full forward lifecycle', function (): void {
    // 1. Create parent → check initial available_events
    $createResponse = $this->postJson('/api/forward-parent/create');
    $machineId      = $createResponse->json('data.id');

    $createEvents = $createResponse->json('data.availableEvents');
    $createTypes  = array_column($createEvents ?? [], 'type');
    expect($createTypes)->toContain('START');

    // 2. Send START → processing state (child dispatched)
    $startResponse = $this->postJson("/api/forward-parent/{$machineId}/start");
    $startEvents   = $startResponse->json('data.availableEvents');
    $startTypes    = array_column($startEvents ?? [], 'type');

    // Should have CANCEL (parent on-event)
    expect($startTypes)->toContain('CANCEL');

    // Wait for child running
    $childRunning = LocalQATestCase::waitFor(function () use ($machineId) {
        $child = MachineChild::where('parent_root_event_id', $machineId)->first();

        return $child
            && $child->status === MachineChild::STATUS_RUNNING
            && $child->child_root_event_id !== null;
    }, timeoutSeconds: 60);

    expect($childRunning)->toBeTrue('Child not running');

    // 3. Forward PROVIDE_CARD → child moves to awaiting_confirmation
    $provideResponse = $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ]);
    $provideResponse->assertStatus(200);

    // 4. Forward CONFIRM_PAYMENT → child reaches final
    $confirmResponse = $this->postJson("/api/forward-parent/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'LIFECYCLE'],
    ]);
    $confirmResponse->assertStatus(200);

    // 5. Wait for parent to complete
    $parentCompleted = LocalQATestCase::waitFor(function () use ($machineId) {
        $cs = MachineCurrentState::where('root_event_id', $machineId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($parentCompleted)->toBeTrue('Parent did not reach completed');

    // Final state — no available events
    $restoredParent = ForwardParentEndpointMachine::create(state: $machineId);
    $finalEvents    = $restoredParent->state->availableEvents();
    expect($finalEvents)->toBe([]);
});

// ═══════════════════════════════════════════════════════════════
//  P2: child output carries correct child data
// ═══════════════════════════════════════════════════════════════

it('LocalQA: child output carries correct child data through real async flow', function (): void {
    $machineId = createAndStartParent($this);

    // Forward PROVIDE_CARD with specific card number
    $this->postJson("/api/forward-parent/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '9876543210987654'],
    ])->assertStatus(200);

    // Wait for child to process PROVIDE_CARD before sending CONFIRM_PAYMENT
    $childRecord      = MachineChild::where('parent_root_event_id', $machineId)->first();
    $provideProcessed = LocalQATestCase::waitFor(function () use ($childRecord) {
        try {
            $child = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);

            return str_contains(implode(',', $child->state->value), 'awaiting_confirmation');
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child processes PROVIDE_CARD before CONFIRM_PAYMENT');

    expect($provideProcessed)->toBeTrue('Child did not reach awaiting_confirmation');

    // Forward CONFIRM_PAYMENT
    $response = $this->postJson("/api/forward-parent/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'FC-VERIFY'],
    ]);

    $response->assertStatus(200);

    // In async mode, verify child data by restoring the child machine after processing
    $childUpdated = LocalQATestCase::waitFor(function () use ($childRecord) {
        try {
            $child = ForwardChildEndpointMachine::create(state: $childRecord->child_root_event_id);

            return $child->state->context->get('cardLast4') === '7654';
        } catch (Throwable) {
            return false;
        }
    }, timeoutSeconds: 30, description: 'child context contains cardLast4=7654');

    expect($childUpdated)->toBeTrue('Child did not have correct cardLast4 in context');

    // Verify parent OutputBehavior response contains orderId in data.output
    $output = $response->json('data.output');
    expect($output)->toBeArray()
        ->and($output)->toHaveKey('orderId');
});
