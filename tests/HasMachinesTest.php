<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;

it('it should return a machine', function (): void {
    $machine = AbcMachine::create();
    $machine->persist();

    ModelA::create([
        'abc_mre' => $machine->state->history->first()->root_event_id,
    ]);

    $modelA = ModelA::first();

    expect($modelA->abc_mre)
        ->toBeInstanceOf(Machine::class);
});
