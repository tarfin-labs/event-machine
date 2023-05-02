<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('always transitions', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateA',
        'states'  => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateB',
                ],
            ],
            'stateB' => [
                'on' => [
                    '@always' => 'stateC',
                ],
            ],
            'stateC' => [],
        ],
    ]);

    $newState = $machine->transition(
        state: null,
        event: ['type' => 'EVENT']
    );

    expect($newState->value)->toBe(['stateC']);
});
