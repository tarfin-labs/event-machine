<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Facades\Machine;

test('a machine is an instance of State::class', function (): void {
    $machine = Machine::define();

    expect($machine)->toBeInstanceOf(State::class);
});

test('a Machine has a name', function (): void {
    $machine = Machine::define([
        'name' => 'traffic_lights_machine',
    ]);

    expect($machine)->name->toBe('traffic_lights_machine');
});

test('a Machine without name has a default name', function (): void {
    $machine = Machine::define([]);

    expect($machine)->name->toBe(State::DEFAULT_NAME);
});
