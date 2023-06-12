<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('can transition through a sequence of states using events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'states'  => [
                'green' => [
                    'on' => [
                        'NEXT' => 'yellow',
                    ],
                ],
                'yellow' => [
                    'on' => [
                        'NEXT' => 'red',
                    ],
                ],
                'red' => [],
            ],
        ],
    );

    $greenState = $machine->getInitialState();
    expect($greenState)
        ->toBeInstanceOf(State::class)
        ->and($greenState->value)->toBe(['(machine).green']);

    $yellowState = $machine->transition(state: null, event: ['type' => 'NEXT']);
    expect($yellowState)
        ->toBeInstanceOf(State::class)
        ->and($yellowState->value)->toBe(['(machine).yellow']);

    $redState = $machine->transition(state: $yellowState, event: ['type' => 'NEXT']);
    expect($redState)
        ->toBeInstanceOf(State::class)
        ->and($redState->value)->toBe(['(machine).red']);
});

it('should apply the given state\'s context data to the machine\'s context when transitioning', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count'     => 0,
                'someValue' => 'abc',
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'INC' => ['actions' => 'incrementAction'],
                    ],
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
    $initialState->context->set('count', 5);

    $newState = $machine->transition(state: $initialState, event: [
        'type' => 'INC',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).active']);
    expect($newState->context->data)->toBe(['count' => 6, 'someValue' => 'abc']);
});
