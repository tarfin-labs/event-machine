<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

test('a Machine with a negative version', function (): void {
    $machine = Machine::define([
        'version' => -2,
    ]);

    expect($machine)->version->toBe(1);
});

test('a Machine with the 0 version', function (): void {
    $machine = Machine::define([
        'version' => 0,
    ]);

    expect($machine)->version->toBe(1);
});

test('a Machine without description', function (): void {
    $machineDefinition = [];

    $machine = Machine::define($machineDefinition);

    expect($machine)->description->toBeNull();
});
