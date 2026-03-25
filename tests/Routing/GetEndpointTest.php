<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint\GetEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint\GetForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint\GetEndpointNoValidationMachine;

// ─── Setup ────────────────────────────────────────────────────────────

beforeEach(function (): void {
    // Stateless GET (tests #1–#5, #11–#12, #14)
    MachineRouter::register(GetEndpointMachine::class, [
        'prefix' => '/api/get',
        'name'   => 'get_endpoint',
    ]);

    // Stateless GET no-validation (tests #6, #7, #13)
    MachineRouter::register(GetEndpointNoValidationMachine::class, [
        'prefix' => '/api/get-noval',
        'name'   => 'get_noval',
    ]);

    // MachineId-bound GET (test #8)
    MachineRouter::register(GetEndpointMachine::class, [
        'prefix'       => '/api/get-mid',
        'machineIdFor' => ['STATUS_REQUESTED'],
        'create'       => true,
        'name'         => 'get_mid',
    ]);

    // Forwarded GET (tests #9, #10)
    // Note: CHILD_STATUS is a forwarded endpoint — it inherits binding from machineIdFor automatically
    MachineRouter::register(GetForwardParentMachine::class, [
        'prefix'       => '/api/get-fwd',
        'machineIdFor' => ['START'],
        'create'       => true,
        'name'         => 'get_fwd',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════
//  A. Core Wrapping (stateless GET)
// ═══════════════════════════════════════════════════════════════

test('#1 — both required query params reach event payload', function (): void {
    $response = $this->get('/api/get/status?dealer_code=ABC123&plate_number=34XY');

    $response->assertStatus(200);

    $context = $response->json('data.context.data');

    expect($context['dealer_code'])->toBe('ABC123')
        ->and($context['plate_number'])->toBe('34XY');
});

test('#2 — missing one required param returns 422', function (): void {
    $response = $this->getJson('/api/get/status?dealer_code=ABC123');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload.plate_number']);
});

test('#3 — missing all required params returns 422', function (): void {
    $response = $this->getJson('/api/get/status');

    // stopOnFirstFailure=true means only first error is returned
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload.dealer_code']);
});

test('#4 — validation rule min:3 enforced on dealer_code', function (): void {
    $response = $this->getJson('/api/get/status?dealer_code=AB&plate_number=34XY');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload.dealer_code']);
});

// ═══════════════════════════════════════════════════════════════
//  B. No-Wrap Guard (isset($data['payload']))
// ═══════════════════════════════════════════════════════════════

test('#5 — explicit payload[] syntax not double-wrapped', function (): void {
    $response = $this->get('/api/get/status?payload[dealer_code]=ABC123&payload[plate_number]=34XY');

    $response->assertStatus(200);

    $context = $response->json('data.context.data');

    expect($context['dealer_code'])->toBe('ABC123')
        ->and($context['plate_number'])->toBe('34XY');
});

// ═══════════════════════════════════════════════════════════════
//  C. No Validation Rules
// ═══════════════════════════════════════════════════════════════

test('#6 — GET with no validation rules and no params returns 200', function (): void {
    $response = $this->get('/api/get-noval/ping');

    $response->assertStatus(200);
});

test('#7 — GET with no validation rules stores query params in context', function (): void {
    $response = $this->get('/api/get-noval/ping?foo=bar&baz=qux');

    $response->assertStatus(200);

    $context = $response->json('data.context.data');

    expect($context['ping_payload'])->toBe(['foo' => 'bar', 'baz' => 'qux']);
});

// ═══════════════════════════════════════════════════════════════
//  D. MachineId-Bound GET
// ═══════════════════════════════════════════════════════════════

test('#8 — machineId-bound GET endpoint works after create', function (): void {
    // Step 1: Create machine
    $createResponse = $this->postJson('/api/get-mid/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.machine_id');

    // Step 2: Send GET with machineId
    $response = $this->get("/api/get-mid/{$machineId}/status?dealer_code=ABC123&plate_number=34XY");

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data['value'])->toContain('get_endpoint.done')
        ->and($data['context']['data']['dealer_code'])->toBe('ABC123')
        ->and($data['context']['data']['plate_number'])->toBe('34XY');
});

// ═══════════════════════════════════════════════════════════════
//  E. Forwarded GET Endpoints
// ═══════════════════════════════════════════════════════════════

test('#9 — forwarded GET validates child event and reaches child', function (): void {
    // Step 1: Create parent
    $createResponse = $this->postJson('/api/get-fwd/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.machine_id');

    // Step 2: Transition parent to delegating state
    $startResponse = $this->postJson("/api/get-fwd/{$machineId}/start");
    $startResponse->assertStatus(200);

    // Step 3: Send forwarded GET to child
    $response = $this->get("/api/get-fwd/{$machineId}/child-status?child_param=hello");

    $response->assertStatus(200);
});

test('#10 — forwarded GET missing required child param returns 422', function (): void {
    // Step 1: Create parent
    $createResponse = $this->postJson('/api/get-fwd/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.machine_id');

    // Step 2: Transition parent to delegating state
    $startResponse = $this->postJson("/api/get-fwd/{$machineId}/start");
    $startResponse->assertStatus(200);

    // Step 3: Send forwarded GET without required param
    $response = $this->getJson("/api/get-fwd/{$machineId}/child-status");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload.child_param']);
});

// ═══════════════════════════════════════════════════════════════
//  F. Edge Cases
// ═══════════════════════════════════════════════════════════════

test('#11 — numeric string passes string validation rule', function (): void {
    $response = $this->get('/api/get/status?dealer_code=12345&plate_number=34XY');

    $response->assertStatus(200);

    $context = $response->json('data.context.data');

    expect($context['dealer_code'])->toBe('12345');
});

test('#12 — empty string converted to null by ConvertEmptyStringsToNull middleware', function (): void {
    $response = $this->getJson('/api/get/status?dealer_code=&plate_number=34XY');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['payload.dealer_code']);
});

test('#13 — array-style query params preserved in payload', function (): void {
    $response = $this->get('/api/get-noval/ping?items[]=a&items[]=b');

    $response->assertStatus(200);

    $context = $response->json('data.context.data');

    expect($context['ping_payload']['items'])->toBe(['a', 'b']);
});

test('#14 — type in query param does not override event type', function (): void {
    $response = $this->get('/api/get/status?type=WRONG&dealer_code=ABC123&plate_number=34XY');

    $response->assertStatus(200);

    // Machine transitioned successfully — event type resolved from getType(), not query param
    $data = $response->json('data');

    expect($data['value'])->toContain('get_endpoint.done');
});
