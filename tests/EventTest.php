<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Machine;

it('should return the set of events accepted by machine', function (): void {
    $machine = Machine::define([
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
    ]);

    expect($machine->events)->toBe([
        'TIMER',
        'POWER_OUTAGE',
        'FORBIDDEN_EVENT',
        'PED_COUNTDOWN',
    ]);
});
