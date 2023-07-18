<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\MachineActor;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

test('a machine as a model attribute can serialize as root_event_id', function (): void {
    $modelA = new ModelA();

    expect($modelA->abc_mre)->toBeInstanceOf(MachineActor::class);
    expect($modelA->traffic_mre)->toBeInstanceOf(MachineActor::class);

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

    expect($retrievedModelA->abc_mre)->toBeInstanceOf(MachineActor::class);
    expect($retrievedModelA->traffic_mre)->toBeInstanceOf(MachineActor::class);
});
