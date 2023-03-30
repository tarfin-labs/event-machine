<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\MachineDefinition;

it('can transition to a next state', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'states'  => [
                'green' => [
                    'on' => [
                        'NEXT' => 'yellow',
                    ],
                ],
                'yellow' => [
                    'on' => [
                        'NEXT' => 'red',
                    ],
                ],
                'red' => [],
            ],
        ],
    );

    expect($machine->initial->key)->toBe('green');

    $yellowState = $machine->transition(state: null, event: ['type' => 'NEXT']);
    expect($yellowState)
        ->toBeInstanceOf(State::class)
        ->and($yellowState->value)->toBe(['yellow']);

    $redState = $machine->transition(state: $yellowState, event: ['type' => 'NEXT']);
    expect($redState)
        ->toBeInstanceOf(State::class)
        ->and($redState->value)->toBe(['red']);
});
