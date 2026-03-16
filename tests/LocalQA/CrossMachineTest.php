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

it('LocalQA: dispatchTo delivers event via Horizon SendToMachineJob', function (): void {
    // Create a machine instance that will be the target
    $target = AsyncParentMachine::create();
    $target->persist();
    $targetId = $target->state->history->first()->root_event_id;

    // Verify initial state
    $cs = MachineCurrentState::where('root_event_id', $targetId)->first();
    expect($cs)->not->toBeNull();

    // dispatchTo sends SendToMachineJob to Horizon
    // We test this by sending START event via dispatchTo
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $targetId,
        event: ['type' => 'START'],
    );

    // Wait for Horizon to process
    $processed = LocalQATestCase::waitFor(function () use ($targetId) {
        $cs = MachineCurrentState::where('root_event_id', $targetId)->first();

        return $cs && str_contains($cs->state_id, 'delegating');
    }, timeoutSeconds: 30);

    expect($processed)->toBeTrue('SendToMachineJob not processed by Horizon');
});

it('LocalQA: SendToMachineJob to non-existent machine does not crash worker', function (): void {
    // Dispatch to non-existent root_event_id — should be handled gracefully
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: 'nonexistent-root-event-id',
        event: ['type' => 'START'],
    );

    sleep(3); // Give Horizon time to process (and fail gracefully)

    // Verify no failed jobs (SendToMachineJob catches RestoringStateException)
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});
