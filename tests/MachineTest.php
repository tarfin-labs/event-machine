<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

it('should properly register machine states', function ($machineDefinition): void {
    $machine = Machine::define($machineDefinition);

    expect($machine->states)->toHaveKeys(['green', 'yellow', 'red']);
})->with('machine_definitions');

it('should return the set of events accepted by machine', function ($machineDefinition): void {
    $machine = Machine::define($machineDefinition);

    expect($machine->events)->toBe(['TIMER']);
})->with('machine_definitions');

dataset('machine_definitions', [
    'traffic_lights' => [[
        'name'    => 'traffic_lights',
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'TIMER' => 'yellow',
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER' => 'red',
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER' => 'green',
                ],
            ],
        ],
    ]],
]);
