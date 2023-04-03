<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('should correctly assign machine definition id', function (): void {
    $idMachine = MachineDefinition::define(config: [
        'id'     => 'some-id',
        'states' => [
            'idle' => [],
        ],
    ]);

    expect($idMachine->id)->toBe('some-id');
});

it('should correctly assign state definition id', function (): void {
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

it('should use the key as the id if no id is provided', function (): void {
    $noStateDefinitionIdMachine = MachineDefinition::define(config: [
        'id'     => 'some-id',
        'states' => [
            'idle' => [],
        ],
    ]);

    expect($noStateDefinitionIdMachine->states['idle']->id)->toBe('some-id.idle');
});
