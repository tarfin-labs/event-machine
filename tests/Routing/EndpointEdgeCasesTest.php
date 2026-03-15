<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestRecoveringEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestRecoveringEndpointMachine;

beforeEach(function (): void {
    TestEndpointAction::reset();
    TestRecoveringEndpointAction::reset();

    // MachineId-bound routes
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/edge',
        'machineIdFor' => ['START', 'COMPLETE', 'CANCEL'],
        'create'       => true,
        'name'         => 'edge',
    ]);

    // Recovering action routes
    MachineRouter::register(TestRecoveringEndpointMachine::class, [
        'prefix' => '/api/recovering',
        'name'   => 'recovering',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════
//  Invalid machineId
// ═══════════════════════════════════════════════════════════════

test('machineId-bound endpoint with non-existent machineId returns error', function (): void {
    $response = $this->postJson('/api/edge/does-not-exist-at-all/start');

    // Should fail gracefully — either 404 or 500, not a silent success
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

test('machineId-bound endpoint with empty machineId returns error', function (): void {
    // POST to base path without machineId should be 404 (no route match)
    $response = $this->postJson('/api/edge//start');

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ═══════════════════════════════════════════════════════════════
//  onException returning JsonResponse (not null)
// ═══════════════════════════════════════════════════════════════

test('onException returning JsonResponse prevents exception propagation', function (): void {
    $response = $this->postJson('/api/recovering/start');

    // RecoveringEndpointAction returns 503 with custom error
    $response->assertStatus(503)
        ->assertJson([
            'error'   => 'handled',
            'message' => 'Action blew up',
        ]);

    expect(TestRecoveringEndpointAction::$exceptionCaught)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  Invalid event for current state
// ═══════════════════════════════════════════════════════════════

test('sending event not valid for current state returns 500', function (): void {
    // Create machine (idle state)
    $createResponse = $this->postJson('/api/edge/create');
    $machineId      = $createResponse->json('data.machine_id');

    // COMPLETE is not valid in 'idle' state (only START is)
    // Machine throws NoTransitionDefinitionFoundException
    $response = $this->postJson("/api/edge/{$machineId}/complete");

    $response->assertStatus(500);
});

// ═══════════════════════════════════════════════════════════════
//  Sequential transitions via machineId-bound routes
// ═══════════════════════════════════════════════════════════════

test('full lifecycle via machineId-bound routes: create -> start -> complete', function (): void {
    // Create
    $createResponse = $this->postJson('/api/edge/create');
    $machineId      = $createResponse->json('data.machine_id');

    $createResponse->assertStatus(201);
    expect($createResponse->json('data.value'))->toContain('test_endpoint.idle');

    // idle -> started
    $startResponse = $this->postJson("/api/edge/{$machineId}/start");
    $startResponse->assertStatus(200);
    expect($startResponse->json('data.value'))->toContain('test_endpoint.started');
    expect($startResponse->json('data.machine_id'))->toBe($machineId);

    // started -> completed (with result behavior, status 201)
    $completeResponse = $this->postJson("/api/edge/{$machineId}/complete");
    $completeResponse->assertStatus(201);
    expect($completeResponse->json('data.custom'))->toBeTrue();
});

test('full lifecycle via machineId-bound routes: create -> start -> cancel', function (): void {
    $createResponse = $this->postJson('/api/edge/create');
    $machineId      = $createResponse->json('data.machine_id');

    $this->postJson("/api/edge/{$machineId}/start");

    // started -> cancelled
    $cancelResponse = $this->postJson("/api/edge/{$machineId}/cancel");
    $cancelResponse->assertStatus(200);
    expect($cancelResponse->json('data.value'))->toContain('test_endpoint.cancelled');
});

// ═══════════════════════════════════════════════════════════════
//  Endpoint action hooks with machineId-bound route
// ═══════════════════════════════════════════════════════════════

test('endpoint action hooks fire on machineId-bound route', function (): void {
    $createResponse = $this->postJson('/api/edge/create');
    $machineId      = $createResponse->json('data.machine_id');

    expect(TestEndpointAction::$beforeCalled)->toBeFalse()
        ->and(TestEndpointAction::$afterCalled)->toBeFalse();

    // START has TestEndpointAction configured
    $this->postJson("/api/edge/{$machineId}/start");

    expect(TestEndpointAction::$beforeCalled)->toBeTrue()
        ->and(TestEndpointAction::$afterCalled)->toBeTrue()
        ->and(TestEndpointAction::$stateValueInAfter)->toContain('test_endpoint.started');
});

// ═══════════════════════════════════════════════════════════════
//  Create endpoint idempotency
// ═══════════════════════════════════════════════════════════════

test('multiple create calls produce different machine instances', function (): void {
    $response1 = $this->postJson('/api/edge/create');
    $response2 = $this->postJson('/api/edge/create');

    $id1 = $response1->json('data.machine_id');
    $id2 = $response2->json('data.machine_id');

    expect($id1)->not->toBe($id2);
});
