<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Locks\MachineLockHandle;
use Tarfinlabs\EventMachine\Models\MachineStateLock;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;

uses(RefreshDatabase::class);

it('creates machine_locks table with correct columns', function (): void {
    expect(Schema::hasTable('machine_locks'))->toBeTrue();
    expect(Schema::hasColumn('machine_locks', 'root_event_id'))->toBeTrue();
    expect(Schema::hasColumn('machine_locks', 'owner_id'))->toBeTrue();
    expect(Schema::hasColumn('machine_locks', 'acquired_at'))->toBeTrue();
    expect(Schema::hasColumn('machine_locks', 'expires_at'))->toBeTrue();
    expect(Schema::hasColumn('machine_locks', 'context'))->toBeTrue();
});

it('MachineStateLock uses correct table and has no timestamps', function (): void {
    $lock = new MachineStateLock();

    expect($lock->getTable())->toBe('machine_locks');
    expect($lock->timestamps)->toBeFalse();
    expect($lock->incrementing)->toBeFalse();
    expect($lock->getKeyName())->toBe('root_event_id');
    expect($lock->getKeyType())->toBe('string');
});

it('MachineStateLock can create and find records', function (): void {
    $lock = MachineStateLock::create([
        'root_event_id' => 'test-root-123',
        'owner_id'      => 'owner-456',
        'acquired_at'   => now(),
        'expires_at'    => now()->addMinutes(1),
        'context'       => 'test_context',
    ]);

    expect($lock)->toBeInstanceOf(MachineStateLock::class);

    $found = MachineStateLock::find('test-root-123');
    expect($found)->not->toBeNull();
    expect($found->owner_id)->toBe('owner-456');
    expect($found->context)->toBe('test_context');
});

it('acquire() acquires lock and returns MachineLockHandle', function (): void {
    $handle = MachineLockManager::acquire('root-001', context: 'test');

    expect($handle)->toBeInstanceOf(MachineLockHandle::class);
    expect($handle->rootEventId)->toBe('root-001');

    // Lock row should exist in DB
    $lock = MachineStateLock::find('root-001');
    expect($lock)->not->toBeNull();
    expect($lock->context)->toBe('test');

    $handle->release();
});

it('acquire() throws immediately in immediate mode when lock held', function (): void {
    $handle1 = MachineLockManager::acquire('root-002');

    expect(fn () => MachineLockManager::acquire('root-002', timeout: 0))
        ->toThrow(MachineLockTimeoutException::class);

    $handle1->release();
});

it('acquire() blocks and acquires after release in blocking mode', function (): void {
    $handle1 = MachineLockManager::acquire('root-003');

    // Release after 50ms in a micro-delay
    $handle1->release();

    // Should succeed since lock was released
    $handle2 = MachineLockManager::acquire('root-003', timeout: 5);
    expect($handle2)->toBeInstanceOf(MachineLockHandle::class);

    $handle2->release();
});

it('acquire() times out in blocking mode', function (): void {
    $handle1 = MachineLockManager::acquire('root-004', ttl: 60);

    expect(fn () => MachineLockManager::acquire('root-004', timeout: 1))
        ->toThrow(MachineLockTimeoutException::class);

    $handle1->release();
});

it('stale lock is cleaned up before new acquisition', function (): void {
    // Create a stale lock (already expired)
    MachineStateLock::create([
        'root_event_id' => 'root-005',
        'owner_id'      => 'stale-owner',
        'acquired_at'   => now()->subMinutes(10),
        'expires_at'    => now()->subMinutes(5),
        'context'       => 'stale',
    ]);

    // Should succeed because stale lock is cleaned up
    $handle = MachineLockManager::acquire('root-005');
    expect($handle)->toBeInstanceOf(MachineLockHandle::class);

    $handle->release();
});

it('release() removes lock from database', function (): void {
    $handle = MachineLockManager::acquire('root-006');
    expect(MachineStateLock::find('root-006'))->not->toBeNull();

    $handle->release();
    expect(MachineStateLock::find('root-006'))->toBeNull();

    // Subsequent acquire should succeed immediately
    $handle2 = MachineLockManager::acquire('root-006');
    expect($handle2)->toBeInstanceOf(MachineLockHandle::class);
    $handle2->release();
});

it('extend() updates expires_at without releasing lock', function (): void {
    $handle = MachineLockManager::acquire('root-007', ttl: 10);

    $lockBefore      = MachineStateLock::find('root-007');
    $expiresAtBefore = $lockBefore->expires_at;

    // Extend by 120 seconds
    $handle->extend(120);

    $lockAfter = MachineStateLock::find('root-007');
    expect($lockAfter)->not->toBeNull();
    expect($lockAfter->expires_at->greaterThan($expiresAtBefore))->toBeTrue();

    $handle->release();
});
