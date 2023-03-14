<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

test('a state has path', function (): void {
    $machine = Machine::define([
        'name'          => 'traffic_lights_machine',
        'initial_state' => 'red',
        'states'        => [
            'red_level_1' => [
                'states' => [
                    'red_level_2' => [
                        'states' => [
                            'red_level_3' => [],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect(true)
        ->and($machine)->path->toBe('traffic_lights_machine')
        ->and($machine->states['red_level_1'])->path->toBe('traffic_lights_machine.red_level_1')
        ->and($machine->states['red_level_1']->states['red_level_2'])->path->toBe('traffic_lights_machine.red_level_1.red_level_2')
        ->and($machine->states['red_level_1']->states['red_level_2']->states['red_level_3'])->path->toBe('traffic_lights_machine.red_level_1.red_level_2.red_level_3');
});
