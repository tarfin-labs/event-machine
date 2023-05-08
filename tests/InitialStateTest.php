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

    expect($machine->stateDefinitions['green']->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->stateDefinitions['yellow']->initial)->toBeNull();
    expect($machine->stateDefinitions['red']->initial)->toBeNull();

    expect($machine->stateDefinitions['green']->states['walk']->initial)->toBeNull();
    expect($machine->stateDefinitions['green']->states['wait']->initial)->toBeNull();
    expect($machine->stateDefinitions['green']->states['stop']->initial)->toBeNull();
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

    expect($machine->stateDefinitions['green']->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->stateDefinitions['yellow']->initial)->toBeNull();
    expect($machine->stateDefinitions['red']->initial)->toBeNull();

    expect($machine->stateDefinitions['green']->states['walk']->initial)->toBeNull();
    expect($machine->stateDefinitions['green']->states['wait']->initial)->toBeNull();
    expect($machine->stateDefinitions['green']->states['stop']->initial)->toBeNull();
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
        ->and($initialState->value)->toBe(['active'])
        ->and($initialState->context->get('count'))->toBe(1);
});
