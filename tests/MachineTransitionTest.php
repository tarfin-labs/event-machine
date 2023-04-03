<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\ContextDefinition;
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

    $greenState = $machine->initialState;
    expect($greenState)
        ->toBeInstanceOf(State::class)
        ->and($greenState->value)->toBe(['green']);

    $yellowState = $machine->transition(state: null, event: ['type' => 'NEXT']);
    expect($yellowState)
        ->toBeInstanceOf(State::class)
        ->and($yellowState->value)->toBe(['yellow']);

    $redState = $machine->transition(state: $yellowState, event: ['type' => 'NEXT']);
    expect($redState)
        ->toBeInstanceOf(State::class)
        ->and($redState->value)->toBe(['red']);
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
                'incrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
        ],
    );

    $initialState              = $machine->initialState;
    $initialState->contextData = ['count' => 5];

    $newState = $machine->transition(state: $initialState, event: [
        'type' => 'INC',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 6, 'someValue' => 'abc']);

    // Ensure that the machine's context has been changed.
    expect($machine->context->get('count'))->toBe(6);
    expect($machine->context->get('someValue'))->toBe('abc');
});
