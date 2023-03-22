<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('it should correctly assign machine definition id', function (): void {
    $idMachine = MachineDefinition::define(config: [
        'id'     => 'some-id',
        'states' => [
            'idle' => [],
        ],
    ]);

    expect($idMachine->id)->toBe('some-id');
});

test('it should correctly assign state definition id', function (): void {
    $idMachine = MachineDefinition::define(config: [
        'id'     => 'some-id',
        'states' => [
            'idle' => [
                'id' => 'idle-id',
            ],
        ],
    ]);

    expect($idMachine->states['idle']->id)->toBe('idle-id');
});
