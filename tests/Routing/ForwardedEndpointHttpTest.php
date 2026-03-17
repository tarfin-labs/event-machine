<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| createAndStartForwardMachine: Creates a parent machine via HTTP /create,
| then sends START via HTTP. With the sync queue driver, ChildMachineJob
| runs immediately, creating the child machine in running status.
*/

/**
 * Create a parent machine, send START, and verify child is running.
 *
 * @return string The parent machine's root_event_id (machineId).
 */
function createAndStartForwardMachine(object $testCase, string $prefix = '/api/forward'): string
{
    $createResponse = $testCase->postJson("{$prefix}/create");
    $createResponse->assertStatus(201);

    $machineId = $createResponse->json('data.machine_id');

    $testCase->postJson("{$prefix}/{$machineId}/start");

    // Sync queue runs ChildMachineJob immediately — verify child is running
    $childRecord = MachineChild::where('parent_root_event_id', $machineId)->first();

    expect($childRecord)->not->toBeNull()
        ->and($childRecord->status)->toBe(MachineChild::STATUS_RUNNING)
        ->and($childRecord->child_root_event_id)->not->toBeNull();

    return $machineId;
}

/**
 * Create parent, start, verify child for FullConfigForwardParentMachine.
 */
function createAndStartFullConfigMachine(object $testCase): string
{
    $createResponse = $testCase->postJson('/api/full-config/create');
    $createResponse->assertStatus(201);

    $machineId = $createResponse->json('data.machine_id');

    $testCase->postJson("/api/full-config/{$machineId}/start");

    return $machineId;
}

/**
 * Create parent, start, verify child for FqcnForwardParentMachine.
 */
function createAndStartFqcnMachine(object $testCase): string
{
    $createResponse = $testCase->postJson('/api/fqcn/create');
    $createResponse->assertStatus(201);

    $machineId = $createResponse->json('data.machine_id');

    $testCase->postJson("/api/fqcn/{$machineId}/start");

    return $machineId;
}

// ─── Setup ────────────────────────────────────────────────────────────

beforeEach(function (): void {
    ForwardEndpointAction::reset();

    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'       => '/api/forward',
        'create'       => true,
        'machineIdFor' => ['START', 'CANCEL'],
    ]);

    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix'       => '/api/full-config',
        'create'       => true,
        'machineIdFor' => ['START'],
    ]);

    MachineRouter::register(FqcnForwardParentMachine::class, [
        'prefix'       => '/api/fqcn',
        'create'       => true,
        'machineIdFor' => ['START'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: Default Forwarded Response
// ═══════════════════════════════════════════════════════════════════════

test('it forwards event via parent endpoint and returns child state in response', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    // Default forwarded response includes child.value
    expect($data)->toHaveKey('child')
        ->and($data['child'])->toHaveKey('value')
        ->and($data['child']['value'])->toContain('forward_endpoint_child.awaiting_confirmation');
});

test('forwarded response includes parent machine_id and value', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data)->toHaveKey('machine_id')
        ->and($data['machine_id'])->toBe($machineId)
        ->and($data)->toHaveKey('value')
        ->and($data['value'])->toContain('forward_endpoint_parent.processing');
});

test('forwarded response includes child value and child context', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $child = $response->json('data.child');

    expect($child)->toHaveKeys(['value', 'context'])
        ->and($child['value'])->toContain('forward_endpoint_child.awaiting_confirmation')
        ->and($child['context'])->toBeArray();
});

test('child context reflects storeCardAction side effect', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    // ContextManager::toArray() wraps in 'data' key
    $childContextData = $response->json('data.child.context.data');

    // storeCardAction stores last 4 digits and sets status
    expect($childContextData)->toHaveKey('card_last4')
        ->and($childContextData['card_last4'])->toBe('1111')
        ->and($childContextData)->toHaveKey('status')
        ->and($childContextData['status'])->toBe('card_provided');
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: Sequential Forward Events
// ═══════════════════════════════════════════════════════════════════════

test('sequential forward events advance child through multiple states', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Step 1: PROVIDE_CARD -> child moves to awaiting_confirmation
    $provideResponse = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $provideResponse->assertStatus(200);
    expect($provideResponse->json('data.child.value'))
        ->toContain('forward_endpoint_child.awaiting_confirmation');

    // Step 2: CONFIRM_PAYMENT -> child moves to charged (final)
    // CONFIRM_PAYMENT has result: PaymentStepResult, so response is custom
    $confirmResponse = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmation_code' => 'CONF-001'],
    ]);

    $confirmResponse->assertStatus(200);

    $data = $confirmResponse->json('data');

    // PaymentStepResult returns custom keys
    expect($data)->toHaveKey('order_id')
        ->and($data)->toHaveKey('card_last4')
        ->and($data['card_last4'])->toBe('1111')
        ->and($data)->toHaveKey('child_step');
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: ResultBehavior with ForwardContext
// ═══════════════════════════════════════════════════════════════════════

