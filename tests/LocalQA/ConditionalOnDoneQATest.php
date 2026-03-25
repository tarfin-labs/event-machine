<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnDoneQAMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnDoneFailMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    // Enable parallel dispatch
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Conditional @done via Real Horizon Dispatch
// ═══════════════════════════════════════════════════════════════

it('LocalQA: guarded @done routes to approved when both regions succeed via Horizon', function (): void {
    $machine = ConditionalOnDoneQAMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions to set context (ParallelRegionJobs via Horizon)
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);

        return $restored->state->context->get('inventory_result') === 'success'
            && $restored->state->context->get('payment_result') === 'success';
    }, timeoutSeconds: 60, description: 'conditional @done: waiting for region entry actions');

    expect($ready)->toBeTrue('Region entry actions did not complete');

    // Complete inventory region and wait for ACTUAL state change (not just event record)
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneQAMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'INVENTORY_CHECKED'],
    );

    $inventoryDone = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        // inventory.done should appear in the state value
        return $cs && str_contains($cs->state_id, 'inventory') && str_contains($cs->state_id, 'done');
    }, timeoutSeconds: 60, description: 'conditional @done: inventory reaching done state');

    // If direct state check failed, try restored machine value array
    if (!$inventoryDone) {
        $inventoryDone = LocalQATestCase::waitFor(function () use ($rootEventId) {
            $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);

            return collect($restored->state->value)->contains(fn ($v) => str_contains($v, 'inventory') && str_contains($v, 'done'));
        }, timeoutSeconds: 30, description: 'conditional @done: inventory done via restored state');
    }

    expect($inventoryDone)->toBeTrue('Inventory did not reach done state');

    // Complete payment region
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneQAMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PAYMENT_VALIDATED'],
    );

    // Wait for @done → guard passes → approved
    $approved = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'approved');
    }, timeoutSeconds: 60, description: 'conditional @done: waiting for approved state');

    expect($approved)->toBeTrue('Guarded @done did not route to approved');

    // Assert: guard evaluated with context from BOTH regions
    $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);
    expect($restored->state->context->get('approval_logged'))->toBeTrue();
});

it('LocalQA: guarded @done fallback to manual_review when guard fails', function (): void {
    $machine = ConditionalOnDoneFailMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);

        return $restored->state->context->get('inventory_result') === 'success'
            && $restored->state->context->get('payment_result') === 'failure';
    }, timeoutSeconds: 60, description: 'conditional @done fail: waiting for region entry actions');

    expect($ready)->toBeTrue('Region entry actions did not complete');

    // Complete inventory region — wait for ACTUAL state change
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneFailMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'INVENTORY_CHECKED'],
    );

    $inventoryDone = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);

        return collect($restored->state->value)->contains(fn ($v) => str_contains($v, 'inventory') && str_contains($v, 'done'));
    }, timeoutSeconds: 60, description: 'conditional @done fail: inventory reaching done state');

    expect($inventoryDone)->toBeTrue('Inventory did not reach done state');

    // Complete payment region
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneFailMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PAYMENT_VALIDATED'],
    );

    // Wait for @done → guard fails → fallback to manual_review
    $review = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'manual_review');
    }, timeoutSeconds: 60, description: 'conditional @done fail: waiting for manual_review state');

    expect($review)->toBeTrue('Guarded @done did not fallback to manual_review');

    // Assert: reviewer notified, NOT approved
    $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);
    expect($restored->state->context->get('reviewer_notified'))->toBeTrue();
    expect($restored->state->context->get('approval_logged'))->toBeFalse();
});
