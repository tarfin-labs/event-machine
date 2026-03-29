<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\LockContention\SlowLockContentionMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    MachineRouter::register(SlowLockContentionMachine::class, [
        'prefix'       => '/api/slow-lock',
        'create'       => true,
        'machineIdFor' => ['START', 'COMPLETE', 'STATUS_REQUESTED'],
        'name'         => 'slow_lock',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ═══════════════════════════════════════════════════════════════════════
//  QA1. Concurrent send + GET status
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: GET /status returns 200 + isProcessing:true during concurrent processing', function (): void {
    // 1. Create and persist machine
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // 2. Dispatch slow-transition event to Horizon (entry action sleeps 2s)
    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // 3. Wait for Horizon to pick up job and acquire lock
    usleep(500_000);

    // 4. GET /status while lock is held
    $response = $this->get("/api/slow-lock/{$rootEventId}/status");

    // 5. Assert graceful response (not 500)
    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['isProcessing'])->toBeTrue()
        ->and($data['state'])->toBeArray()
        ->and($data)->toHaveKey('output');

    // 6. Wait for processing to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // 7. GET /status again — should show isProcessing:false
    $response2 = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response2->assertStatus(200);
    expect($response2->json('data.isProcessing'))->toBeFalse()
        ->and($response2->json('data.state'))->toContain('slow_lock_contention.processing');
});

// ═══════════════════════════════════════════════════════════════════════
//  QA2. Concurrent send + POST (write rejected)
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: POST returns 423 + isProcessing:true during concurrent processing', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch slow-transition event
    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    usleep(500_000);

    // POST while lock held → 423
    $response = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    $response->assertStatus(423);
    expect($response->json('data.isProcessing'))->toBeTrue();

    // Wait for processing to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // Retry POST → should succeed now
    $retryResponse = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    $retryResponse->assertStatus(200);
    expect($retryResponse->json('data.isProcessing'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
//  QA3. Multiple rapid GETs during processing
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: multiple rapid GETs during processing all return 200', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    usleep(500_000);

    // Fire 5 GET requests
    $responses = [];
    for ($i = 0; $i < 5; $i++) {
        $responses[] = $this->get("/api/slow-lock/{$rootEventId}/status");
    }

    // All should return 200
    foreach ($responses as $response) {
        $response->assertStatus(200);
        expect($response->json('data.isProcessing'))->toBeTrue();
    }

    // All should have consistent state data
    $states = array_map(fn ($r) => $r->json('data.state'), $responses);

    expect(array_unique($states, SORT_REGULAR))->toHaveCount(1);
});

// ═══════════════════════════════════════════════════════════════════════
//  QA4. Lock released after processing — no stale contention
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: no stale locks after processing completes', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for transition to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // GET /status — should be normal (no contention)
    $response = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse();

    // No stale lock rows
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();

    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════
//  QA5. POST retry succeeds after contention resolves
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: POST retry succeeds after contention resolves', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    usleep(500_000);

    // First POST → 423 (lock held)
    $firstResponse = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    expect($firstResponse->status())->toBe(423)
        ->and($firstResponse->json('data.isProcessing'))->toBeTrue();

    // Wait for slow transition to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // Retry POST → 200 (event processed normally)
    $retryResponse = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    $retryResponse->assertStatus(200);
    expect($retryResponse->json('data.isProcessing'))->toBeFalse()
        ->and($retryResponse->json('data.state'))->toContain('slow_lock_contention.completed');
});
