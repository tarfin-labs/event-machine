<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineStateLock;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\LockContention\LockContentionEndpointMachine;

// ─── Setup ────────────────────────────────────────────────────────────

beforeEach(function (): void {
    TestEndpointAction::reset();

    config()->set('machine.parallel_dispatch.enabled', true);

    // Stateless routes
    MachineRouter::register(LockContentionEndpointMachine::class, [
        'prefix' => '/api/lock-contention',
        'create' => true,
        'name'   => 'lock_contention',
    ]);

    // MachineId-bound routes
    MachineRouter::register(LockContentionEndpointMachine::class, [
        'prefix'       => '/api/lock-contention-mid',
        'machineIdFor' => ['START', 'COMPLETE', 'STATUS_REQUESTED'],
        'name'         => 'lock_contention_mid',
    ]);

    // TestEndpointMachine with action (for action lifecycle tests)
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/endpoint-action',
        'machineIdFor' => ['START', 'COMPLETE', 'CANCEL'],
        'create'       => true,
        'name'         => 'endpoint_action',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ═══════════════════════════════════════════════════════════════════════
//  A. Response Envelope — isProcessing Always Present
// ═══════════════════════════════════════════════════════════════════════

test('normal POST request includes isProcessing:false', function (): void {
    $response = $this->postJson('/api/lock-contention/start');

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse();
});

test('normal GET request includes isProcessing:false', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    // Transition to started so STATUS_REQUESTED is available
    $this->postJson("/api/lock-contention-mid/{$machineId}/start");

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse();
});

test('create endpoint includes isProcessing:false', function (): void {
    $response = $this->postJson('/api/lock-contention/create');

    $response->assertStatus(201);
    expect($response->json('data.isProcessing'))->toBeFalse();
});

test('stateless endpoint includes isProcessing:false', function (): void {
    $response = $this->postJson('/api/lock-contention/start');

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  B. GET Contention — 200 with Snapshot
// ═══════════════════════════════════════════════════════════════════════

test('GET endpoint returns 200 + isProcessing:true during lock contention', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");

    $response->assertStatus(200);

    $data = $response->json('data');

    expect($data['isProcessing'])->toBeTrue()
        ->and($data['id'])->toBe($machineId)
        ->and($data['machineId'])->toBe('lock_contention_endpoint')
        ->and($data['state'])->toBeArray()
        ->and($data['availableEvents'])->toBeArray()
        ->and($data)->toHaveKey('output');
});

test('GET contention returns correct availableEvents for current state', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");
    $events   = collect($response->json('data.availableEvents'));

    $eventTypes = $events->pluck('type')->toArray();

    expect($eventTypes)->toContain('START')
        ->and($eventTypes)->toContain('STATUS_REQUESTED');
});

test('GET contention returns output from current state', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");
    $output   = $response->json('data.output');

    expect($output)->toBeArray()
        ->and($output['counter'])->toBe(0)
        ->and($output['label'])->toBe('initial');
});

// ═══════════════════════════════════════════════════════════════════════
//  C. POST Contention — 423 Locked
// ═══════════════════════════════════════════════════════════════════════

test('POST endpoint returns 423 + isProcessing:true during lock contention', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->postJson("/api/lock-contention-mid/{$machineId}/start");

    $response->assertStatus(423);
    expect($response->json('data.isProcessing'))->toBeTrue()
        ->and($response->json('data.state'))->toContain('lock_contention_endpoint.idle');
});

test('POST contention does not modify machine state', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $this->postJson("/api/lock-contention-mid/{$machineId}/start")->assertStatus(423);

    // Release lock and verify state unchanged
    MachineStateLock::where('root_event_id', $machineId)->delete();

    $machine = LockContentionEndpointMachine::create(state: $machineId);

    expect($machine->state->matches('idle'))->toBeTrue();
});

test('POST contention returns full envelope in 423 body', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->postJson("/api/lock-contention-mid/{$machineId}/start");
    $data     = $response->json('data');

    expect($data)->toHaveKeys(['id', 'machineId', 'state', 'availableEvents', 'output', 'isProcessing']);
});

// ═══════════════════════════════════════════════════════════════════════
//  D. Action Lifecycle
// ═══════════════════════════════════════════════════════════════════════

