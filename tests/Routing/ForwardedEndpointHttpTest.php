<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
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

    $machineId = $createResponse->json('data.id');

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

    $machineId = $createResponse->json('data.id');

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

    $machineId = $createResponse->json('data.id');

    $testCase->postJson("/api/fqcn/{$machineId}/start");

    return $machineId;
}

/**
 * Create parent, start, verify child for RenameForwardParentMachine.
 */
function createAndStartRenameMachine(object $testCase): string
{
    $createResponse = $testCase->postJson('/api/rename/create');
    $createResponse->assertStatus(201);

    $machineId = $createResponse->json('data.id');

    $testCase->postJson("/api/rename/{$machineId}/start");

    $childRecord = MachineChild::where('parent_root_event_id', $machineId)->first();

    expect($childRecord)->not->toBeNull()
        ->and($childRecord->status)->toBe(MachineChild::STATUS_RUNNING);

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

    MachineRouter::register(RenameForwardParentMachine::class, [
        'prefix'       => '/api/rename',
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
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    // Default forwarded response includes child.state
    expect($data)->toHaveKey('child')
        ->and($data['child'])->toHaveKey('state')
        ->and($data['child']['state'])->toContain('forward_endpoint_child.awaiting_confirmation')
        ->and($data['isProcessing'])->toBeFalse();
});

test('forwarded response includes parent machine_id and value', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data)->toHaveKey('id')
        ->and($data['id'])->toBe($machineId)
        ->and($data)->toHaveKey('state')
        ->and($data['state'])->toContain('forward_endpoint_parent.processing')
        ->and($data['isProcessing'])->toBeFalse();
});

test('forwarded default response includes availableEvents', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4242424242424242'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data)->toHaveKey('availableEvents')
        ->and($data['availableEvents'])->toBeArray()
        ->and($data['availableEvents'])->not->toBeEmpty()
        ->and($data['isProcessing'])->toBeFalse();
});

test('forwarded response includes child value and child context', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $child = $response->json('data.child');

    expect($child)->toHaveKeys(['state', 'output'])
        ->and($child['state'])->toContain('forward_endpoint_child.awaiting_confirmation')
        ->and($child['output'])->toBeArray()
        ->and($response->json('data.isProcessing'))->toBeFalse();
});

test('child context reflects storeCardAction side effect', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    // ContextManager::toArray() wraps in 'data' key
    $childContextData = $response->json('data.child.output.data');

    // storeCardAction stores last 4 digits and sets status
    expect($childContextData)->toHaveKey('cardLast4')
        ->and($childContextData['cardLast4'])->toBe('1111')
        ->and($childContextData)->toHaveKey('status')
        ->and($childContextData['status'])->toBe('card_provided')
        ->and($response->json('data.isProcessing'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: Sequential Forward Events
// ═══════════════════════════════════════════════════════════════════════

test('sequential forward events advance child through multiple states', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Step 1: PROVIDE_CARD -> child moves to awaiting_confirmation
    $provideResponse = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $provideResponse->assertStatus(200);
    expect($provideResponse->json('data.child.state'))
        ->toContain('forward_endpoint_child.awaiting_confirmation');

    // Step 2: CONFIRM_PAYMENT -> child moves to charged (final)
    // CONFIRM_PAYMENT has result: PaymentStepOutput, so response is custom
    $confirmResponse = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'CONF-001'],
    ]);

    $confirmResponse->assertStatus(200);

    $data = $confirmResponse->json('data');

    // PaymentStepOutput returns custom keys — now nested under data.output
    $output = $data['output'];
    expect($output)->toHaveKey('orderId')
        ->and($output)->toHaveKey('cardLast4')
        ->and($output['cardLast4'])->toBe('1111')
        ->and($output)->toHaveKey('childStep')
        ->and($data['isProcessing'])->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: OutputBehavior with ForwardContext
// ═══════════════════════════════════════════════════════════════════════

test('it runs parent OutputBehavior and returns custom response', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // First forward PROVIDE_CARD to set up child state
    $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '5500000000000004'],
    ]);

    // CONFIRM_PAYMENT has result: PaymentStepOutput
    $response = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'CONF-ABC'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    // PaymentStepOutput reads parent context.order_id and child context.card_last4 — nested under output
    $output = $data['output'];
    expect($output)->toHaveKey('orderId')
        ->and($output)->toHaveKey('cardLast4')
        ->and($output['cardLast4'])->toBe('0004')
        ->and($output)->toHaveKey('childStep')
        ->and($output['childStep'])->toContain('forward_endpoint_child')
        ->and($data['isProcessing'])->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  Happy Path: contextKeys Filtering
