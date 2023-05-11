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

    $foundStateDefinition = $machine->getNearestStateDefinitionByString(state: null);
    expect($foundStateDefinition)->toBe(null);
});

it('throws exception if multiple state definitions found', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'id'      => 'machine',
        'states'  => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    $machine->getNearestStateDefinitionByString(state: 'e');
})->expectException(AmbiguousStateDefinitionsException::class);

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

    expect($greenStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $delimiter.'green'));
    expect($yellowStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $delimiter.'yellow'));
    expect($redStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $delimiter.'red'));

    expect($greenStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $machineName.$delimiter.'green'));
    expect($yellowStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $machineName.$delimiter.'yellow'));
    expect($redStateDefinition)->toBe($machine->getNearestStateDefinitionByString(state: $machineName.$delimiter.'red'));
});
