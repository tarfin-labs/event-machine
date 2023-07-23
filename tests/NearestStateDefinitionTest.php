<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('search nothing', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'id'      => 'machine',
        'states'  => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->getNearestStateDefinitionByString(stateDefinitionId: ''))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(stateDefinitionId: 'g'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(stateDefinitionId: 'gr'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(stateDefinitionId: 'gre'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(stateDefinitionId: 'gree'))->toBe(null);
});

test('search root states by string', function (): void {
    $machineName = 'machine';
    $delimiter   = '.';

    $machine = MachineDefinition::define(config: [
        'initial'   => 'green',
        'id'        => $machineName,
        'delimiter' => $delimiter,
        'states'    => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    $greenStateDefinition  = $machine->stateDefinitions['green'];
    $yellowStateDefinition = $machine->stateDefinitions['yellow'];
    $redStateDefinition    = $machine->stateDefinitions['red'];

    expect($greenStateDefinition)->toBe($machine->getNearestStateDefinitionByString(stateDefinitionId: 'green'));
    expect($yellowStateDefinition)->toBe($machine->getNearestStateDefinitionByString(stateDefinitionId: 'yellow'));
    expect($redStateDefinition)->toBe($machine->getNearestStateDefinitionByString(stateDefinitionId: 'red'));
});

test('search unique child states by string', function (): void {
    $machineName = 'machine';
    $delimiter   = '.';

    $machine = MachineDefinition::define(config: [
        'initial'   => 'green',
        'id'        => $machineName,
        'delimiter' => $delimiter,
        'states'    => [
            'green' => [
                'states' => [
                    'uniqueSubState' => [],
                    'green'          => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    $uniqueSubStateDefinition = $machine->stateDefinitions['green']->stateDefinitions['uniqueSubState'];
    $foundStateDefinition     = $machine->getNearestStateDefinitionByString(stateDefinitionId: 'green'.$delimiter.'uniqueSubState');

    expect($uniqueSubStateDefinition)->toBe($foundStateDefinition);

    $subGreenStateDefinition = $machine->stateDefinitions['green']->stateDefinitions['green'];
    $foundStateDefinition    = $machine->getNearestStateDefinitionByString(stateDefinitionId: 'green'.$delimiter.'green');

    expect($subGreenStateDefinition)->toBe($foundStateDefinition);
});
