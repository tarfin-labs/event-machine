<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a machine can have states', function ($definition): void {
    $machine = Machine::define($definition);

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
})->with('states');

dataset('states', [
    'with state implementation' => [
        [
            'name'          => 'traffic_lights_machine',
            'initial_state' => 'red',
            'states'        => [
                'red'    => [],
                'yellow' => [],
                'green'  => [],
            ],
        ],
    ],
    'without state implementation' => [
        [
            'name'          => 'traffic_lights_machine',
            'initial_state' => 'red',
            'states'        => [
                'red',
                'yellow',
                'green',
            ],
        ],
    ],
    'with or without state implementation' => [
        [
            'name'          => 'traffic_lights_machine',
            'initial_state' => 'red',
            'states'        => [
                'red',
                'yellow' => [],
                'green',
            ],
        ],
    ],
]);
