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

    expect($machine->getNearestStateDefinitionByString(state: ''))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(state: 'g'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(state: 'gr'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(state: 'gre'))->toBe(null);
    expect($machine->getNearestStateDefinitionByString(state: 'gree'))->toBe(null);
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

    expect($greenStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: 'green'));
    expect($yellowStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: 'yellow'));
    expect($redStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: 'red'));
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
    $foundStateDefinition     = $machine->getNearestStateDefinitionByString(state: 'green'.$delimiter.'uniqueSubState');

    expect($uniqueSubStateDefinition)->toBe($foundStateDefinition);

    $subGreenStateDefinition = $machine->stateDefinitions['green']->stateDefinitions['green'];
    $foundStateDefinition    = $machine->getNearestStateDefinitionByString(state: 'green'.$delimiter.'green');

    expect($subGreenStateDefinition)->toBe($foundStateDefinition);
});
