<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

test('Machine can be cast to JSON', function (): void {
    $machine = XyzMachine::create();

    expect(json_encode($machine, JSON_THROW_ON_ERROR))
        ->toBe('"'.$machine->state->history->first()->root_event_id.'"');
});

test('Machine can be cast to string', function (): void {
    $machine = XyzMachine::create();

    expect((string) $machine)
        ->toBe($machine->state->history->first()->root_event_id);
});

test('a machine as a model attribute can serialize as root_event_id', function (): void {
    $modelA = new ModelA();

    expect($modelA->abc_mre)->toBeInstanceOf(Machine::class);
    expect($modelA->traffic_mre)->toBeInstanceOf(Machine::class);

    expect($modelA->toArray())->toBeArray();
    expect($modelA->toJson())->toBeString();

    $modelA->abc_mre->persist();
    $modelA->traffic_mre->persist();
    $modelA->save();

    expect($modelA->toArray())->toBeArray();
    expect($modelA->toJson())->toBeString();

    $retrievedModelA = ModelA::findOrFail($modelA->id);

    expect($retrievedModelA->toArray())->toBeArray();
    expect($retrievedModelA->toJson())->toBeString();

    expect($retrievedModelA->abc_mre)->toBeInstanceOf(Machine::class);
    expect($retrievedModelA->traffic_mre)->toBeInstanceOf(Machine::class);
});
