<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a Machine can have an initial state', function (): void {
    $machine = Machine::define([
        'name'          => 'traffic_lights_machine',
        'initial_state' => 'red',
    ]);

    expect($machine)
        ->initialState->toBeInstanceOf(State::class)
        ->initialState->value->toBe('red');
});

test('The initial state of a Machine has a parent state as machine itself', function (): void {
    $machine = Machine::define([
        'name'          => 'traffic_lights_machine',
        'initial_state' => 'red',
    ]);

    expect($machine)
        ->initialState->parent
        ->toBeInstanceOf(State::class)
        ->toBe($machine);
});
