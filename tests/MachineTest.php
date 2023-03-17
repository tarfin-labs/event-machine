<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

it('should properly register machine states', function ($machineDefinition): void {
    $machine = Machine::define($machineDefinition);

    expect($machine->states)->toHaveKeys(['green', 'yellow', 'red']);
})->with('machine_definitions');

it('should return the set of events accepted by machine', function ($machineDefinition): void {
    $machine = Machine::define($machineDefinition);

    expect($machine->events)->toBe([
        'TIMER',
        'POWER_OUTAGE',
        'FORBIDDEN_EVENT',
        'PED_COUNTDOWN',
    ]);
})->with('machine_definitions');

dataset('machine_definitions', [
    'traffic_lights' => [
        [
            'initial' => 'green',
            'states'  => [
                'green' => [
                    'on' => [
                        'TIMER'           => 'yellow',
                        'POWER_OUTAGE'    => 'red',
                        'FORBIDDEN_EVENT' => null,
                    ],
                ],
                'yellow' => [
                    'on' => [
                        'TIMER'        => 'red',
                        'POWER_OUTAGE' => 'red',
                    ],
                ],
                'red' => [
                    'on' => [
                        'TIMER'        => 'green',
                        'POWER_OUTAGE' => 'red',
                    ],
                    'initial' => 'walk',
                    'states'  => [
                        'walk' => [
                            'on' => [
                                'PED_COUNTDOWN' => 'wait',
                            ],
                        ],
                        'wait' => [
                            'on' => [
                                'PED_COUNTDOWN' => 'stop',
                            ],
                        ],
                        'stop' => [],
                    ],
                ],
            ],
        ],
    ],
]);