test('action before() runs but after() is skipped during contention', function (): void {
    $createResponse = $this->postJson('/api/endpoint-action/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $this->postJson("/api/endpoint-action/{$machineId}/start");

    expect(TestEndpointAction::$beforeCalled)->toBeTrue()
        ->and(TestEndpointAction::$afterCalled)->toBeFalse();
});

test('action onException() is NOT called during contention', function (): void {
    $createResponse = $this->postJson('/api/endpoint-action/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $this->postJson("/api/endpoint-action/{$machineId}/start");

    expect(TestEndpointAction::$lastException)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
//  E. Forwarded Endpoints
// ═══════════════════════════════════════════════════════════════════════

test('forwarded POST endpoint returns 423 during parent lock contention', function (): void {
    MachineRouter::register(
        ForwardParentEndpointMachine::class,
        [
            'prefix'       => '/api/forward-lock',
            'create'       => true,
            'machineIdFor' => ['START', 'CANCEL'],
            'name'         => 'forward_lock',
        ],
    );
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $createResponse = $this->postJson('/api/forward-lock/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/forward-lock/{$machineId}/start");

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->postJson("/api/forward-lock/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(423);
    expect($response->json('data.isProcessing'))->toBeTrue()
        ->and($response->json('data.state'))->toBeArray();
});

test('forwarded contention response has no child key', function (): void {
    MachineRouter::register(
        ForwardParentEndpointMachine::class,
        [
            'prefix'       => '/api/forward-lock2',
            'create'       => true,
            'machineIdFor' => ['START', 'CANCEL'],
            'name'         => 'forward_lock2',
        ],
    );
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $createResponse = $this->postJson('/api/forward-lock2/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/forward-lock2/{$machineId}/start");

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->postJson("/api/forward-lock2/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    expect($response->json('data'))->not->toHaveKey('child')
        ->and($response->json('data.isProcessing'))->toBeTrue();
});

test('forwarded endpoint returns isProcessing:false on normal path', function (): void {
    MachineRouter::register(
        ForwardParentEndpointMachine::class,
        [
            'prefix'       => '/api/forward-lock3',
            'create'       => true,
            'machineIdFor' => ['START', 'CANCEL'],
            'name'         => 'forward_lock3',
        ],
    );
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $createResponse = $this->postJson('/api/forward-lock3/create');
    $machineId      = $createResponse->json('data.id');

    $this->postJson("/api/forward-lock3/{$machineId}/start");

    $response = $this->postJson("/api/forward-lock3/{$machineId}/provide-card", [
        'payload' => ['cardNumber' => '4111111111111111'],
    ]);

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse()
        ->and($response->json('data'))->toHaveKey('child');
});

// ═══════════════════════════════════════════════════════════════════════
//  F. State Data Freshness
// ═══════════════════════════════════════════════════════════════════════

test('contention response reflects latest committed state', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    // Transition to started
    $this->postJson("/api/lock-contention-mid/{$machineId}/start");

    // Insert lock (simulating further processing after persist)
    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");

    $response->assertStatus(200);
    expect($response->json('data.state'))->toContain('lock_contention_endpoint.started')
        ->and($response->json('data.isProcessing'))->toBeTrue();
});

test('multiple contention requests return same consistent snapshot', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $responses = [];
    for ($i = 0; $i < 3; $i++) {
        $responses[] = $this->get("/api/lock-contention-mid/{$machineId}/status")->json('data');
    }

    expect($responses[0]['state'])->toBe($responses[1]['state'])
        ->and($responses[1]['state'])->toBe($responses[2]['state'])
        ->and($responses[0]['isProcessing'])->toBeTrue()
        ->and($responses[1]['isProcessing'])->toBeTrue()
        ->and($responses[2]['isProcessing'])->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════
//  G. Boundary Conditions
// ═══════════════════════════════════════════════════════════════════════

test('contention overrides custom endpoint statusCode', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    // Transition to started so COMPLETE (status:201) is available
    $this->postJson("/api/lock-contention-mid/{$machineId}/start");

    // Insert lock to simulate contention
    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    // Contention: COMPLETE endpoint has status:201 configured, but contention returns 423
    $response = $this->postJson("/api/lock-contention-mid/{$machineId}/complete");

    expect($response->status())->toBe(423)
        ->and($response->json('data.isProcessing'))->toBeTrue();
});

test('contention with outputKeys returns filtered keys', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $response = $this->get("/api/lock-contention-mid/{$machineId}/status");

    // StatusOutput returns counter and label from context
    $output = $response->json('data.output');

    expect($output)->toHaveKeys(['counter', 'label']);
});

test('contention does not leave stale lock rows', function (): void {
    $createResponse = $this->postJson('/api/lock-contention/create');
    $machineId      = $createResponse->json('data.id');

    MachineStateLock::create([
        'root_event_id' => $machineId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now(),
        'expires_at'    => now()->addSeconds(60),
        'context'       => 'test_lock',
    ]);

    $lockCountBefore = MachineStateLock::where('root_event_id', $machineId)->count();

    $this->get("/api/lock-contention-mid/{$machineId}/status")->assertStatus(200);

    $lockCountAfter = MachineStateLock::where('root_event_id', $machineId)->count();

    expect($lockCountAfter)->toBe($lockCountBefore);
});
