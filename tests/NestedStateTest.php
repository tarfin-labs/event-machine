<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\Machine;

test('a machine can have nested states', function (): void {
    $machine = Machine::define([
        'name'          => 'traffic_lights_machine',
        'initial_state' => 'red',
        'states'        => [
            'red' => [
                'states' => [
                    'red_1' => [],
                    'red_2' => [],
                    'red_3' => [],
                ],
            ],
            'yellow' => [
                'states' => [
                    'yellow_1',
                    'yellow_2',
                    'yellow_3',
                ],
            ],
            'green' => [
                'states' => [
                    'green_1' => [],
                    'green_2',
                    'green_3' => [],
                ],
            ],
        ],
    ]);

    foreach ($machine->states as $stateName => $stateInstance) {
        expect($stateInstance)
            ->toBeInstanceOf(State::class)
            ->machine->toBe($machine)
            ->parent->toBe($machine)
            ->states->toBeArray()
            ->each->toBeInstanceOf(State::class)
            ->machine->toBe($machine);
    }
});

test('a machine can have deeply nested states', function (): void {
    $levelOfNesting = random_int(10, 20);
    $states         = null;

    foreach (range(1, $levelOfNesting) as $level) {
        $states = [
            'red_level_'.$levelOfNesting - $level + 1 => [
                'states' => $states,
            ],
        ];
    }

    $machine = Machine::define([
        'name'   => 'traffic_lights_machine',
        'states' => $states,
    ]);

    $deepMachine = $machine;
    foreach (range(1, $levelOfNesting) as $_) {
        expect($deepMachine)
            ->machine->toBe($machine)
            ->states->toBeArray()
            ->each->toBeInstanceOf(State::class);

        $deepMachine = $deepMachine->states[array_key_first($deepMachine->states)];
    }
});
