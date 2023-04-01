<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\ContextDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

it('should run the guarded action when the guard condition is met', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 1,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'MUT' => [
                            'conditions' => 'isEvenGuard',
                            'actions'    => 'multiplyByTwoAction',
                        ],
                        'INC' => ['actions' => 'incrementAction'],
                        'DEC' => ['actions' => 'decrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'multiplyByTwoAction' => function (ContextDefinition $context): void {
                    $context->set('count', $context->get('count') * 2);
                },
                'incrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') + 1);
                },
                'decrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') - 1);
                },
            ],
            'guards' => [
                'isEvenGuard' => function (ContextDefinition $context, array $event): bool {
                    return $context->get('count') % 2 === 0;
                },
            ],
        ],
    );

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 1]);

    // Ensure that the machine's context has not been changed.
    expect($machine->context->get('count'))->toBe(1);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->contextData)->toBe(['count' => 2]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 4]);

    // Ensure that the machine's context has been changed.
    expect($machine->context->get('count'))->toBe(4);
});

it('should not run the guarded action when the guard condition is not met', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 1,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'MUT' => [
                            'conditions' => 'isEvenGuard',
                            'actions'    => 'multiplyByTwoAction',
                        ],
                        'INC' => ['actions' => 'incrementAction'],
                        'DEC' => ['actions' => 'decrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'multiplyByTwoAction' => function (ContextDefinition $context): void {
                    $context->set('count', $context->get('count') * 2);
                },
                'incrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') + 1);
                },
                'decrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') - 1);
                },
            ],
            'guards' => [
                'isEvenGuard' => function (ContextDefinition $context, array $event): bool {
                    return $context->get('count') % 2 === 0;
                },
            ],
        ],
    );

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 1]);

    // Ensure that the machine's context has not been changed.
    expect($machine->context->get('count'))->toBe(1);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->contextData)->toBe(['count' => 2]);

    $newState = $machine->transition(state: $newState, event: ['type' => 'DEC']);
    expect($newState->contextData)->toBe(['count' => 1]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    // Guard condition not met, action not executed
    expect($newState->contextData)->toBe(['count' => 1]);

    // Ensure that the machine's context has not been changed.
    expect($machine->context->get('count'))->toBe(1);
});

it('should transition through multiple if-else targets based on guard conditions', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'context' => [
                'value' => 1,
            ],
            'states' => [
                'green' => [
                    'on' => [
                        'TIMER' => [
                            [
                                'target'     => 'yellow',
                                'conditions' => 'isOneGuard',
                            ],
                            [
                                'target'     => 'red',
                                'conditions' => 'isTwoGuard',
                            ],
                            [
                                'target' => 'pedestrian',
                            ],
                        ],
                    ],
                ],
                'yellow'     => [],
                'red'        => [],
                'pedestrian' => [],
            ],
        ],
        behavior: [
            'guards' => [
                'isOneGuard' => function (ContextDefinition $context, array $event): bool {
                    return $context->get('value') === 1;
                },
                'isTwoGuard' => function (ContextDefinition $context, array $event): bool {
                    return $context->get('value') === 2;
                },
            ],
        ],
    );

    $newState = $machine->transition(state: null, event: ['type' => 'TIMER']);
    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['yellow']);

    $newState = $machine->transition(
        state: new State(
            activeStateDefinition: $machine->states['green'],
            contextData: ['value' => 2],
        ),
        event: ['type' => 'TIMER']
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['red']);

    $newState = $machine->transition(
        state: new State(
            activeStateDefinition: $machine->states['green'],
            contextData: ['value' => 3],
        ),
        event: ['type' => 'TIMER']
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['pedestrian']);
});