// ═══════════════════════════════════════════════════════════════════════

test('PROVIDE_CARD without contextKeys returns full child context', function (): void {
    $machineId = createAndStartForwardMachine($this);

    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    // ContextManager::toArray() wraps in 'data' key
    $childContextData = $response->json('data.child.output.data');

    // No contextKeys filtering on PROVIDE_CARD -- full child context
    expect($childContextData)->toHaveKey('orderId')
        ->and($childContextData)->toHaveKey('cardLast4')
        ->and($childContextData)->toHaveKey('status')
        ->and($response->json('data.isProcessing'))->toBeFalse();
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
        'payload' => ['cardNumber' => '4111111111111111'],
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
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertSuccessful();
});

test('custom method overrides default POST', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    // FullConfigForwardParentMachine has method: 'PATCH'
    // POST should not match
    $postResponse = $this->postJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $postResponse->assertStatus(405);

    // PATCH should match
    $patchResponse = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $patchResponse->assertSuccessful();
});

test('custom status code overrides default 200', function (): void {
    $machineId = createAndStartFullConfigMachine($this);

    // FullConfigForwardParentMachine has status: 202
    $response = $this->patchJson("/api/full-config/{$machineId}/enter-payment-details", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(202);
});

// ═══════════════════════════════════════════════════════════════════════
//  Event Validation
// ═══════════════════════════════════════════════════════════════════════

test('it validates request payload using child EventBehavior', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // ProvideCardEvent requires payload.cardNumber (string, min:13, max:19)
    // Send without cardNumber
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => [],
    ]);

    $response->assertStatus(422);
});

test('it returns 422 when child event validation fails with details', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Send with cardNumber too short
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '123'],
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
    $machineId      = $createResponse->json('data.id');

    // Try to forward PROVIDE_CARD -- parent is in idle, not processing
    $response = $this->postJson("/api/forward/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    // Should fail -- parent cannot forward from idle state
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

test('forwarding event not valid for child current state fails', function (): void {
    $machineId = createAndStartForwardMachine($this);

    // Child starts in awaiting_card. CONFIRM_PAYMENT is only valid in awaiting_confirmation.
    $response = $this->postJson("/api/forward/{$machineId}/confirm-payment", [
        'payload' => ['confirmationCode' => 'CONF-001'],
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
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data)->toHaveKey('child')
        ->and($data['child']['state'])->toContain('forward_endpoint_child.awaiting_confirmation')
        ->and($data['isProcessing'])->toBeFalse();
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

    expect($data['state'])->toContain('forward_endpoint_parent.cancelled')
        ->and($data['isProcessing'])->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  Non-existent MachineId
// ═══════════════════════════════════════════════════════════════════════

test('forwarded endpoint with non-existent machineId returns error', function (): void {
    $response = $this->postJson('/api/forward/does-not-exist/provide-card', [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ═══════════════════════════════════════════════════════════════════════
//  Rename Forward via HTTP (Format 2)
// ═══════════════════════════════════════════════════════════════════════

test('rename forward CANCEL_ORDER → ABORT works via HTTP endpoint', function (): void {
    $machineId = createAndStartRenameMachine($this);

    // Child starts at awaiting_card. ABORT is only valid in awaiting_confirmation.
    // First advance child to awaiting_confirmation via PROVIDE_CARD (plain forward would
    // be needed, but RenameForwardParentMachine only forwards CANCEL_ORDER).
    // So we advance child directly via Machine::send.
    $childRecord      = MachineChild::where('parent_root_event_id', $machineId)->first();
    $childRootEventId = $childRecord->child_root_event_id;

    $child = ForwardChildEndpointMachine::create(state: $childRootEventId);
    $child->send(['type' => 'PROVIDE_CARD', 'payload' => ['cardNumber' => '4242424242424242']]);

    // Now POST to the rename forward endpoint — CANCEL_ORDER should be forwarded as ABORT to child
    $response = $this->postJson("/api/rename/{$machineId}/cancel-order");

    $response->assertStatus(200);

    // Verify child transitioned to aborted
    $childCs = MachineCurrentState::where('root_event_id', $childRootEventId)->first();
    expect($childCs->state_id)->toContain('aborted');
});
