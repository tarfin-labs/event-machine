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

        return $restored->state->context->get('inventoryResult') === 'success'
            && $restored->state->context->get('paymentResult') === 'success';
    }, timeoutSeconds: 60, description: 'conditional @done: waiting for region entry actions');

    expect($ready)->toBeTrue('Region entry actions did not complete');

    // Complete inventory region — wait for ACTUAL state change via restored machine
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneQAMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'INVENTORY_CHECKED'],
    );

    $inventoryDone = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);

        return collect($restored->state->value)->contains(
            fn ($v) => str_contains($v, 'inventory') && str_contains($v, 'done')
        );
    }, timeoutSeconds: 60, description: 'conditional @done: inventory reaching done state');

    expect($inventoryDone)->toBeTrue('Inventory did not reach done state');

    // Complete payment region
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneQAMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PAYMENT_VALIDATED'],
    );

    // Wait for @done → guard passes → approved (use restored machine, not MachineCurrentState)
    $approved = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);

        return str_contains($restored->state->currentStateDefinition->id, 'approved');
    }, timeoutSeconds: 60, description: 'conditional @done: waiting for approved state');

    expect($approved)->toBeTrue('Guarded @done did not route to approved');

    $restored = ConditionalOnDoneQAMachine::create(state: $rootEventId);
    expect($restored->state->context->get('approvalLogged'))->toBeTrue();
});

it('LocalQA: guarded @done fallback to manual_review when guard fails', function (): void {
    $machine = ConditionalOnDoneFailMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);

        return $restored->state->context->get('inventoryResult') === 'success'
            && $restored->state->context->get('paymentResult') === 'failure';
    }, timeoutSeconds: 60, description: 'conditional @done fail: waiting for region entry actions');

    expect($ready)->toBeTrue('Region entry actions did not complete');

    // Complete inventory — wait for state via restored machine
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneFailMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'INVENTORY_CHECKED'],
    );

    $inventoryDone = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);

        return collect($restored->state->value)->contains(
            fn ($v) => str_contains($v, 'inventory') && str_contains($v, 'done')
        );
    }, timeoutSeconds: 60, description: 'conditional @done fail: inventory reaching done state');

    expect($inventoryDone)->toBeTrue('Inventory did not reach done state');

    // Complete payment
    SendToMachineJob::dispatch(
        machineClass: ConditionalOnDoneFailMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PAYMENT_VALIDATED'],
    );

    // Wait for @done → guard fails → manual_review (use restored machine, not MachineCurrentState)
    $review = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);

        return str_contains($restored->state->currentStateDefinition->id, 'manual_review');
    }, timeoutSeconds: 60, description: 'conditional @done fail: waiting for manual_review state');

    expect($review)->toBeTrue('Guarded @done did not fallback to manual_review');

    $restored = ConditionalOnDoneFailMachine::create(state: $rootEventId);
    expect($restored->state->context->get('reviewerNotified'))->toBeTrue();
    expect($restored->state->context->get('approvalLogged'))->toBeFalse();
});
