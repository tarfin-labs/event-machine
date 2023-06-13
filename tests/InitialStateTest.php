<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('initial states are correctly set for both top-level machine definition and sub-states when explicitly specified', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green' => [
                'initial' => 'walk',
                'states'  => [
                    'walk' => [],
                    'wait' => [],
                    'stop' => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->stateDefinitions['green']->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->stateDefinitions['yellow']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['red']->initialStateDefinition)->toBeNull();

    expect($machine->stateDefinitions['green']->stateDefinitions['walk']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['wait']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['stop']->initialStateDefinition)->toBeNull();
});

test('the first state definition is set as the initial state for both top-level machine definition and sub-states when not explicitly specified', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'states' => [
                    'walk' => [],
                    'wait' => [],
                    'stop' => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->stateDefinitions['green']->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->stateDefinitions['yellow']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['red']->initialStateDefinition)->toBeNull();

    expect($machine->stateDefinitions['green']->stateDefinitions['walk']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['wait']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['stop']->initialStateDefinition)->toBeNull();
});

it('should run entry actions for building initial state', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'active' => [
                    'entry' => 'incrementAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
        ],
    );

    $initialState = $machine->getInitialState();
    expect($initialState)
        ->toBeInstanceOf(State::class)
        ->and($initialState->value)->toBe(['(machine).active'])
        ->and($initialState->context->get('count'))->toBe(1);
});

test('initial state can be a child state', function (): void {
    $machine = MachineDefinition::define([
        'initial' => 'green.walk',
        'states'  => [
            'green' => [
                'states' => [
                    'walk' => [],
                    'wait' => [],
                    'stop' => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->id->toBe('(machine).green.walk');
});
