<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestThrowingEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestValidatedEndpointMachine;

// ─── Setup ────────────────────────────────────────────────────────────

beforeEach(function (): void {
    TestEndpointAction::reset();

    // Stateless routes for TestEndpointMachine
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/endpoint',
        'create' => true,
        'name'   => 'endpoint',
    ]);

    // MachineId-bound routes for TestEndpointMachine
    // machineIdFor requires a model to be set (otherwise stateless is used)
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/endpoint-mid',
        'model'        => 'App\\Models\\Dummy',
        'attribute'    => 'machine',
        'machineIdFor' => ['START', 'COMPLETE', 'CANCEL'],
        'name'         => 'endpoint_mid',
    ]);

    // Stateless routes for TestValidatedEndpointMachine
    MachineRouter::register(TestValidatedEndpointMachine::class, [
        'prefix' => '/api/validated',
        'name'   => 'validated',
    ]);

    // Stateless routes for TestThrowingEndpointMachine
    MachineRouter::register(TestThrowingEndpointMachine::class, [
        'prefix' => '/api/throwing',
        'name'   => 'throwing',
    ]);

    // Refresh route lookups so the router can match our new routes
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ─── Create Endpoint: 201 with machine_id ─────────────────────────────

test('create endpoint returns 201 with machine_id in response', function (): void {
    $response = $this->postJson('/api/endpoint/create');

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'machine_id',
                'value',
                'context',
            ],
        ]);

    $data = $response->json('data');

    expect($data['machine_id'])->not->toBeNull()
        ->and($data['machine_id'])->toBeString();
});

test('create endpoint initializes machine in initial state', function (): void {
    $response = $this->postJson('/api/endpoint/create');

    $response->assertStatus(201);

    $value = $response->json('data.value');

    // The initial state is 'idle', so value should contain 'test_endpoint.idle'
    expect($value)->toContain('test_endpoint.idle');
});

// ─── Stateless Endpoint: Simple Flow ──────────────────────────────────

test('stateless endpoint creates machine and transitions state', function (): void {
    $response = $this->postJson('/api/endpoint/start');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'machine_id',
                'value',
                'context',
            ],
        ]);

    // Machine was created fresh (stateless) then START event was sent
    // idle -> started
    $value = $response->json('data.value');

    expect($value)->toContain('test_endpoint.started');
});

test('default status code is 200 when not specified in endpoint config', function (): void {
    // START endpoint has no custom status code, default is 200
    $response = $this->postJson('/api/endpoint/start');

    $response->assertStatus(200);
});

// ─── MachineId-Bound Endpoint: State JSON ─────────────────────────────

test('machineId-bound endpoint returns state JSON with correct structure', function (): void {
    // First create the machine to get a machine_id
    $createResponse = $this->postJson('/api/endpoint/create');
    $createResponse->assertStatus(201);

    $machineId = $createResponse->json('data.machine_id');

    expect($machineId)->not->toBeNull();

    // Send START via machineId-bound route: idle -> started
    $response = $this->postJson("/api/endpoint-mid/{$machineId}/start");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'machine_id',
                'value',
                'context',
            ],
        ]);

    expect($response->json('data.machine_id'))->toBe($machineId);
});

test('machineId-bound endpoints allow sequential transitions', function (): void {
    // Create machine
    $createResponse = $this->postJson('/api/endpoint/create');
    $machineId      = $createResponse->json('data.machine_id');

    // idle -> started
    $startResponse = $this->postJson("/api/endpoint-mid/{$machineId}/start");
    $startResponse->assertStatus(200);

    expect($startResponse->json('data.value'))->toContain('test_endpoint.started');

    // started -> cancelled
    $cancelResponse = $this->postJson("/api/endpoint-mid/{$machineId}/cancel");
    $cancelResponse->assertStatus(200);

    expect($cancelResponse->json('data.value'))->toContain('test_endpoint.cancelled');
});

// ─── Endpoint with ResultBehavior ─────────────────────────────────────

test('endpoint with result behavior returns custom response with custom status', function (): void {
    // Create machine and transition to 'started'
    $createResponse = $this->postJson('/api/endpoint/create');
    $machineId      = $createResponse->json('data.machine_id');

    // idle -> started
    $this->postJson("/api/endpoint-mid/{$machineId}/start");

    // started -> completed (COMPLETE endpoint has result behavior with status 201)
    $response = $this->postJson("/api/endpoint-mid/{$machineId}/complete");

    $response->assertStatus(201);

    $data = $response->json('data');

    // TestEndpointResult returns ['custom' => true, 'context' => $context->toArray()]
    expect($data)->toHaveKey('custom')
        ->and($data['custom'])->toBeTrue()
        ->and($data)->toHaveKey('context');
});

// ─── Endpoint with EndpointAction ─────────────────────────────────────

test('endpoint action before and after hooks are called', function (): void {
    expect(TestEndpointAction::$beforeCalled)->toBeFalse()
        ->and(TestEndpointAction::$afterCalled)->toBeFalse();

    // START endpoint has TestEndpointAction configured
    $response = $this->postJson('/api/endpoint/start');

    $response->assertStatus(200);

    expect(TestEndpointAction::$beforeCalled)->toBeTrue()
        ->and(TestEndpointAction::$afterCalled)->toBeTrue();
});

// ─── MachineValidationException: 422 ──────────────────────────────────

test('validation guard failure returns 422 with error messages', function (): void {
    $response = $this->postJson('/api/validated/start');

    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors',
        ]);
});

// ─── EndpointAction.onException ───────────────────────────────────────

test('endpoint action onException is called when machine send throws', function (): void {
    TestEndpointAction::reset();

    expect(TestEndpointAction::$lastException)->toBeNull();

    try {
        $this->withoutExceptionHandling();
        $this->postJson('/api/throwing/start');
    } catch (RuntimeException) {
        // Expected: onException returns null so exception is re-thrown
    }

    // onException should have been called with the RuntimeException
    expect(TestEndpointAction::$lastException)->not->toBeNull()
        ->and(TestEndpointAction::$lastException)->toBeInstanceOf(RuntimeException::class)
        ->and(TestEndpointAction::$lastException->getMessage())->toBe('Action blew up');
});

test('endpoint action onException re-throws when returning null', function (): void {
    TestEndpointAction::reset();

    // TestEndpointAction.onException returns null, so the exception should propagate
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/throwing/start'))
        ->toThrow(RuntimeException::class, 'Action blew up');
});
