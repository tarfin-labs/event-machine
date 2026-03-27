<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Lock Stress — 10 Concurrent SendToMachineJob
// ═══════════════════════════════════════════════════════════════

it('LocalQA: 10 concurrent SendToMachineJob — all processed without corruption', function (): void {
    // Create 10 separate machines and send them each a START event concurrently
    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $m = AsyncParentMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    // Dispatch START event to all 10 machines simultaneously via SendToMachineJob
    foreach ($ids as $rootEventId) {
        SendToMachineJob::dispatch(
            machineClass: AsyncParentMachine::class,
            rootEventId: $rootEventId,
            event: ['type' => 'START'],
        );
    }

    // Wait for all 10 to process (they transition to 'processing' or 'completed')
    $allProcessed = LocalQATestCase::waitFor(function () use ($ids) {
        foreach ($ids as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || str_contains($cs->state_id, 'idle')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 90, description: 'lock stress: waiting for all 10 machines to process START');

    expect($allProcessed)->toBeTrue('Not all 10 machines processed their events');

    // Assert: all events recorded (at least START for each)
    foreach ($ids as $rootEventId) {
        $events = DB::table('machine_events')
            ->where('root_event_id', $rootEventId)
            ->count();
        expect($events)->toBeGreaterThan(1, "Machine {$rootEventId} missing events");
    }

    // Assert: no stale locks remain
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0, 'Stale locks found after concurrent processing');
});