test('it runs parent ResultBehavior and returns custom response', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // First forward PROVIDE_CARD to set up child state
    $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '5500000000000004'],
    ]);

    // CONFIRM_PAYMENT has result: PaymentStepResult
    $response = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmation_code' => 'CONF-ABC'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    // PaymentStepResult reads parent context.order_id and child context.card_last4
    expect($data)->toHaveKey('order_id')
        ->and($data)->toHaveKey('card_last4')
        ->and($data['card_last4'])->toBe('0004')
        ->and($data)->toHaveKey('child_step')
        ->and($data['child_step'])->toContain('forward_endpoint_child');
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: contextKeys Filtering
// ═══════════════════════════════════════════════════════════════════════

test('PROVIDE_CARD without contextKeys returns full child context', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    // ContextManager::toArray() wraps in 'data' key
    $childContextData = $response->json('data.child.context.data');

    // No contextKeys filtering on PROVIDE_CARD -- full child context
    expect($childContextData)->toHaveKey('order_id')
        ->and($childContextData)->toHaveKey('card_last4')
        ->and($childContextData)->toHaveKey('status');
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: MachineEndpointAction Lifecycle
// ═══════════════════════════════════════════════════════════════════════

test('it runs MachineEndpointAction before and after for forwarded endpoint', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    expect(ForwardEndpointAction::$beforeCalled)->toBeFalse()
        ->and(ForwardEndpointAction::$afterCalled)->toBeFalse();

    // FullConfigForwardParentMachine has action: ForwardEndpointAction on forward
    $response = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertSuccessful();

    expect(ForwardEndpointAction::$beforeCalled)->toBeTrue()
        ->and(ForwardEndpointAction::$afterCalled)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: Endpoint Customization
// ═══════════════════════════════════════════════════════════════════════

test('custom URI overrides auto-generated URI', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    // FullConfigForwardParentMachine has uri: '/enter-payment-details'
    $response = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertSuccessful();
});

test('custom method overrides default POST', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    // FullConfigForwardParentMachine has method: 'PATCH'
    // POST should not match
    $postResponse = $this->postJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $postResponse->assertStatus(405);

    // PATCH should match
    $patchResponse = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $patchResponse->assertSuccessful();
});

test('custom status code overrides default 200', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    // FullConfigForwardParentMachine has status: 202
    $response = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(202);
});

// ═══════════════════════════════════════════════════════════════════════
//  Event Validation
// ═══════════════════════════════════════════════════════════════════════

test('it validates request payload using child EventBehavior', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // ProvideCardEvent requires payload.card_number (string, min:13, max:19)
    // Send without card_number
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => [],
    ]);

    $response->assertStatus(422);
});

test('it returns 422 when child event validation fails with details', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Send with card_number too short
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '123'],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

test('it returns 422 when required payload field is missing', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Send empty body -- no payload at all
    $response = $this->postJson("/api/forward/{$machineId}/provide-card");

    $response->assertStatus(422);
});

// ═══════════════════════════════════════════════════════════════════════
//  State-Dependent Forwarding
// ═══════════════════════════════════════════════════════════════════════

test('forwarding fails when parent is not in delegating state', function (): void {
    // Create parent but do NOT send START -- parent stays in idle
    $createResponse = $this->postJson('/api/forward/create');
    $machineId      = $createResponse->json('data.machine_id');

    // Try to forward PROVIDE_CARD -- parent is in idle, not processing
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    // Should fail -- parent cannot forward from idle state
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

test('forwarding event not valid for child current state fails', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Child starts in awaiting_card. CONFIRM_PAYMENT is only valid in awaiting_confirmation.
    $response = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmation_code' => 'CONF-001'],
    ]);

    // Should fail -- child does not accept CONFIRM_PAYMENT in awaiting_card state
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ═══════════════════════════════════════════════════════════════════════
//  FQCN Forward
// ═══════════════════════════════════════════════════════════════════════

test('FQCN Format 1 forward works end-to-end via HTTP', function (): void {
    $machineId = createAndStartFqcnMachine($this);

    // FqcnForwardParentMachine: ProvideCardEvent::class (Format 1 FQCN)
    // Routes as PROVIDE_CARD -> PROVIDE_CARD (no rename)
    $response = $this->postJson("/api/fqcn/{$machineId}/provide-card", [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data)->toHaveKey('child')
        ->and($data['child']['value'])->toContain('forward_endpoint_child.awaiting_confirmation');
});

// ═══════════════════════════════════════════════════════════════════════
//  Parent Events Still Work Alongside Forwarded
// ═══════════════════════════════════════════════════════════════════════

test('parent CANCEL event still works when forward endpoints are registered', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // CANCEL is a parent event on the processing state (not forwarded).
    // ForwardParentEndpointMachine uses TestStartEvent for both START and CANCEL.
    // TestStartEvent::getType() returns 'START', so we must pass type explicitly.
    $response = $this->postJson("/api/forward/{$machineId}/cancel", [
        'type' => 'CANCEL',
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data['value'])->toContain('forward_endpoint_parent.cancelled');
});

// ═══════════════════════════════════════════════════════════════════════
//  Non-existent MachineId
// ═══════════════════════════════════════════════════════════════════════

test('forwarded endpoint with non-existent machineId returns error', function (): void {
    $response = $this->postJson('/api/forward/does-not-exist/provide-card', [
        'payload' => ['card_number' => '4111111111111111'],
    ]);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});
