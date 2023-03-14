<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a Machine is an instance of State::class', function (): void {
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

test('a Machine has a machine', function (): void {
    $machine = Machine::define([]);

    expect($machine->machine)->toBeInstanceOf(State::class);
});

test('a Machine has a value', function (): void {
    $value = random_int(0, 1) ? 'red' : 1;

    $machine = Machine::define([
        'name'  => 'traffic_lights_machine',
        'value' => $value,
    ]);

    expect($machine->value)->toBe($value);
});

test('a Machine has a default value', function (): void {
    $machine = Machine::define([
        'name'  => 'traffic_lights_machine',
        'value' => null,
    ]);

    expect($machine->value)->toBe('traffic_lights_machine');
});

test('a Machine has a version', function (): void {
    $machine = Machine::define([
        'version' => 2,
    ]);

    expect($machine)->version->toBe(2);
});
