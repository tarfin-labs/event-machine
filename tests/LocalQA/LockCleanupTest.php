<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineStateLock;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
    MachineLockManager::resetCleanupTimer();
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Stale lock cleanup after worker crash
// ═══════════════════════════════════════════════════════════════

it('LocalQA: stale lock cleanup — expired locks are cleaned up on next acquire', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);

    // Create a machine and persist it
    $machine = AsyncAutoCompleteParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Simulate a worker crash by manually inserting a stale lock
    // with an expires_at in the past (as if a worker died without releasing)
    MachineStateLock::create([
        'root_event_id' => $rootEventId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now()->subMinutes(5),
        'expires_at'    => now()->subMinutes(2), // Expired 2 minutes ago
        'context'       => 'simulated_crash',
    ]);

    // Verify the stale lock exists
    $staleLocks = DB::table('machine_locks')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($staleLocks)->toBe(1);

    // Now try to acquire a new lock — the stale lock cleanup in MachineLockManager
    // should clean the expired lock and allow acquisition
    $lockHandle = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 5,
        ttl: 60,
        context: 'after_crash_recovery',
    );

    // Lock was successfully acquired (stale lock was cleaned up)
    expect($lockHandle)->not->toBeNull();

    // Verify the lock in DB is the new one, not the stale one
    $currentLock = MachineStateLock::find($rootEventId);
    expect($currentLock)->not->toBeNull();
    expect($currentLock->context)->toBe('after_crash_recovery');
    expect($currentLock->owner_id)->toBe($lockHandle->ownerId);

    // Release the lock
    $lockHandle->release();

    // Verify lock is fully cleaned up
    $locksAfter = DB::table('machine_locks')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($locksAfter)->toBe(0);
});

it('LocalQA: stale lock does not block machine processing via Horizon', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
    config(['machine.parallel_dispatch.lock_timeout' => 10]);

    // Create a machine
    $machine = AsyncAutoCompleteParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Insert a stale lock (simulates crashed worker)
    MachineStateLock::create([
        'root_event_id' => $rootEventId,
        'owner_id'      => (string) Str::ulid(),
        'acquired_at'   => now()->subMinutes(10),
        'expires_at'    => now()->subMinutes(5), // Expired 5 minutes ago
        'context'       => 'crashed_worker',
    ]);

    // Now send an event via Horizon — the SendToMachineJob should:
    // 1. Try to acquire lock
    // 2. Stale lock cleanup runs (on next acquire attempt)
    // 3. New lock acquired
    // 4. Event processed normally
    SendToMachineJob::dispatch(
        machineClass: AsyncAutoCompleteParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for machine to process the event
    $processed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        // Machine should move beyond idle (START was processed)
        return $cs && !str_contains($cs->state_id, 'idle');
    }, timeoutSeconds: 30);

    expect($processed)->toBeTrue('Machine did not process event despite stale lock cleanup');

    // Wait for full completion (child delegation)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && (
            str_contains($cs->state_id, 'completed')
            || str_contains($cs->state_id, 'failed')
        );
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Machine did not complete after stale lock recovery');

    // No stale locks remain
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: multiple stale locks for different machines are all cleaned up', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);

    // Create 3 stale locks for different (fake) root event IDs
    $staleIds = [];
    for ($i = 0; $i < 3; $i++) {
        $id         = (string) Str::ulid();
        $staleIds[] = $id;

        MachineStateLock::create([
            'root_event_id' => $id,
            'owner_id'      => (string) Str::ulid(),
            'acquired_at'   => now()->subMinutes(10),
            'expires_at'    => now()->subMinutes($i + 1), // All expired
            'context'       => "stale_lock_{$i}",
        ]);
    }

    // Verify all 3 stale locks exist
    $count = DB::table('machine_locks')->count();
    expect($count)->toBe(3);

    // Now create a real machine and acquire a lock — cleanup should remove all expired locks
    $machine = AsyncAutoCompleteParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $lockHandle = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'fresh_lock',
    );

    // Only the fresh lock should remain
    $remaining = DB::table('machine_locks')->count();
    expect($remaining)->toBe(1);

    $freshLock = MachineStateLock::find($rootEventId);
    expect($freshLock->context)->toBe('fresh_lock');

    // All stale locks are gone
    foreach ($staleIds as $staleId) {
        $staleLock = MachineStateLock::find($staleId);
        expect($staleLock)->toBeNull();
    }

    $lockHandle->release();
});
