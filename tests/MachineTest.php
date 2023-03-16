<?php

declare(strict_types=1);

// region machine

// region machine.states

use Tarfinlabs\EventMachine\Machine;

it('should properly register machine states', function (): void {
    $machine = Machine::define([
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
    ]);

    expect($machine->states)->toHaveKeys(['green', 'yellow', 'red']);
});

// endregion

// endregion
