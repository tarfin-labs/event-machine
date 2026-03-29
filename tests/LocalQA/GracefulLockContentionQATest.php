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

// ─── Helper ──────────────────────────────────────────────────────────

/**
 * Wait for the lock to appear in machine_locks table.
 * This proves Horizon picked up the job and acquired the lock — deterministic,
 * unlike usleep which is probabilistic.
 */
function waitForLockAcquired(string $rootEventId, int $timeoutSeconds = 15): bool
{
    return LocalQATestCase::waitFor(
        condition: fn () => DB::table('machine_locks')->where('root_event_id', $rootEventId)->exists(),
        timeoutSeconds: $timeoutSeconds,
        description: "lock acquired for {$rootEventId}",
    );
}

// ═══════════════════════════════════════════════════════════════════════
//  QA1. Concurrent send + GET status
//
//  Real-world scenario: Machine is processing an event (lock held).
//  Frontend receives a broadcast and calls GET /status.
//  Expected: 200 + isProcessing:true (not 500).
//  After processing completes: 200 + isProcessing:false + new state.
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: GET /status returns 200 + isProcessing:true during concurrent processing', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch slow-transition event to Horizon (entry action sleeps 2s)
    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for Horizon to actually acquire the lock — deterministic
    $lockAcquired = waitForLockAcquired($rootEventId);
    expect($lockAcquired)->toBeTrue('Horizon should acquire the lock');

    // GET /status while lock is held by Horizon worker
    $response = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['isProcessing'])->toBeTrue('GET during contention should have isProcessing:true')
        ->and($data['state'])->toBeArray()
        ->and($data['id'])->toBe($rootEventId)
        ->and($data['machineId'])->toBe('slow_lock_contention')
        ->and($data)->toHaveKey('output');

    // Wait for processing to complete (slow action finishes)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // GET /status again — lock released, should be normal
    $response2 = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response2->assertStatus(200);
    expect($response2->json('data.isProcessing'))->toBeFalse('GET after processing should have isProcessing:false')
        ->and($response2->json('data.state'))->toContain('slow_lock_contention.processing')
        ->and($response2->json('data.output.label'))->toBe('processed');
});

// ═══════════════════════════════════════════════════════════════════════
//  QA2. Concurrent send + POST (write rejected then retried)
//
//  Real-world scenario: Machine is processing event A.
//  User submits event B via POST. Machine is locked → 423.
//  After A completes, user retries B → 200.
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: POST returns 423 during processing, then 200 after retry', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    $lockAcquired = waitForLockAcquired($rootEventId);
    expect($lockAcquired)->toBeTrue('Horizon should acquire the lock');

    // POST while lock held → 423, event NOT processed
    $response = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    $response->assertStatus(423);
    $data = $response->json('data');

    expect($data['isProcessing'])->toBeTrue('POST during contention should have isProcessing:true')
        ->and($data['state'])->toContain('slow_lock_contention.idle')
        ->and($data)->toHaveKeys(['id', 'machineId', 'state', 'availableEvents', 'output', 'isProcessing']);

    // Wait for processing to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // Retry POST → now it should succeed
    $retryResponse = $this->postJson("/api/slow-lock/{$rootEventId}/complete");

    $retryResponse->assertStatus(200);
    expect($retryResponse->json('data.isProcessing'))->toBeFalse('Retry after lock release should have isProcessing:false')
        ->and($retryResponse->json('data.state'))->toContain('slow_lock_contention.completed');
});

// ═══════════════════════════════════════════════════════════════════════
//  QA3. Multiple rapid GETs during processing
//
//  Real-world scenario: Frontend polls rapidly or multiple broadcasts
//  arrive in quick succession. All GETs should return 200, not 500.
//  All should return the same consistent state snapshot.
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: multiple rapid GETs during processing all return 200 with consistent data', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    $lockAcquired = waitForLockAcquired($rootEventId);
    expect($lockAcquired)->toBeTrue('Horizon should acquire the lock');

    // Fire 5 rapid GET requests while lock is held
    $responses = [];
    for ($i = 0; $i < 5; $i++) {
        $responses[] = $this->get("/api/slow-lock/{$rootEventId}/status");
    }

    // ALL should return 200 (not a mix of 200 and 500)
    foreach ($responses as $index => $response) {
        $response->assertStatus(200);
        expect($response->json('data.isProcessing'))->toBeTrue("Response #{$index} should have isProcessing:true");
    }

    // ALL should return identical state data (internally consistent snapshots)
    $states  = array_map(fn ($r) => $r->json('data.state'), $responses);
    $outputs = array_map(fn ($r) => $r->json('data.output'), $responses);

    expect(array_unique($states, SORT_REGULAR))->toHaveCount(1, 'All responses should have identical state')
        ->and(array_unique($outputs, SORT_REGULAR))->toHaveCount(1, 'All responses should have identical output');
});

// ═══════════════════════════════════════════════════════════════════════
//  QA4. Lock released after processing — no stale contention
//
//  Verifies that after processing completes, the lock is fully released
//  and subsequent requests work normally (no phantom contention).
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: no stale locks or phantom contention after processing completes', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for transition to fully complete (lock acquired AND released)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30, description: 'machine reaches processing state');

    expect($completed)->toBeTrue();

    // Verify no stale lock rows remain
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0, 'No stale lock rows should remain');

    // GET /status should be normal (no contention)
    $response = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response->assertStatus(200);
    expect($response->json('data.isProcessing'))->toBeFalse('No contention after processing completes')
        ->and($response->json('data.output.counter'))->toBe(1, 'Entry action should have incremented counter')
        ->and($response->json('data.output.label'))->toBe('processed', 'Entry action should have set label');
});

// ═══════════════════════════════════════════════════════════════════════
//  QA5. Contention response returns valid state data, not empty/null
//
//  Verifies that the graceful fallback actually returns usable data —
//  the frontend can update its UI with the contention response.
// ═══════════════════════════════════════════════════════════════════════

it('LocalQA: contention response contains usable state data for frontend', function (): void {
    $machine = SlowLockContentionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SendToMachineJob::dispatch(
        machineClass: SlowLockContentionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    $lockAcquired = waitForLockAcquired($rootEventId);
    expect($lockAcquired)->toBeTrue('Horizon should acquire the lock');

    // GET /status during contention — verify response has ALL fields a frontend needs
    $response = $this->get("/api/slow-lock/{$rootEventId}/status");

    $response->assertStatus(200);
    $data = $response->json('data');

    // Every field should be present and non-null
    expect($data['id'])->toBe($rootEventId)
        ->and($data['machineId'])->toBe('slow_lock_contention')
        ->and($data['state'])->not->toBeEmpty()
        ->and($data['availableEvents'])->toBeArray()
        ->and($data['output'])->toBeArray()
        ->and($data['isProcessing'])->toBeTrue();

    // Output should contain actual context data (not null/empty defaults)
    expect($data['output']['counter'])->toBe(0)
        ->and($data['output']['label'])->toBe('initial');

    // Available events should reflect the current (pre-transition) state
    $eventTypes = collect($data['availableEvents'])->pluck('type')->toArray();
    expect($eventTypes)->toContain('START')
        ->and($eventTypes)->toContain('STATUS_REQUESTED');
});
