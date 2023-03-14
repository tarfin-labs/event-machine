<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a Machine can have states', function (): void {
    $machine = Machine::define([
        'name'          => 'traffic_lights_machine',
        'initial_state' => 'red',
        'states'        => [
            'red'    => [],
            'yellow' => [],
            'green'  => [],
        ],
    ]);

    expect($machine)
        ->toBeInstanceOf(State::class)
        ->machine->toBe($machine)
        ->parent->toBeNull()
        ->states->toBeArray()
        ->toHaveCount(3);

    foreach ($machine->states as $stateName => $stateInstance) {
        expect($stateInstance)
            ->toBeInstanceOf(State::class)
            ->name->toBe($stateName)
            ->machine->toBe($machine)
            ->parent->toBe($machine)
            ->states->toBeNull();
    }
});
