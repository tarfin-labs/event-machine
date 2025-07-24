<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// === Root State Definition Tests ===

test('root state definition has a reference to the machine definition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine->root->machine)->toBe($nullMachine);
});

test('root state definition has a local id', function (): void {
    $machineName = 'custom_machine_name';
    $machine     = MachineDefinition::define(config: ['id' => $machineName]);

    expect($machine->root)->toHaveProperty('key');
    expect($machine->root->key)->toBe($machineName);
});

test('root state definition has a default local id', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('key');
    expect($machine->root->key)->toBe(MachineDefinition::DEFAULT_ID);
});

// === Nearest State Definition Tests ===

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

// === Transition to Child State Tests ===

it('can transition to a child state', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateB.subStateOfB',
                ],
            ],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateB.subStateOfB']);
});

it('can transition to a child state, targets as arrays', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'stateA' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'stateB.subStateOfB',
                    ],
                ],
            ],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateB.subStateOfB']);
});

it('can transition from a child state', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateB.subStateOfB',
        'states'  => [
            'stateA' => [],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [
                        'on' => [
                            'EVENT' => 'stateA',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateA']);
});

it('can transition from a child state, targets as arrays', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateB.subStateOfB',
        'states'  => [
            'stateA' => [],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [
                        'on' => [
                            'EVENT' => [
                                'target' => 'stateA',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateA']);
});
