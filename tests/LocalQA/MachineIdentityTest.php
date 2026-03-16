<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

it('LocalQA: machineId set on fresh create with MySQL', function (): void {
    $machine = TrafficLightsMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;
    expect($machine->state->context->machineId())->toBe($rootEventId);

    // Verify in MySQL
    $cs = DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->first();
    expect($cs)->not->toBeNull();
});

it('LocalQA: machineId preserved after restore from MySQL', function (): void {
    $machine = TrafficLightsMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $restored = TrafficLightsMachine::create(state: $rootEventId);
    expect($restored->state->context->machineId())->toBe($rootEventId);
});

it('LocalQA: machineId correct after async delegation completes via Horizon', function (): void {
    $parent = AsyncParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue();

    $restored = AsyncParentMachine::create(state: $rootEventId);
    expect($restored->state->context->machineId())->toBe($rootEventId);
});
