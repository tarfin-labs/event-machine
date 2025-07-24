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
        ->id->toBe(MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green.walk');

    expect($machine->stateDefinitions['green']->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->id->toBe(MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green.walk');

    expect($machine->stateDefinitions['yellow']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['red']->initialStateDefinition)->toBeNull();

    expect($machine->stateDefinitions['green']->stateDefinitions['walk']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['wait']->initialStateDefinition)->toBeNull();
    expect($machine->stateDefinitions['green']->stateDefinitions['stop']->initialStateDefinition)->toBeNull();
});

test('first state auto-set as initial for machine and sub-states when not specified', function (): void {
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
        ->id->toBe(MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green.walk');

    expect($machine->stateDefinitions['green']->initialStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->id->toBe(MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green.walk');

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

    /** @var State $initialState */
    $initialState = $machine->getInitialState();
    expect($initialState)
        ->toBeInstanceOf(State::class)
        ->and($initialState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
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
        ->id->toBe(MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green.walk');
});
